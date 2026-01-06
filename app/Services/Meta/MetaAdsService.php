<?php

namespace App\Services\Meta;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MetaAdsService
{
    public function __construct(
        private readonly MetaGraphClient $client
    ) {
    }

    public function fetchAdAccounts(string $accessToken, int $userId): array
    {
        return Cache::remember($this->cacheKey($userId, 'ad_accounts'), now()->addHour(), function () use ($accessToken) {
            $response = $this->client->get('me/adaccounts', $accessToken, [
                'fields' => 'id,name,account_id',
            ]);

            return collect(Arr::get($response, 'data', []))
                ->mapWithKeys(function (array $account) {
                    $id = $account['account_id'] ?? $account['id'] ?? null;
                    if (!$id) {
                        return [];
                    }

                    $name = $account['name'] ?? $id;

                    return [$this->stripActPrefix($id) => sprintf('%s (%s)', $name, $this->stripActPrefix($id))];
                })
                ->all();
        });
    }

    public function fetchPages(string $accessToken, int $userId): array
    {
        return Cache::remember($this->cacheKey($userId, 'pages'), now()->addHour(), function () use ($accessToken) {
            $response = $this->client->get('me/accounts', $accessToken, [
                'fields' => 'id,name',
            ]);

            return collect(Arr::get($response, 'data', []))
                ->mapWithKeys(function (array $page) {
                    $id = $page['id'] ?? null;
                    if (!$id) {
                        return [];
                    }

                    $name = $page['name'] ?? $id;

                    return [$id => $name];
                })
                ->all();
        });
    }

    public function fetchInstagramAccounts(string $accessToken, int $userId): array
    {
        return Cache::remember($this->cacheKey($userId, 'instagram_accounts'), now()->addHour(), function () use ($accessToken) {
            $response = $this->client->get('me/accounts', $accessToken, [
                'fields' => 'instagram_business_account{name,username},name',
            ]);

            return collect(Arr::get($response, 'data', []))
                ->mapWithKeys(function (array $page) {
                    $ig = $page['instagram_business_account'] ?? null;
                    if (!$ig || empty($ig['id'])) {
                        return [];
                    }

                    $label = $ig['username'] ?? $ig['name'] ?? $ig['id'];

                    return [$ig['id'] => $label];
                })
                ->all();
        });
    }

    public function fetchPixels(string $accessToken, int $userId, string $adAccountId): array
    {
        if (!$adAccountId) {
            return [];
        }

        $cacheKey = $this->cacheKey($userId, 'pixels:' . $this->stripActPrefix($adAccountId));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($accessToken, $adAccountId) {
            $response = $this->client->get(sprintf('%s/adspixels', $this->formatAdAccountId($adAccountId)), $accessToken, [
                'fields' => 'id,name',
            ]);

            return collect(Arr::get($response, 'data', []))
                ->mapWithKeys(function (array $pixel) {
                    $id = $pixel['id'] ?? null;
                    if (!$id) {
                        return [];
                    }

                    $name = $pixel['name'] ?? $id;

                    return [$id => $name];
                })
                ->all();
        });
    }

    public function forgetCacheForUser(int $userId): void
    {
        Cache::forget($this->cacheKey($userId, 'ad_accounts'));
        Cache::forget($this->cacheKey($userId, 'pages'));
        Cache::forget($this->cacheKey($userId, 'instagram_accounts'));
    }

    public function forgetPixelsCacheForUser(int $userId, ?string $adAccountId): void
    {
        if (!$adAccountId) {
            return;
        }

        Cache::forget($this->cacheKey($userId, 'pixels:' . $this->stripActPrefix($adAccountId)));
    }

    public function findCityKey(string $accessToken, string $cityName, ?string $stateName = null): ?string
    {
        $response = $this->client->get('search', $accessToken, [
            'type' => 'adgeolocation',
            'location_types' => 'city',
            'q' => $cityName,
        ]);

        $fallback = null;

        foreach (Arr::get($response, 'data', []) as $entry) {
            if (($entry['type'] ?? null) !== 'city' || ($entry['country_code'] ?? null) !== 'BR') {
                continue;
            }

            if ($stateName && isset($entry['region']) && Str::lower($entry['region']) !== Str::lower($stateName)) {
                $fallback ??= $entry['key'] ?? null;
                continue;
            }

            return $entry['key'] ?? null;
        }

        return $fallback;
    }

    public function createCampaign(string $accessToken, string $adAccountId, string $objective, string $name, string $status): ?string
    {
        $response = $this->client->post(sprintf('%s/campaigns', $this->formatAdAccountId($adAccountId)), $accessToken, [
            'name' => $name,
            'objective' => $objective,
            'status' => $status,
            'special_ad_categories' => json_encode([]),
        ]);

        return $response['id'] ?? null;
    }

    public function createAdSet(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $name,
        string $cityKey,
        int $dailyBudgetCents,
        string $startTimeUtc,
        string $pixelId,
        string $conversionEvent,
        string $status
    ): ?string {
        $response = $this->client->post(sprintf('%s/adsets', $this->formatAdAccountId($adAccountId)), $accessToken, [
            'name' => $name,
            'campaign_id' => $campaignId,
            'daily_budget' => $dailyBudgetCents,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'OFFSITE_CONVERSIONS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode([
                'geo_locations' => [
                    'cities' => [
                        ['key' => $cityKey],
                    ],
                ],
                'device_platforms' => ['mobile'],
            ]),
            'promoted_object' => json_encode([
                'pixel_id' => $pixelId,
                'custom_event_type' => $conversionEvent,
            ]),
            'status' => $status,
            'start_time' => $startTimeUtc,
            'is_optimized_for_quality' => false,
        ]);

        return $response['id'] ?? null;
    }

    public function uploadImage(string $accessToken, string $adAccountId, string $filePath): ?string
    {
        $response = $this->client->postWithFile(
            sprintf('%s/adimages', $this->formatAdAccountId($adAccountId)),
            $accessToken,
            'filename',
            $filePath,
        );

        $images = $response['images'] ?? [];
        if (!$images) {
            return null;
        }

        $first = array_values($images)[0] ?? null;

        return $first['hash'] ?? null;
    }

    public function createCreative(
        string $accessToken,
        string $adAccountId,
        string $name,
        string $title,
        string $body,
        string $url,
        string $imageHash,
        string $pageId,
        ?string $instagramActorId,
        string $enrollStatus
    ): ?string {
        $storySpec = [
            'page_id' => $pageId,
            'link_data' => [
                'image_hash' => $imageHash,
                'link' => $url,
                'message' => $body,
                'name' => $title,
                'description' => $body,
                'call_to_action' => [
                    'type' => 'LEARN_MORE',
                    'value' => [
                        'link' => $url,
                    ],
                ],
            ],
        ];

        if ($instagramActorId) {
            $storySpec['instagram_actor_id'] = $instagramActorId;
        }

        $response = $this->client->post(sprintf('%s/adcreatives', $this->formatAdAccountId($adAccountId)), $accessToken, [
            'name' => $name,
            'object_story_spec' => json_encode($storySpec),
            'degrees_of_freedom_spec' => json_encode([
                'creative_features_spec' => [
                    'standard_enhancements' => [
                        'enroll_status' => $enrollStatus,
                    ],
                ],
            ]),
        ]);

        return $response['id'] ?? null;
    }

    public function createAd(
        string $accessToken,
        string $adAccountId,
        string $adSetId,
        string $creativeId,
        string $name,
        string $status
    ): ?string {
        $response = $this->client->post(sprintf('%s/ads', $this->formatAdAccountId($adAccountId)), $accessToken, [
            'name' => $name,
            'adset_id' => $adSetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status' => $status,
        ]);

        return $response['id'] ?? null;
    }

    private function cacheKey(int $userId, string $suffix): string
    {
        return sprintf('meta:%d:%s', $userId, $suffix);
    }

    private function formatAdAccountId(string $adAccountId): string
    {
        return Str::startsWith($adAccountId, 'act_') ? $adAccountId : 'act_' . $adAccountId;
    }

    private function stripActPrefix(string $adAccountId): string
    {
        return Str::startsWith($adAccountId, 'act_') ? Str::after($adAccountId, 'act_') : $adAccountId;
    }
}
