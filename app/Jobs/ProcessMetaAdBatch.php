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
use Illuminate\Support\Facades\Storage;
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

        $campaignId = $batch->meta_campaign_id;
        if (!$campaignId) {
            $campaignName = $this->buildCampaignName($batch->objective);
            $campaignId = $adsService->createCampaign($accessToken, $adAccountId, $batch->objective, $campaignName, $status);
            if (!$campaignId) {
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

            $generatedPath = null;

            try {
                $cityKey = $adsService->findCityKey($accessToken, $city->name, $city->state);
                if (!$cityKey) {
                    $this->markItemFailed($batch, $item, 'Cidade nao encontrada no Meta.');
                    continue;
                }

                $generatedPath = $this->generateCreativeImage($imageGenerator, $batch, $city->name);
                $imageHash = $adsService->uploadImage($accessToken, $adAccountId, $generatedPath);

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
                    $status
                );

                if (!$adSetId) {
                    $this->markItemFailed($batch, $item, 'Erro ao criar conjunto de anuncios.');
                    $this->deleteGeneratedImage($generatedPath);
                    continue;
                }

                $title = str_replace('{cidade}', $city->name, $batch->title_template);
                $body = str_replace('{cidade}', $city->name, $batch->body_template);
                $url = str_replace('{cidade}', $city->name, $batch->url_template);

                $creativeId = $adsService->createCreative(
                    $accessToken,
                    $adAccountId,
                    $adSetName,
                    $title,
                    $body,
                    $url,
                    $imageHash,
                    $pageId,
                    $instagramActorId,
                    'OPT_IN'
                );

                if (!$creativeId) {
                    $creativeId = $adsService->createCreative(
                        $accessToken,
                        $adAccountId,
                        $adSetName,
                        $title,
                        $body,
                        $url,
                        $imageHash,
                        $pageId,
                        $instagramActorId,
                        'OPT_OUT'
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
                    $status
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

    private function generateCreativeImage(CityImageGenerator $generator, MetaAdBatch $batch, string $cityName): string
    {
        $sourcePath = Storage::disk('public')->path($batch->image_path);

        return Storage::disk('local')->path($generator->generate($sourcePath, $cityName));
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
}
