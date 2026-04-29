<?php

namespace App\Services\Meta;

use App\Models\City;
use App\Models\MetaAdBatch;
use App\Models\MetaAdBatchItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MetaAdBatchProcessor
{
    public const CHUNK_SIZE = 25;

    private const TERMINAL_STATUSES = ['success', 'error'];

    public function __construct(
        private readonly MetaAdsService $adsService,
        private readonly CityImageGenerator $imageGenerator
    ) {}

    public function prepareBatch(MetaAdBatch $batch): array
    {
        $batch->loadMissing('user.metaConnection');

        if ($this->cancelIfRequested($batch)) {
            return [];
        }

        $hasExistingItems = $batch->items()->exists();
        $batch->update(array_merge([
            'status' => 'processing',
            'error_message' => null,
        ], $hasExistingItems ? [] : [
            'processed_items' => 0,
            'success_count' => 0,
            'error_count' => 0,
        ]));

        $batchContext = $this->batchContext($batch);
        $connection = $batch->user?->metaConnection;
        if (! $connection || ! $connection->access_token) {
            $this->markBatchFailed($batch, 'Conexao Meta ausente ou token invalido.', $batchContext);

            return [];
        }

        $cities = $this->resolveCities($batch);
        if ($cities->isEmpty()) {
            $this->markBatchFailed($batch, 'Nenhuma cidade encontrada para processar.', $batchContext);

            return [];
        }

        try {
            $runtime = $this->resolveRuntime($batch);
        } catch (Throwable $exception) {
            $this->markBatchFailed($batch, $exception->getMessage(), $batchContext);

            return [];
        }

        $batchContext = array_merge($batchContext, $this->runtimeLogContext($runtime));

        if (! $runtime['pixel_id']) {
            Log::channel('meta')->error('MetaAds batch missing pixel', $batchContext);
            $this->markBatchFailed($batch, 'Pixel nao informado para o lote.', $batchContext);

            return [];
        }

        if ($runtime['destination_type'] === 'WHATSAPP' && ! $runtime['page_id']) {
            Log::channel('meta')->error('MetaAds batch missing page_id for WhatsApp', $batchContext);
            $this->markBatchFailed($batch, 'Pagina do Facebook nao informada para campanha de WhatsApp.', $batchContext);

            return [];
        }

        if ($runtime['destination_type'] === 'WHATSAPP' && ! $runtime['whatsapp_number']) {
            Log::channel('meta')->warning('MetaAds batch missing whatsapp_number for WhatsApp, sending without number', $batchContext);
        }

        Log::channel('meta')->info('MetaAds batch start', $batchContext);
        Log::channel('meta')->info('MetaAds instagram actor selected', array_merge($batchContext, [
            'instagram_actor_id' => $runtime['instagram_actor_id'],
        ]));

        if ($runtime['instagram_actor_id']) {
            try {
                $this->adsService->fetchInstagramActorDetails($connection->access_token, $runtime['instagram_actor_id'], $batchContext);
            } catch (Throwable $exception) {
                Log::channel('meta')->warning('MetaAds instagram actor details failed', array_merge($batchContext, [
                    'instagram_actor_id' => $runtime['instagram_actor_id'],
                    'exception' => $exception->getMessage(),
                ]));
            }
        }

        if (! $this->ensureCampaign($batch, $connection->access_token, $runtime, $batchContext)) {
            return [];
        }

        if ($runtime['creative_source_mode'] === 'single_media' && $runtime['creative_media_type'] === 'video') {
            $videoId = $this->ensureSharedVideoId($batch, $connection->access_token, $runtime, $batchContext);
            if (! $videoId) {
                $this->markBatchFailed($batch, 'Falha no upload do video.', $batchContext);

                return [];
            }
        }

        $this->createMissingItems($batch, $cities, $runtime);
        $this->syncBatchCounters($batch);

        $chunks = $this->pendingItemChunks($batch);
        if ($chunks === []) {
            $this->finalizeBatchIfComplete($batch);
        }

        return $chunks;
    }

    public function processChunk(int $batchId, array $itemIds): void
    {
        $batch = MetaAdBatch::with('user.metaConnection')->find($batchId);
        if (! $batch) {
            return;
        }

        if ($this->cancelIfRequested($batch)) {
            return;
        }

        if (in_array($batch->status, ['failed', 'completed', 'completed_with_errors', 'cancelled'], true)) {
            return;
        }

        $connection = $batch->user?->metaConnection;
        $batchContext = $this->batchContext($batch);
        if (! $connection || ! $connection->access_token) {
            $this->markBatchFailed($batch, 'Conexao Meta ausente ou token invalido.', $batchContext);

            return;
        }

        try {
            $runtime = $this->resolveRuntime($batch);
        } catch (Throwable $exception) {
            $this->markBatchFailed($batch, $exception->getMessage(), $batchContext);

            return;
        }

        $batchContext = array_merge($batchContext, $this->runtimeLogContext($runtime));

        if (! $this->ensureCampaign($batch, $connection->access_token, $runtime, $batchContext)) {
            return;
        }

        if ($runtime['creative_source_mode'] === 'single_media' && $runtime['creative_media_type'] === 'video') {
            $videoId = $this->ensureSharedVideoId($batch, $connection->access_token, $runtime, $batchContext);
            if (! $videoId) {
                $this->failChunkItemsForBatch($batch, $itemIds, 'Falha no upload do video.');
                $this->finalizeBatchIfComplete($batch);

                return;
            }
        }

        $items = MetaAdBatchItem::query()
            ->where('meta_ad_batch_id', $batch->id)
            ->whereIn('id', $itemIds)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $batch->refresh();
            if ($this->cancelIfRequested($batch)) {
                return;
            }

            if ($this->isTerminalStatus($item->status)) {
                continue;
            }

            $this->processItem($batch, $item, $connection->access_token, $runtime, $batchContext);

            usleep(500000);
        }

        $this->finalizeBatchIfComplete($batch);
    }

    public function failChunkItems(int $batchId, array $itemIds, string $message): void
    {
        $batch = MetaAdBatch::find($batchId);
        if (! $batch) {
            return;
        }

        $this->failChunkItemsForBatch($batch, $itemIds, $message);
        $this->finalizeBatchIfComplete($batch);
    }

    public function markBatchFailed(MetaAdBatch $batch, string $message, array $context = []): void
    {
        $batch->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);

        Log::channel('meta')->error('MetaAds batch failed', array_merge($context, [
            'error_message' => $message,
        ]));
    }

    private function processItem(MetaAdBatch $batch, MetaAdBatchItem $item, string $accessToken, array $runtime, array $batchContext): void
    {
        $item->update(['status' => 'processing']);

        $itemContext = array_merge($batchContext, [
            'item_id' => $item->id,
            'city_id' => $item->city_id,
            'city' => $item->city_name,
            'state' => $item->state_name,
            'creative_source_index' => $item->creative_source_index,
            'creative_source_path' => $item->creative_source_path,
        ]);

        $generatedPath = null;

        try {
            $cityKey = $this->resolveItemCityKey($item, $accessToken, $itemContext);
            if (! $cityKey) {
                $this->markItemFailed($batch, $item, 'Cidade nao encontrada no Meta.');

                return;
            }

            $mediaId = null;
            if ($runtime['creative_source_mode'] === 'existing_post') {
                // Existing posts already contain their own media and copy.
            } elseif ($runtime['creative_media_type'] === 'video') {
                $mediaId = (string) ($item->image_hash ?: Arr::get($batch->settings, 'video_id'));
                if ($mediaId === '') {
                    $this->markItemFailed($batch, $item, 'Video do lote nao encontrado.');

                    return;
                }

                if (! $item->image_hash) {
                    $item->update(['image_hash' => $mediaId]);
                }
            } else {
                $mediaId = $item->image_hash;
                if (! $mediaId) {
                    $overlayTextTemplate = Arr::get($batch->settings, 'overlay_text', '');
                    if (! is_string($overlayTextTemplate)) {
                        $overlayTextTemplate = '';
                    }

                    $overlay = [
                        'text' => str_replace('{cidade}', $item->city_name, $overlayTextTemplate),
                        'text_color' => Arr::get($batch->settings, 'overlay_text_color', '#ffffff'),
                        'bg_color' => Arr::get($batch->settings, 'overlay_bg_color', '#000000'),
                        'position_x' => (float) Arr::get($batch->settings, 'overlay_position_x', 50),
                        'position_y' => (float) Arr::get($batch->settings, 'overlay_position_y', 12),
                    ];

                    $generatedPath = $this->generateCreativeImage((string) $item->creative_source_path, $overlay);
                    $mediaId = $this->adsService->uploadImage($accessToken, $runtime['ad_account_id'], $generatedPath, $itemContext);

                    if (! $mediaId) {
                        $this->markItemFailed($batch, $item, 'Falha no upload da imagem.');

                        return;
                    }

                    $item->update(['image_hash' => $mediaId]);
                }
            }

            $adSetName = sprintf('%s - %s', $item->state_name, $item->city_name);
            $adSetId = $item->ad_set_id ?: $this->createAdSet($batch, $runtime, $cityKey, $adSetName, $accessToken, $itemContext);
            if (! $adSetId) {
                $this->markItemFailed($batch, $item, 'Erro ao criar conjunto de anuncios.');

                return;
            }

            if (! $item->ad_set_id) {
                $item->update(['ad_set_id' => $adSetId]);
            }

            $creativeId = $item->ad_creative_id ?: $this->createCreative($batch, $item, $runtime, $adSetName, (string) $mediaId, $accessToken, $itemContext);
            if (! $creativeId) {
                $this->markItemFailed($batch, $item, 'Erro ao criar criativo.');

                return;
            }

            if (! $item->ad_creative_id) {
                $item->update(['ad_creative_id' => $creativeId]);
            }

            $adId = $item->ad_id ?: $this->adsService->createAd(
                $accessToken,
                $runtime['ad_account_id'],
                $adSetId,
                $creativeId,
                $adSetName,
                $runtime['status'],
                $itemContext
            );

            if (! $adId) {
                $this->markItemFailed($batch, $item, 'Erro ao criar anuncio.');

                return;
            }

            if (! $item->ad_id) {
                $item->update(['ad_id' => $adId]);
            }

            $this->markItemSucceeded($batch, $item, [
                'meta_city_key' => $cityKey,
                'ad_set_id' => $adSetId,
                'ad_creative_id' => $creativeId,
                'ad_id' => $adId,
                'image_hash' => $mediaId,
            ]);
        } catch (Throwable $exception) {
            Log::channel('meta')->error('MetaAds batch item exception', array_merge($itemContext, [
                'exception' => $exception->getMessage(),
            ]));

            $this->markItemFailed($batch, $item, $exception->getMessage());
        } finally {
            $this->deleteGeneratedImage($generatedPath);
        }
    }

    private function createAdSet(MetaAdBatch $batch, array $runtime, string $cityKey, string $adSetName, string $accessToken, array $context): ?string
    {
        $conversionEvent = $this->resolveConversionEvent($batch->objective);
        $optimizationGoal = $this->resolveOptimizationGoal($batch->objective, $runtime['destination_type']);
        $usePixel = $runtime['destination_type'] !== 'WHATSAPP' && $conversionEvent !== null;

        return $this->adsService->createAdSet(
            $accessToken,
            $runtime['ad_account_id'],
            (string) $batch->meta_campaign_id,
            $adSetName,
            $cityKey,
            $batch->daily_budget_cents,
            $this->formatStartTimeUtc($batch->start_at),
            $usePixel ? $runtime['pixel_id'] : null,
            $usePixel ? $conversionEvent : null,
            $runtime['status'],
            $context,
            [
                'destination_type' => $runtime['destination_type'],
                'page_id' => $runtime['page_id'],
                'optimization_goal' => $optimizationGoal,
            ]
        );
    }

    private function createCreative(
        MetaAdBatch $batch,
        MetaAdBatchItem $item,
        array $runtime,
        string $adSetName,
        string $mediaId,
        string $accessToken,
        array $context
    ): ?string {
        $title = str_replace('{cidade}', $item->city_name, $batch->title_template);
        $body = str_replace('{cidade}', $item->city_name, $batch->body_template);
        $url = str_replace('{cidade}', $item->city_name, $batch->url_template);

        if ($runtime['creative_source_mode'] === 'existing_post') {
            return $this->adsService->createExistingPostCreative(
                $accessToken,
                $runtime['ad_account_id'],
                $adSetName,
                (string) $runtime['existing_post_id'],
                $context
            );
        }

        $options = [
            'destination_type' => $runtime['destination_type'],
            'whatsapp_number' => $runtime['whatsapp_number'],
        ];

        $creator = $runtime['creative_media_type'] === 'video'
            ? fn (string $enrollStatus) => $this->createVideoCreativeWithFallback(
                $accessToken,
                $runtime['ad_account_id'],
                $adSetName,
                $title,
                $body,
                $url,
                $mediaId,
                (string) $runtime['page_id'],
                $runtime['instagram_actor_id'],
                $enrollStatus,
                $context,
                $item,
                $options
            )
            : fn (string $enrollStatus) => $this->createImageCreativeWithFallback(
                $accessToken,
                $runtime['ad_account_id'],
                $adSetName,
                $title,
                $body,
                $url,
                $mediaId,
                (string) $runtime['page_id'],
                $runtime['instagram_actor_id'],
                $enrollStatus,
                $context,
                $item,
                $options
            );

        return $creator('OPT_IN') ?: $creator('OPT_OUT');
    }

    private function createMissingItems(MetaAdBatch $batch, $cities, array $runtime): void
    {
        $existingCityIds = $batch->items()
            ->whereNotNull('city_id')
            ->pluck('id', 'city_id')
            ->all();

        foreach ($cities->values() as $cityIndex => $city) {
            if (array_key_exists((string) $city->id, $existingCityIds) || array_key_exists($city->id, $existingCityIds)) {
                continue;
            }

            [$selectedSourceIndex, $selectedSourceRelativePath] = $this->creativeSourceForCity($batch, $runtime, $cityIndex);

            $batch->items()->create([
                'city_id' => $city->id,
                'city_name' => $city->name,
                'state_name' => $city->state,
                'meta_city_key' => $city->meta_city_key,
                'creative_source_path' => $selectedSourceRelativePath,
                'creative_source_index' => $selectedSourceIndex,
                'status' => 'pending',
            ]);
        }
    }

    private function pendingItemChunks(MetaAdBatch $batch): array
    {
        return $batch->items()
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id')
            ->pluck('id')
            ->chunk(self::CHUNK_SIZE)
            ->map(fn ($chunk) => $chunk->values()->all())
            ->values()
            ->all();
    }

    private function resolveItemCityKey(MetaAdBatchItem $item, string $accessToken, array $context): ?string
    {
        if (filled($item->meta_city_key)) {
            return (string) $item->meta_city_key;
        }

        $city = $item->city;
        if ($city && filled($city->meta_city_key)) {
            $item->update(['meta_city_key' => $city->meta_city_key]);

            return (string) $city->meta_city_key;
        }

        $cityKey = $this->adsService->findCityKey($accessToken, $item->city_name, $item->state_name, $context);
        if (! $cityKey) {
            return null;
        }

        $item->update(['meta_city_key' => $cityKey]);
        if ($city) {
            $city->update(['meta_city_key' => $cityKey]);
        }

        return $cityKey;
    }

    private function ensureCampaign(MetaAdBatch $batch, string $accessToken, array $runtime, array $context): bool
    {
        if ($batch->meta_campaign_id) {
            return true;
        }

        $campaignId = $this->adsService->createCampaign(
            $accessToken,
            $runtime['ad_account_id'],
            $this->resolveCampaignObjective($batch->objective),
            $this->buildCampaignName($batch->objective),
            $runtime['status'],
            $context,
            $this->resolveSpecialAdCategories($batch)
        );

        if (! $campaignId) {
            Log::channel('meta')->error('MetaAds batch campaign failed', $context);
            $this->markBatchFailed($batch, 'Nao foi possivel criar a campanha no Meta Ads.', $context);

            return false;
        }

        $batch->update(['meta_campaign_id' => $campaignId]);
        $batch->refresh();

        return true;
    }

    private function ensureSharedVideoId(MetaAdBatch $batch, string $accessToken, array $runtime, array $context): ?string
    {
        $videoId = Arr::get($batch->settings, 'video_id');
        if (is_string($videoId) && trim($videoId) !== '') {
            return trim($videoId);
        }

        $videoId = $this->adsService->uploadVideo(
            $accessToken,
            $runtime['ad_account_id'],
            $this->resolveBatchSourceMediaPath($batch),
            $context
        );

        if (! $videoId) {
            return null;
        }

        $this->updateBatchSettings($batch, ['video_id' => $videoId]);

        return $videoId;
    }

    private function resolveRuntime(MetaAdBatch $batch): array
    {
        $destinationType = $batch->destination_type ?: Arr::get($batch->settings, 'destination_type') ?: 'WEBSITE';
        $whatsappNumber = Arr::get($batch->settings, 'whatsapp_number');
        if (is_string($whatsappNumber)) {
            $whatsappNumber = preg_replace('/\D/', '', $whatsappNumber);
        }

        $creativeSourceMode = $this->resolveCreativeSourceMode($batch);
        $creativeMediaType = $this->resolveCreativeMediaType($batch);
        $rotationImagePaths = [];
        $existingPostId = null;

        if ($creativeSourceMode === 'image_rotation') {
            if ($creativeMediaType !== 'image') {
                throw new \RuntimeException('Rodizio aceita apenas imagens.');
            }

            $rotationImagePaths = $this->resolveRotationImagePaths($batch);
            foreach ($rotationImagePaths as $rotationImagePath) {
                $this->resolveBatchSourceMediaPathFromRelative($rotationImagePath);
            }
        } elseif ($creativeSourceMode === 'existing_post') {
            $existingPostId = $this->resolveExistingPostId($batch);
        } else {
            $this->resolveBatchSourceMediaPath($batch);
        }

        return [
            'ad_account_id' => $batch->ad_account_id,
            'page_id' => $batch->page_id,
            'instagram_actor_id' => $batch->instagram_actor_id,
            'pixel_id' => $batch->pixel_id,
            'destination_type' => $destinationType,
            'whatsapp_number' => $whatsappNumber ?: null,
            'status' => $batch->auto_activate ? 'ACTIVE' : 'PAUSED',
            'creative_source_mode' => $creativeSourceMode,
            'creative_media_type' => $creativeMediaType,
            'rotation_image_paths' => $rotationImagePaths,
            'rotation_image_count' => count($rotationImagePaths),
            'existing_post_id' => $existingPostId,
        ];
    }

    private function runtimeLogContext(array $runtime): array
    {
        return [
            'ad_account_id' => $runtime['ad_account_id'],
            'destination_type' => $runtime['destination_type'],
            'creative_source_mode' => $runtime['creative_source_mode'],
            'creative_media_type' => $runtime['creative_media_type'],
            'rotation_image_count' => $runtime['rotation_image_count'],
            'existing_post_id' => $runtime['existing_post_id'],
        ];
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
            if (Str::ascii(Str::lower((string) $candidate)) === $normalized) {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function resolveCreativeSourceMode(MetaAdBatch $batch): string
    {
        $configured = Arr::get($batch->settings, 'creative_source_mode');

        return is_string($configured) && trim($configured) === 'image_rotation'
            ? 'image_rotation'
            : (is_string($configured) && trim($configured) === 'existing_post' ? 'existing_post' : 'single_media');
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

        if (! is_array($paths)) {
            throw new \RuntimeException('Lista de imagens do rodizio invalida.');
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $path = trim($path);

            if (! $this->isSupportedRotationImagePath($path)) {
                throw new \RuntimeException('Rodizio aceita apenas imagens JPG, PNG ou WEBP.');
            }

            $normalized[] = $path;
        }

        if (count($normalized) < 1 || count($normalized) > 30) {
            throw new \RuntimeException('Rodizio requer de 1 a 30 imagens.');
        }

        return array_values($normalized);
    }

    private function resolveExistingPostId(MetaAdBatch $batch): string
    {
        $postId = Arr::get($batch->settings, 'existing_post_id');

        if (! is_string($postId) || trim($postId) === '') {
            throw new \RuntimeException('ID do post existente nao informado.');
        }

        $postId = trim($postId);

        if (preg_match('/^\d+_\d+$/', $postId) !== 1) {
            throw new \RuntimeException('ID do post existente invalido. Use pagina_post.');
        }

        return $postId;
    }

    private function creativeSourceForCity(MetaAdBatch $batch, array $runtime, int $cityIndex): array
    {
        if ($runtime['creative_source_mode'] === 'single_media') {
            return [0, $batch->image_path];
        }

        if ($runtime['creative_source_mode'] === 'image_rotation' && $runtime['rotation_image_count'] > 0) {
            $sourceIndex = $cityIndex % $runtime['rotation_image_count'];

            return [$sourceIndex, $runtime['rotation_image_paths'][$sourceIndex] ?? null];
        }

        return [null, null];
    }

    private function isSupportedRotationImagePath(string $path): bool
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    private function resolveBatchSourceMediaPath(MetaAdBatch $batch): string
    {
        if (! filled($batch->image_path)) {
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

        if (! is_file($path)) {
            throw new \RuntimeException('Arquivo de midia do anuncio nao encontrado.');
        }

        return $path;
    }

    private function generateCreativeImage(string $sourceRelativePath, array $overlay): string
    {
        $sourcePath = $this->resolveBatchSourceMediaPathFromRelative($sourceRelativePath);

        return Storage::disk('local')->path($this->imageGenerator->generate($sourcePath, $overlay));
    }

    private function deleteGeneratedImage(?string $path): void
    {
        if (! $path) {
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

    private function markItemSucceeded(MetaAdBatch $batch, MetaAdBatchItem $item, array $attributes): void
    {
        $wasTerminal = $this->isTerminalStatus($item->status);

        $item->update(array_merge($attributes, [
            'status' => 'success',
        ]));

        if (! $wasTerminal) {
            $batch->increment('success_count');
            $batch->increment('processed_items');
        }
    }

    private function markItemFailed(MetaAdBatch $batch, MetaAdBatchItem $item, string $message): void
    {
        $wasTerminal = $this->isTerminalStatus($item->status);

        $item->update([
            'status' => 'error',
            'error_message' => $message,
        ]);

        if (! $wasTerminal) {
            $batch->increment('error_count');
            $batch->increment('processed_items');
        }
    }

    private function failChunkItemsForBatch(MetaAdBatch $batch, array $itemIds, string $message): void
    {
        $items = $batch->items()
            ->whereIn('id', $itemIds)
            ->whereIn('status', ['pending', 'processing'])
            ->get();

        foreach ($items as $item) {
            $this->markItemFailed($batch, $item, $message);
        }
    }

    private function syncBatchCounters(MetaAdBatch $batch): void
    {
        $batch->update([
            'total_items' => $batch->items()->count(),
            'processed_items' => $batch->items()->whereIn('status', self::TERMINAL_STATUSES)->count(),
            'success_count' => $batch->items()->where('status', 'success')->count(),
            'error_count' => $batch->items()->where('status', 'error')->count(),
        ]);
    }

    private function finalizeBatchIfComplete(MetaAdBatch $batch): void
    {
        $batch->refresh();
        if (in_array($batch->status, ['cancelled', 'cancel_requested', 'failed'], true)) {
            return;
        }

        $this->syncBatchCounters($batch);
        $batch->refresh();

        $hasOpenItems = $batch->items()
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasOpenItems) {
            return;
        }

        $batch->update([
            'status' => $batch->error_count > 0 ? 'completed_with_errors' : 'completed',
        ]);

        Log::channel('meta')->info('MetaAds batch complete', array_merge($this->batchContext($batch), [
            'status' => $batch->status,
            'success_count' => $batch->success_count,
            'error_count' => $batch->error_count,
        ]));
    }

    private function cancelIfRequested(MetaAdBatch $batch): bool
    {
        if (! $batch->cancel_requested_at && $batch->status !== 'cancel_requested' && $batch->status !== 'cancelled') {
            return false;
        }

        $batch->update([
            'status' => 'cancelled',
            'cancelled_at' => $batch->cancelled_at ?? now(),
        ]);

        Log::channel('meta')->warning('MetaAds batch cancelled', $this->batchContext($batch));

        return true;
    }

    private function updateBatchSettings(MetaAdBatch $batch, array $values): void
    {
        $settings = is_array($batch->settings) ? $batch->settings : [];

        $batch->update([
            'settings' => array_merge($settings, $values),
        ]);

        $batch->refresh();
    }

    private function batchContext(MetaAdBatch $batch): array
    {
        return [
            'batch_id' => $batch->id,
            'user_id' => $batch->user_id,
            'ad_account_id' => $batch->ad_account_id,
            'destination_type' => $batch->destination_type ?: Arr::get($batch->settings, 'destination_type') ?: 'WEBSITE',
        ];
    }

    private function buildCampaignName(string $objective): string
    {
        $label = match ($objective) {
            'OUTCOME_SALES' => 'Compra',
            'OUTCOME_SALES_INITIATE_CHECKOUT' => 'IniciarCheckout',
            'OUTCOME_LEADS' => 'Cadastro',
            'OUTCOME_LEADS_CONTENT_VIEW' => 'ContentView',
            'OUTCOME_AWARENESS' => 'Reconhecimento',
            'OUTCOME_TRAFFIC' => 'Trafego',
            'OUTCOME_ENGAGEMENT' => 'Engajamento',
            default => 'Campanha',
        };

        return sprintf('Afiliados %s - %s', $label, now()->format('Y-m-d H:i:s'));
    }

    private function createImageCreativeWithFallback(
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
            return $this->adsService->createCreative(
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
                    'error_message' => 'Falha no Instagram: '.$exception->getMessage(),
                ]);

                return $this->adsService->createCreative(
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
            return $this->adsService->createVideoCreative(
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
                    'error_message' => 'Falha no Instagram: '.$exception->getMessage(),
                ]);

                return $this->adsService->createVideoCreative(
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
            'OUTCOME_SALES_INITIATE_CHECKOUT' => 'INITIATE_CHECKOUT',
            'OUTCOME_LEADS' => 'LEAD',
            'OUTCOME_LEADS_CONTENT_VIEW' => 'CONTENT_VIEW',
            default => null,
        };
    }

    private function resolveCampaignObjective(string $objective): string
    {
        return match ($objective) {
            'OUTCOME_LEADS_CONTENT_VIEW' => 'OUTCOME_LEADS',
            'OUTCOME_SALES_INITIATE_CHECKOUT' => 'OUTCOME_SALES',
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
            'OUTCOME_SALES', 'OUTCOME_SALES_INITIATE_CHECKOUT', 'OUTCOME_LEADS', 'OUTCOME_LEADS_CONTENT_VIEW' => 'OFFSITE_CONVERSIONS',
            'OUTCOME_TRAFFIC' => 'LINK_CLICKS',
            default => 'REACH',
        };
    }

    private function resolveSpecialAdCategories(MetaAdBatch $batch): array
    {
        return ['NONE'];
    }

    private function isTerminalStatus(?string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }
}
