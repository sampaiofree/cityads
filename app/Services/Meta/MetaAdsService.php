<?php

namespace App\Services\Meta;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaAdsService
{
    public function __construct(
        private readonly MetaGraphClient $client
    ) {
    }

    public function fetchAdAccounts(string $accessToken, int $userId, array $context = []): array
    {
        return Cache::remember($this->cacheKey($userId, 'ad_accounts'), now()->addHour(), function () use ($accessToken, $context) {
            $stepContext = $this->withStep($context, 'fetch_ad_accounts');
            $this->logMeta('debug', 'MetaAdsService fetch ad accounts', $stepContext);

            $response = $this->client->get('me/adaccounts', $accessToken, [
                'fields' => 'id,name,account_id',
            ], $stepContext);

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

    public function fetchPages(string $accessToken, int $userId, array $context = []): array
    {
        return Cache::remember($this->cacheKey($userId, 'pages'), now()->addHour(), function () use ($accessToken, $context) {
            $stepContext = $this->withStep($context, 'fetch_pages');
            $this->logMeta('debug', 'MetaAdsService fetch pages', $stepContext);

            $response = $this->client->get('me/accounts', $accessToken, [
                'fields' => 'id,name',
            ], $stepContext);

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

    public function fetchInstagramAccounts(string $accessToken, int $userId, ?string $adAccountId = null, array $context = []): array
    {
        $cacheKey = $this->cacheKey($userId, 'instagram_accounts:' . ($adAccountId ? $this->stripActPrefix($adAccountId) : 'all'));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($accessToken, $context, $adAccountId) {
            $stepContext = $this->withStep($context, 'fetch_instagram_accounts');
            $this->logMeta('debug', 'MetaAdsService fetch instagram accounts', $stepContext);

            $results = [];

            if ($adAccountId) {
                $response = $this->client->get(sprintf('%s/instagram_accounts', $this->formatAdAccountId($adAccountId)), $accessToken, [
                    'fields' => 'id,ig_id,username,name',
                ], array_merge($stepContext, [
                    'ad_account_id' => $this->stripActPrefix($adAccountId),
                    'source' => 'ad_account',
                ]));

                foreach (Arr::get($response, 'data', []) as $ig) {
                    $id = $ig['id'] ?? null;
                    if (!$id) {
                        continue;
                    }

                    $labelBase = $ig['username'] ?? $ig['name'] ?? $id;
                    $igId = $ig['ig_id'] ?? null;
                    $results[$id] = $this->formatInstagramLabel($labelBase, $id, $igId, 'ad_account_id');
                }
            }

            if (empty($results)) {
                $response = $this->client->get('me/accounts', $accessToken, [
                    'fields' => 'instagram_business_account{id,ig_id,username},name',
                ], array_merge($stepContext, ['source' => 'pages']));

                $results = collect(Arr::get($response, 'data', []))
                    ->flatMap(function (array $page) {
                        $ig = $page['instagram_business_account'] ?? null;
                        if (!$ig || empty($ig['id'])) {
                            return [];
                        }

                        $username = $ig['username'] ?? $ig['name'] ?? null;
                        $labelBase = $username ?: $ig['id'];
                        $options = [];

                        $options[$ig['id']] = $this->formatInstagramLabel($labelBase, $ig['id'], $ig['ig_id'] ?? null, 'page_id');

                        return $options;
                    })
                    ->all();
            }

            return $results;
        });
    }

    public function fetchPixels(string $accessToken, int $userId, string $adAccountId, array $context = []): array
    {
        if (!$adAccountId) {
            return [];
        }

        $cacheKey = $this->cacheKey($userId, 'pixels:' . $this->stripActPrefix($adAccountId));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($accessToken, $adAccountId, $context) {
            $stepContext = $this->withStep($context, 'fetch_pixels');
            $this->logMeta('debug', 'MetaAdsService fetch pixels', $stepContext);

            $response = $this->client->get(sprintf('%s/adspixels', $this->formatAdAccountId($adAccountId)), $accessToken, [
                'fields' => 'id,name',
            ], $stepContext);

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
        Cache::forget($this->cacheKey($userId, 'instagram_accounts:all'));
    }

    public function forgetInstagramAccountsCacheForUser(int $userId, ?string $adAccountId): void
    {
        if (!$adAccountId) {
            return;
        }

        Cache::forget($this->cacheKey($userId, 'instagram_accounts:' . $this->stripActPrefix($adAccountId)));
    }

    public function forgetPixelsCacheForUser(int $userId, ?string $adAccountId): void
    {
        if (!$adAccountId) {
            return;
        }

        Cache::forget($this->cacheKey($userId, 'pixels:' . $this->stripActPrefix($adAccountId)));
    }

    public function findCityKey(string $accessToken, string $cityName, ?string $stateName = null, array $context = []): ?string
    {
        $stepContext = $this->withStep($context, 'find_city_key');
        $this->logMeta('info', 'MetaAdsService find city key', array_merge($stepContext, [
            'city' => $cityName,
            'state' => $stateName,
        ]));

        $response = $this->client->get('search', $accessToken, [
            'type' => 'adgeolocation',
            'location_types' => 'city',
            'q' => $cityName,
        ], $stepContext);

        $fallback = null;

        foreach (Arr::get($response, 'data', []) as $entry) {
            if (($entry['type'] ?? null) !== 'city' || ($entry['country_code'] ?? null) !== 'BR') {
                continue;
            }

            if ($stateName && isset($entry['region']) && Str::lower($entry['region']) !== Str::lower($stateName)) {
                $fallback ??= $entry['key'] ?? null;
                continue;
            }

            $this->logMeta('info', 'MetaAdsService find city key result', array_merge($stepContext, [
                'city_key' => $entry['key'] ?? null,
            ]));

            return $entry['key'] ?? null;
        }

        if ($fallback) {
            $this->logMeta('info', 'MetaAdsService find city key fallback', array_merge($stepContext, [
                'city_key' => $fallback,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService find city key failed', $stepContext);
        }

        return $fallback;
    }

    public function createCampaign(
        string $accessToken,
        string $adAccountId,
        string $objective,
        string $name,
        string $status,
        array $context = []
    ): ?string {
        $stepContext = $this->withStep($context, 'create_campaign');
        $this->logMeta('info', 'MetaAdsService create campaign', array_merge($stepContext, [
            'objective' => $objective,
            'name' => $name,
            'status' => $status,
        ]));

        $response = $this->client->post(sprintf('%s/campaigns', $this->formatAdAccountId($adAccountId)), $accessToken, [
            'name' => $name,
            'objective' => $objective,
            'status' => $status,
            'special_ad_categories' => json_encode([]),
            'is_adset_budget_sharing_enabled' => false,
        ], $stepContext);

        $id = $response['id'] ?? null;
        if ($id) {
            $this->logMeta('info', 'MetaAdsService create campaign result', array_merge($stepContext, [
                'campaign_id' => $id,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService create campaign failed', $stepContext);
        }

        return $id;
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
        string $status,
        array $context = []
    ): ?string {
        $stepContext = $this->withStep($context, 'create_ad_set');
        $this->logMeta('info', 'MetaAdsService create ad set', array_merge($stepContext, [
            'campaign_id' => $campaignId,
            'name' => $name,
            'city_key' => $cityKey,
        ]));

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
        ], $stepContext);

        $id = $response['id'] ?? null;
        if ($id) {
            $this->logMeta('info', 'MetaAdsService create ad set result', array_merge($stepContext, [
                'ad_set_id' => $id,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService create ad set failed', $stepContext);
        }

        return $id;
    }

    public function uploadImage(string $accessToken, string $adAccountId, string $filePath, array $context = []): ?string
    {
        $stepContext = $this->withStep($context, 'upload_image');
        $this->logMeta('info', 'MetaAdsService upload image', array_merge($stepContext, [
            'file' => basename($filePath),
        ]));

        $response = $this->client->postWithFile(
            sprintf('%s/adimages', $this->formatAdAccountId($adAccountId)),
            $accessToken,
            'filename',
            $filePath,
            [],
            $stepContext
        );

        $images = $response['images'] ?? [];
        if (!$images) {
            $this->logMeta('warning', 'MetaAdsService upload image failed', $stepContext);
            return null;
        }

        $first = array_values($images)[0] ?? null;

        $hash = $first['hash'] ?? null;
        if ($hash) {
            $this->logMeta('info', 'MetaAdsService upload image result', array_merge($stepContext, [
                'image_hash' => $hash,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService upload image hash missing', $stepContext);
        }

        return $hash;
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
        string $enrollStatus,
        array $context = []
    ): ?string {
        $stepContext = $this->withStep($context, 'create_creative');
        $this->logMeta('info', 'MetaAdsService create creative', array_merge($stepContext, [
            'name' => $name,
            'enroll_status' => $enrollStatus,
        ]));

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
        ], $stepContext);

        $id = $response['id'] ?? null;
        if ($id) {
            $this->logMeta('info', 'MetaAdsService create creative result', array_merge($stepContext, [
                'creative_id' => $id,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService create creative failed', $stepContext);
        }

        return $id;
    }

    public function createAd(
        string $accessToken,
        string $adAccountId,
        string $adSetId,
        string $creativeId,
        string $name,
        string $status,
        array $context = []
    ): ?string {
        $stepContext = $this->withStep($context, 'create_ad');
        $this->logMeta('info', 'MetaAdsService create ad', array_merge($stepContext, [
            'ad_set_id' => $adSetId,
            'creative_id' => $creativeId,
            'name' => $name,
        ]));

        $response = $this->client->post(sprintf('%s/ads', $this->formatAdAccountId($adAccountId)), $accessToken, [
            'name' => $name,
            'adset_id' => $adSetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status' => $status,
        ], $stepContext);

        $id = $response['id'] ?? null;
        if ($id) {
            $this->logMeta('info', 'MetaAdsService create ad result', array_merge($stepContext, [
                'ad_id' => $id,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService create ad failed', $stepContext);
        }

        return $id;
    }

    public function fetchInstagramActorDetails(string $accessToken, string $instagramActorId, array $context = []): ?array
    {
        $stepContext = $this->withStep($context, 'fetch_instagram_actor');
        $this->logMeta('info', 'MetaAdsService fetch instagram actor details', array_merge($stepContext, [
            'instagram_actor_id' => $instagramActorId,
        ]));

        $response = $this->client->get($instagramActorId, $accessToken, [
            'fields' => 'id,ig_id,username,name,profile_picture_url,followers_count,media_count,website',
        ], $stepContext);

        $this->logMeta('info', 'MetaAdsService fetch instagram actor details result', array_merge($stepContext, [
            'instagram_actor_id' => $instagramActorId,
            'instagram' => $response,
        ]));

        return $response;
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

    private function formatInstagramLabel(string $labelBase, string $id, ?string $igId, string $primary): string
    {
        $parts = ["id: {$id}"];

        if ($igId) {
            $parts[] = "ig_id: {$igId}";
        }

        $suffix = implode(' | ', $parts);

        return sprintf('%s (%s) [%s]', $labelBase, $suffix, $primary);
    }

    private function withStep(array $context, string $step): array
    {
        return array_merge($context, ['step' => $step]);
    }

    private function logMeta(string $level, string $message, array $context = []): void
    {
        Log::channel('meta')->log($level, $message, $context);
    }
}
