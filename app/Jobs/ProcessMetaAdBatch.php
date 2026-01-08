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

        $batch->update([
            'status' => 'processing',
            'processed_items' => 0,
            'success_count' => 0,
            'error_count' => 0,
        ]);

        $connection = $batch->user->metaConnection;
        if (!$connection || !$connection->access_token) {
            $batch->update(['status' => 'failed']);
            return;
        }

        $cities = $this->resolveCities($batch);
        if ($cities->isEmpty()) {
            $batch->update(['status' => 'failed']);
            return;
        }

        $batch->update(['total_items' => $cities->count()]);

        $accessToken = $connection->access_token;
        $adAccountId = $batch->ad_account_id;
        $pageId = $batch->page_id;
        $instagramActorId = $batch->instagram_actor_id;
        $pixelId = $batch->pixel_id;
        $status = $batch->auto_activate ? 'ACTIVE' : 'PAUSED';

        $batchContext = [
            'batch_id' => $batch->id,
            'user_id' => $batch->user_id,
            'ad_account_id' => $adAccountId,
        ];

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
                $batch->objective,
                $campaignName,
                $status,
                $batchContext
            );
            if (!$campaignId) {
                Log::channel('meta')->error('MetaAds batch campaign failed', $batchContext);
                $batch->update(['status' => 'failed']);
                return;
            }

            $batch->update(['meta_campaign_id' => $campaignId]);
        }

        foreach ($cities as $city) {
            $item = $batch->items()->create([
                'city_id' => $city->id,
                'city_name' => $city->name,
                'state_name' => $city->state,
                'status' => 'processing',
            ]);

            $itemContext = array_merge($batchContext, [
                'item_id' => $item->id,
                'city_id' => $city->id,
                'city' => $city->name,
                'state' => $city->state,
            ]);

            $generatedPath = null;

            try {
                $cityKey = $adsService->findCityKey($accessToken, $city->name, $city->state, $itemContext);
                if (!$cityKey) {
                    $this->markItemFailed($batch, $item, 'Cidade nao encontrada no Meta.');
                    continue;
                }

                $overlayTextTemplate = Arr::get($batch->settings, 'overlay_text', '{cidade}');
                if (!is_string($overlayTextTemplate) || trim($overlayTextTemplate) === '') {
                    $overlayTextTemplate = '{cidade}';
                }
                $overlayText = str_replace('{cidade}', $city->name, $overlayTextTemplate);

                $overlay = [
                    'text' => $overlayText,
                    'text_color' => Arr::get($batch->settings, 'overlay_text_color', '#ffffff'),
                    'bg_color' => Arr::get($batch->settings, 'overlay_bg_color', '#000000'),
                    'position_x' => (float) Arr::get($batch->settings, 'overlay_position_x', 50),
                    'position_y' => (float) Arr::get($batch->settings, 'overlay_position_y', 12),
                ];

                $generatedPath = $this->generateCreativeImage($imageGenerator, $batch, $overlay);
                $imageHash = $adsService->uploadImage($accessToken, $adAccountId, $generatedPath, $itemContext);

                if (!$imageHash) {
                    $this->markItemFailed($batch, $item, 'Falha no upload da imagem.');
                    $this->deleteGeneratedImage($generatedPath);
                    continue;
                }

                $conversionEvent = $batch->objective === 'OUTCOME_SALES' ? 'PURCHASE' : 'LEAD';
                $startTimeUtc = $this->formatStartTimeUtc($batch->start_at);
                $adSetName = sprintf('%s - %s', $city->state, $city->name);

                $adSetId = $adsService->createAdSet(
                    $accessToken,
                    $adAccountId,
                    $campaignId,
                    $adSetName,
                    $cityKey,
                    $batch->daily_budget_cents,
                    $startTimeUtc,
                    $pixelId,
                    $conversionEvent,
                    $status,
                    $itemContext
                );

                if (!$adSetId) {
                    $this->markItemFailed($batch, $item, 'Erro ao criar conjunto de anuncios.');
                    $this->deleteGeneratedImage($generatedPath);
                    continue;
                }

                $title = str_replace('{cidade}', $city->name, $batch->title_template);
                $body = str_replace('{cidade}', $city->name, $batch->body_template);
                $url = str_replace('{cidade}', $city->name, $batch->url_template);

                $creativeId = $this->createCreativeWithFallback(
                    $adsService,
                    $accessToken,
                    $adAccountId,
                    $adSetName,
                    $title,
                    $body,
                    $url,
                    $imageHash,
                    $pageId,
                    $instagramActorId,
                    'OPT_IN',
                    $itemContext,
                    $item
                );

                if (!$creativeId) {
                    $creativeId = $this->createCreativeWithFallback(
                        $adsService,
                        $accessToken,
                        $adAccountId,
                        $adSetName,
                        $title,
                        $body,
                        $url,
                        $imageHash,
                        $pageId,
                        $instagramActorId,
                        'OPT_OUT',
                        $itemContext,
                        $item
                    );
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
                    'image_hash' => $imageHash,
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

        $batch->update([
            'status' => $batch->error_count > 0 ? 'completed_with_errors' : 'completed',
        ]);

        Log::channel('meta')->info('MetaAds batch complete', array_merge($batchContext, [
            'status' => $batch->status,
            'success_count' => $batch->success_count,
            'error_count' => $batch->error_count,
        ]));
    }

    private function resolveCities(MetaAdBatch $batch)
    {
        $state = Arr::get($batch->settings, 'state');
        $cityIds = Arr::get($batch->settings, 'city_ids', []);

        if ($state) {
            return City::query()
                ->where('state', $state)
                ->orderBy('name')
                ->get();
        }

        return City::query()
            ->whereIn('id', $cityIds)
            ->orderBy('name')
            ->get();
    }

    private function buildCampaignName(string $objective): string
    {
        $label = $objective === 'OUTCOME_SALES' ? 'Compra' : 'Cadastro';

        return sprintf('Afiliados %s - %s', $label, now()->format('Y-m-d H:i:s'));
    }

    private function generateCreativeImage(CityImageGenerator $generator, MetaAdBatch $batch, array $overlay): string
    {
        $sourcePath = Storage::disk('public')->path($batch->image_path);

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
        MetaAdBatchItem $item
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
                $context
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
                    array_merge($context, ['instagram_fallback' => true])
                );
            }

            throw $exception;
        }
    }
}
