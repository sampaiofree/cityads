<?php

namespace App\Jobs;

use App\Models\City;
use App\Models\MetaAdBatch;
use App\Models\MetaAdBatchItem;
use App\Services\Meta\CityImageGenerator;
use App\Services\Meta\MetaAdsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessMetaAdBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly int $batchId
    ) {
    }

    public function handle(MetaAdsService $adsService, CityImageGenerator $imageGenerator): void
    {
        $batch = MetaAdBatch::with('user')->find($this->batchId);
        if (!$batch) {
            return;
        }

        if ($batch->cancel_requested_at || $batch->status === 'cancel_requested' || $batch->status === 'cancelled') {
            $batch->update([
                'status' => 'cancelled',
                'cancelled_at' => $batch->cancelled_at ?? now(),
            ]);
            return;
        }

        $batch->update([
            'status' => 'processing',
            'error_message' => null,
            'processed_items' => 0,
            'success_count' => 0,
            'error_count' => 0,
        ]);

        $batchContext = [
            'batch_id' => $batch->id,
            'user_id' => $batch->user_id,
            'ad_account_id' => $batch->ad_account_id,
            'destination_type' => $batch->destination_type ?: Arr::get($batch->settings, 'destination_type') ?: 'WEBSITE',
        ];

        $connection = $batch->user->metaConnection;
        if (!$connection || !$connection->access_token) {
            $this->markBatchFailed($batch, 'Conexao Meta ausente ou token invalido.', $batchContext);
            return;
        }

        $cities = $this->resolveCities($batch);
        if ($cities->isEmpty()) {
            $this->markBatchFailed($batch, 'Nenhuma cidade encontrada para processar.', $batchContext);
            return;
        }

        $batch->update(['total_items' => $cities->count()]);

        $accessToken = $connection->access_token;
        $adAccountId = $batch->ad_account_id;
        $pageId = $batch->page_id;
        $instagramActorId = $batch->instagram_actor_id;
        $pixelId = $batch->pixel_id;
        $destinationType = $batch->destination_type ?: Arr::get($batch->settings, 'destination_type');
        $destinationType = $destinationType ?: 'WEBSITE';
        $whatsappNumber = Arr::get($batch->settings, 'whatsapp_number');
        if (is_string($whatsappNumber)) {
            $whatsappNumber = preg_replace('/\D/', '', $whatsappNumber);
        }
        $whatsappNumber = $whatsappNumber ?: null;
        $status = $batch->auto_activate ? 'ACTIVE' : 'PAUSED';

        $batchContext = array_merge($batchContext, [
            'ad_account_id' => $adAccountId,
            'destination_type' => $destinationType,
        ]);

        $creativeSourceMode = $this->resolveCreativeSourceMode($batch);
        $sourceMediaPath = null;
        $rotationImagePaths = [];
        $rotationImageCount = 0;

        try {
            $creativeMediaType = $this->resolveCreativeMediaType($batch);

            if ($creativeSourceMode === 'image_rotation') {
                if ($creativeMediaType !== 'image') {
                    $this->markBatchFailed($batch, 'Rodizio aceita apenas imagens.', $batchContext);
                    return;
                }

                $rotationImagePaths = $this->resolveRotationImagePaths($batch);
                foreach ($rotationImagePaths as $rotationImagePath) {
                    $this->resolveBatchSourceMediaPathFromRelative($rotationImagePath);
                }
                $rotationImageCount = count($rotationImagePaths);
            } else {
                $sourceMediaPath = $this->resolveBatchSourceMediaPath($batch);
            }
        } catch (Throwable $exception) {
            $this->markBatchFailed($batch, $exception->getMessage(), $batchContext);
            return;
        }

        $batchContext = array_merge($batchContext, [
            'creative_source_mode' => $creativeSourceMode,
            'creative_media_type' => $creativeMediaType,
            'rotation_image_count' => $rotationImageCount,
        ]);

        if (!$pixelId) {
            Log::channel('meta')->error('MetaAds batch missing pixel', $batchContext);
            $this->markBatchFailed($batch, 'Pixel nao informado para o lote.', $batchContext);
            return;
        }

        if ($destinationType === 'WHATSAPP' && !$pageId) {
            Log::channel('meta')->error('MetaAds batch missing page_id for WhatsApp', $batchContext);
            $this->markBatchFailed($batch, 'Pagina do Facebook nao informada para campanha de WhatsApp.', $batchContext);
            return;
        }

        if ($destinationType === 'WHATSAPP' && !$whatsappNumber) {
            Log::channel('meta')->warning('MetaAds batch missing whatsapp_number for WhatsApp, sending without number', $batchContext);
        }

        Log::channel('meta')->info('MetaAds batch start', $batchContext);
        Log::channel('meta')->info('MetaAds instagram actor selected', array_merge($batchContext, [
            'instagram_actor_id' => $instagramActorId,
        ]));

        if ($instagramActorId) {
            try {
                $adsService->fetchInstagramActorDetails($accessToken, $instagramActorId, $batchContext);
            } catch (Throwable $exception) {
                Log::channel('meta')->warning('MetaAds instagram actor details failed', array_merge($batchContext, [
                    'instagram_actor_id' => $instagramActorId,
                    'exception' => $exception->getMessage(),
                ]));
            }
        }

        $campaignId = $batch->meta_campaign_id;
        if (!$campaignId) {
            $campaignName = $this->buildCampaignName($batch->objective);
            $campaignId = $adsService->createCampaign(
                $accessToken,
                $adAccountId,
                $this->resolveCampaignObjective($batch->objective),
                $campaignName,
                $status,
                $batchContext,
                $this->resolveSpecialAdCategories($batch)
            );
            if (!$campaignId) {
                Log::channel('meta')->error('MetaAds batch campaign failed', $batchContext);
                $this->markBatchFailed($batch, 'Nao foi possivel criar a campanha no Meta Ads.', $batchContext);
                return;
            }

            $batch->update(['meta_campaign_id' => $campaignId]);
        }

        foreach ($cities->values() as $cityIndex => $city) {
            $batch->refresh();
            if ($batch->cancel_requested_at || $batch->status === 'cancel_requested') {
                $batch->update([
                    'status' => 'cancelled',
                    'cancelled_at' => $batch->cancelled_at ?? now(),
                ]);
                Log::channel('meta')->warning('MetaAds batch cancelled', $batchContext);
                return;
            }

            $selectedSourceIndex = 0;
            $selectedSourceRelativePath = $batch->image_path;

            if ($creativeSourceMode === 'image_rotation' && $rotationImageCount > 0) {
                $selectedSourceIndex = $cityIndex % $rotationImageCount;
                $selectedSourceRelativePath = $rotationImagePaths[$selectedSourceIndex] ?? $selectedSourceRelativePath;
            }

            $item = $batch->items()->create([
                'city_id' => $city->id,
                'city_name' => $city->name,
                'state_name' => $city->state,
                'creative_source_path' => $selectedSourceRelativePath,
                'creative_source_index' => $selectedSourceIndex,
                'status' => 'processing',
            ]);

            $itemContext = array_merge($batchContext, [
                'item_id' => $item->id,
                'city_id' => $city->id,
                'city' => $city->name,
                'state' => $city->state,
                'creative_source_index' => $selectedSourceIndex,
                'creative_source_path' => $selectedSourceRelativePath,
            ]);

            $generatedPath = null;
            $imageHash = null;
            $videoId = null;

            try {
                $cityKey = $adsService->findCityKey($accessToken, $city->name, $city->state, $itemContext);
                if (!$cityKey) {
                    $this->markItemFailed($batch, $item, 'Cidade nao encontrada no Meta.');
                    continue;
                }

                if ($creativeMediaType === 'video') {
                    $videoId = $adsService->uploadVideo($accessToken, $adAccountId, $sourceMediaPath, $itemContext);

                    if (!$videoId) {
                        $this->markItemFailed($batch, $item, 'Falha no upload do video.');
                        continue;
                    }
                } else {
                    $overlayTextTemplate = Arr::get($batch->settings, 'overlay_text', '');
                    if (!is_string($overlayTextTemplate)) {
                        $overlayTextTemplate = '';
                    }
                    $overlayText = str_replace('{cidade}', $city->name, $overlayTextTemplate);

                    $overlay = [
                        'text' => $overlayText,
                        'text_color' => Arr::get($batch->settings, 'overlay_text_color', '#ffffff'),
                        'bg_color' => Arr::get($batch->settings, 'overlay_bg_color', '#000000'),
                        'position_x' => (float) Arr::get($batch->settings, 'overlay_position_x', 50),
                        'position_y' => (float) Arr::get($batch->settings, 'overlay_position_y', 12),
                    ];

                    $generatedPath = $this->generateCreativeImage($imageGenerator, (string) $selectedSourceRelativePath, $overlay);
                    $imageHash = $adsService->uploadImage($accessToken, $adAccountId, $generatedPath, $itemContext);

                    if (!$imageHash) {
                        $this->markItemFailed($batch, $item, 'Falha no upload da imagem.');
                        $this->deleteGeneratedImage($generatedPath);
                        continue;
                    }
                }

                $conversionEvent = $this->resolveConversionEvent($batch->objective);
                $optimizationGoal = $this->resolveOptimizationGoal($batch->objective, $destinationType);
                $startTimeUtc = $this->formatStartTimeUtc($batch->start_at);
                $adSetName = sprintf('%s - %s', $city->state, $city->name);

                $usePixel = $destinationType !== 'WHATSAPP' && $conversionEvent !== null;
                $pixelForAdset = $usePixel ? $pixelId : null;
                $conversionEventForAdset = $usePixel ? $conversionEvent : null;

                $adSetId = $adsService->createAdSet(
                    $accessToken,
                    $adAccountId,
                    $campaignId,
                    $adSetName,
                    $cityKey,
                    $batch->daily_budget_cents,
                    $startTimeUtc,
                    $pixelForAdset,
                    $conversionEventForAdset,
                    $status,
                    $itemContext,
                    [
                        'destination_type' => $destinationType,
                        'page_id' => $pageId,
                        'optimization_goal' => $optimizationGoal,
                    ]
                );

                if (!$adSetId) {
                    $this->markItemFailed($batch, $item, 'Erro ao criar conjunto de anuncios.');
                    $this->deleteGeneratedImage($generatedPath);
                    continue;
                }

                $title = str_replace('{cidade}', $city->name, $batch->title_template);
                $body = str_replace('{cidade}', $city->name, $batch->body_template);
                $url = str_replace('{cidade}', $city->name, $batch->url_template);

                if ($creativeMediaType === 'video') {
                    $creativeId = $this->createVideoCreativeWithFallback(
                        $adsService,
                        $accessToken,
                        $adAccountId,
                        $adSetName,
                        $title,
                        $body,
                        $url,
                        (string) $videoId,
                        $pageId,
                        $instagramActorId,
                        'OPT_IN',
                        $itemContext,
                        $item,
                        [
                            'destination_type' => $destinationType,
                            'whatsapp_number' => $whatsappNumber,
                        ]
                    );
                } else {
                    $creativeId = $this->createCreativeWithFallback(
                        $adsService,
                        $accessToken,
                        $adAccountId,
                        $adSetName,
                        $title,
                        $body,
                        $url,
                        (string) $imageHash,
                        $pageId,
                        $instagramActorId,
                        'OPT_IN',
                        $itemContext,
                        $item,
                        [
                            'destination_type' => $destinationType,
                            'whatsapp_number' => $whatsappNumber,
                        ]
                    );
                }

                if (!$creativeId) {
                    if ($creativeMediaType === 'video') {
                        $creativeId = $this->createVideoCreativeWithFallback(
                            $adsService,
                            $accessToken,
                            $adAccountId,
                            $adSetName,
                            $title,
                            $body,
                            $url,
                            (string) $videoId,
                            $pageId,
                            $instagramActorId,
                            'OPT_OUT',
                            $itemContext,
                            $item,
                            [
                                'destination_type' => $destinationType,
                                'whatsapp_number' => $whatsappNumber,
                            ]
                        );
                    } else {
                        $creativeId = $this->createCreativeWithFallback(
                            $adsService,
                            $accessToken,
                            $adAccountId,
                            $adSetName,
                            $title,
                            $body,
                            $url,
                            (string) $imageHash,
                            $pageId,
                            $instagramActorId,
                            'OPT_OUT',
                            $itemContext,
                            $item,
                            [
                                'destination_type' => $destinationType,
                                'whatsapp_number' => $whatsappNumber,
                            ]
                        );
                    }
                }

                if (!$creativeId) {
                    $this->markItemFailed($batch, $item, 'Erro ao criar criativo.');
                    $this->deleteGeneratedImage($generatedPath);
                    continue;
                }

                $adId = $adsService->createAd(
                    $accessToken,
                    $adAccountId,
                    $adSetId,
                    $creativeId,
                    $adSetName,
                    $status,
                    $itemContext
                );

                if (!$adId) {
                    $this->markItemFailed($batch, $item, 'Erro ao criar anuncio.');
                    $this->deleteGeneratedImage($generatedPath);
                    continue;
                }

                $item->update([
                    'meta_city_key' => $cityKey,
                    'ad_set_id' => $adSetId,
                    'ad_creative_id' => $creativeId,
                    'ad_id' => $adId,
                    'image_hash' => $imageHash ?: $videoId,
                    'creative_source_path' => $selectedSourceRelativePath,
                    'creative_source_index' => $selectedSourceIndex,
                    'status' => 'success',
                ]);

                $batch->increment('success_count');
            } catch (Throwable $exception) {
                Log::channel('meta')->error('MetaAds batch item exception', array_merge($itemContext, [
                    'exception' => $exception->getMessage(),
                ]));
                $this->markItemFailed($batch, $item, $exception->getMessage());
            } finally {
                $this->deleteGeneratedImage($generatedPath ?? null);
                $batch->increment('processed_items');
            }

            usleep(500000);
        }

        $batch->refresh();
        if ($batch->status !== 'cancelled' && $batch->status !== 'cancel_requested') {
            $batch->update([
                'status' => $batch->error_count > 0 ? 'completed_with_errors' : 'completed',
            ]);
        }

        Log::channel('meta')->info('MetaAds batch complete', array_merge($batchContext, [
            'status' => $batch->status,
            'success_count' => $batch->success_count,
            'error_count' => $batch->error_count,
        ]));
    }

    public function failed(Throwable $exception): void
    {
        $batch = MetaAdBatch::find($this->batchId);
        if (!$batch) {
            return;
        }

        if ($batch->status === 'failed' && filled($batch->error_message)) {
            return;
        }

        $this->markBatchFailed($batch, 'Erro inesperado no processamento: ' . $exception->getMessage(), [
            'batch_id' => $this->batchId,
        ]);
    }

    private function resolveCities(MetaAdBatch $batch)
    {
        $state = Arr::get($batch->settings, 'state');
        $cityIds = Arr::get($batch->settings, 'city_ids', []);

        if ($state) {
            $stateMatch = $this->resolveStateMatch($state);
            return City::query()
                ->where('state', $stateMatch ?: $state)
                ->orderBy('name')
                ->get();
        }

        return City::query()
            ->whereIn('id', $cityIds)
            ->orderBy('name')
            ->get();
    }

    private function resolveStateMatch(string $state): ?string
    {
        $normalized = Str::ascii(Str::lower(trim($state)));
        if ($normalized === '') {
            return null;
        }

        $states = City::query()->select('state')->distinct()->pluck('state');
        foreach ($states as $candidate) {
            if (Str::ascii(Str::lower($candidate)) === $normalized) {
                return $candidate;
            }
        }

        return null;
    }

    private function buildCampaignName(string $objective): string
    {
        $label = match ($objective) {
            'OUTCOME_SALES' => 'Compra',
            'OUTCOME_LEADS' => 'Cadastro',
            'OUTCOME_LEADS_CONTENT_VIEW' => 'ContentView',
            'OUTCOME_AWARENESS' => 'Reconhecimento',
            'OUTCOME_TRAFFIC' => 'Trafego',
            'OUTCOME_ENGAGEMENT' => 'Engajamento',
            default => 'Campanha',
        };

        return sprintf('Afiliados %s - %s', $label, now()->format('Y-m-d H:i:s'));
    }

    private function resolveCreativeSourceMode(MetaAdBatch $batch): string
    {
        $configured = Arr::get($batch->settings, 'creative_source_mode');

        return is_string($configured) && trim($configured) === 'image_rotation'
            ? 'image_rotation'
            : 'single_media';
    }

    private function resolveCreativeMediaType(MetaAdBatch $batch): string
    {
        $configured = Arr::get($batch->settings, 'creative_media_type');
        if (is_string($configured) && Str::lower(trim($configured)) === 'video') {
            return 'video';
        }

        $extension = Str::lower(pathinfo((string) $batch->image_path, PATHINFO_EXTENSION));
        if (in_array($extension, ['mp4', 'mov', 'avi', 'm4v', 'webm'], true)) {
            return 'video';
        }

        return 'image';
    }

    private function resolveRotationImagePaths(MetaAdBatch $batch): array
    {
        $paths = Arr::get($batch->settings, 'creative_image_paths', []);

        if (!is_array($paths)) {
            throw new \RuntimeException('Lista de imagens do rodizio invalida.');
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $path = trim($path);

            if (!$this->isSupportedRotationImagePath($path)) {
                throw new \RuntimeException('Rodizio aceita apenas imagens JPG, PNG ou WEBP.');
            }

            $normalized[] = $path;
        }

        if (count($normalized) < 1 || count($normalized) > 30) {
            throw new \RuntimeException('Rodizio requer de 1 a 30 imagens.');
        }

        return array_values($normalized);
    }

    private function isSupportedRotationImagePath(string $path): bool
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    private function resolveBatchSourceMediaPath(MetaAdBatch $batch): string
    {
        if (!filled($batch->image_path)) {
            throw new \RuntimeException('Midia do anuncio nao informada.');
        }

        return $this->resolveBatchSourceMediaPathFromRelative((string) $batch->image_path);
    }

    private function resolveBatchSourceMediaPathFromRelative(string $relativePath): string
    {
        if (trim($relativePath) === '') {
            throw new \RuntimeException('Midia do anuncio nao informada.');
        }

        $path = Storage::disk('public')->path($relativePath);

        if (!is_file($path)) {
            throw new \RuntimeException('Arquivo de midia do anuncio nao encontrado.');
        }

        return $path;
    }

    private function generateCreativeImage(CityImageGenerator $generator, string $sourceRelativePath, array $overlay): string
    {
        $sourcePath = $this->resolveBatchSourceMediaPathFromRelative($sourceRelativePath);

        return Storage::disk('local')->path($generator->generate($sourcePath, $overlay));
    }

    private function deleteGeneratedImage(?string $path): void
    {
        if (!$path) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function formatStartTimeUtc(?Carbon $startAt): string
    {
        $startAt = $startAt ?: now()->addMinutes(10);

        return $startAt->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
    }

    private function markItemFailed(MetaAdBatch $batch, MetaAdBatchItem $item, string $message): void
    {
        $item->update([
            'status' => 'error',
            'error_message' => $message,
        ]);

        $batch->increment('error_count');
    }

    private function markBatchFailed(MetaAdBatch $batch, string $message, array $context = []): void
    {
        $batch->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);

        Log::channel('meta')->error('MetaAds batch failed', array_merge($context, [
            'error_message' => $message,
        ]));
    }

    private function createCreativeWithFallback(
        MetaAdsService $adsService,
        string $accessToken,
        string $adAccountId,
        string $name,
        string $title,
        string $body,
        string $url,
        string $imageHash,
        string $pageId,
        ?string $instagramActorId,
        string $enrollStatus,
        array $context,
        MetaAdBatchItem $item,
        array $creativeOptions = []
    ): ?string {
        try {
            return $adsService->createCreative(
                $accessToken,
                $adAccountId,
                $name,
                $title,
                $body,
                $url,
                $imageHash,
                $pageId,
                $instagramActorId,
                $enrollStatus,
                $context,
                $creativeOptions
            );
        } catch (Throwable $exception) {
            if ($instagramActorId && Str::contains($exception->getMessage(), 'instagram_actor_id')) {
                Log::channel('meta')->warning('INSTAGRAM FALLBACK TRIGGERED: Tentando criar criativo sem Instagram.', array_merge($context, [
                    'enroll_status' => $enrollStatus,
                    'original_error' => $exception->getMessage(),
                ]));

                $item->update([
                    'error_message' => 'Falha no Instagram: ' . $exception->getMessage(),
                ]);

                return $adsService->createCreative(
                    $accessToken,
                    $adAccountId,
                    $name,
                    $title,
                    $body,
                    $url,
                    $imageHash,
                    $pageId,
                    null,
                    $enrollStatus,
                    array_merge($context, ['instagram_fallback' => true]),
                    $creativeOptions
                );
            }

            throw $exception;
        }
    }

    private function createVideoCreativeWithFallback(
        MetaAdsService $adsService,
        string $accessToken,
        string $adAccountId,
        string $name,
        string $title,
        string $body,
        string $url,
        string $videoId,
        string $pageId,
        ?string $instagramActorId,
        string $enrollStatus,
        array $context,
        MetaAdBatchItem $item,
        array $creativeOptions = []
    ): ?string {
        try {
            return $adsService->createVideoCreative(
                $accessToken,
                $adAccountId,
                $name,
                $title,
                $body,
                $url,
                $videoId,
                $pageId,
                $instagramActorId,
                $enrollStatus,
                $context,
                $creativeOptions
            );
        } catch (Throwable $exception) {
            if ($instagramActorId && Str::contains($exception->getMessage(), 'instagram_actor_id')) {
                Log::channel('meta')->warning('INSTAGRAM FALLBACK TRIGGERED: Tentando criar criativo de video sem Instagram.', array_merge($context, [
                    'enroll_status' => $enrollStatus,
                    'original_error' => $exception->getMessage(),
                ]));

                $item->update([
                    'error_message' => 'Falha no Instagram: ' . $exception->getMessage(),
                ]);

                return $adsService->createVideoCreative(
                    $accessToken,
                    $adAccountId,
                    $name,
                    $title,
                    $body,
                    $url,
                    $videoId,
                    $pageId,
                    null,
                    $enrollStatus,
                    array_merge($context, ['instagram_fallback' => true]),
                    $creativeOptions
                );
            }

            throw $exception;
        }
    }

    private function resolveConversionEvent(string $objective): ?string
    {
        return match ($objective) {
            'OUTCOME_SALES' => 'PURCHASE',
            'OUTCOME_LEADS' => 'LEAD',
            'OUTCOME_LEADS_CONTENT_VIEW' => 'CONTENT_VIEW',
            default => null,
        };
    }

    private function resolveCampaignObjective(string $objective): string
    {
        return match ($objective) {
            'OUTCOME_LEADS_CONTENT_VIEW' => 'OUTCOME_LEADS',
            default => $objective,
        };
    }

    private function resolveOptimizationGoal(string $objective, string $destinationType): string
    {
        if ($destinationType === 'WHATSAPP') {
            return match ($objective) {
                'OUTCOME_ENGAGEMENT' => 'REPLIES',
                'OUTCOME_TRAFFIC' => 'LINK_CLICKS',
                default => 'REACH',
            };
        }

        return match ($objective) {
            'OUTCOME_SALES', 'OUTCOME_LEADS', 'OUTCOME_LEADS_CONTENT_VIEW' => 'OFFSITE_CONVERSIONS',
            'OUTCOME_TRAFFIC' => 'LINK_CLICKS',
            default => 'REACH',
        };
    }

    private function resolveSpecialAdCategories(MetaAdBatch $batch): array
    {
        return ['NONE'];
    }
}
