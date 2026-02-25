<?php

namespace App\Services\Meta;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
                'fields' => 'id,name',
            ], $stepContext);

            return collect(Arr::get($response, 'data', []))
                ->mapWithKeys(function (array $account) {
                    $id = $account['account_id'] ?? $account['id'] ?? null;
                    if (!$id) {
                        return [];
                    }

                    $name = $account['name'] ?? $id;

                    return [$this->stripActPrefix($id) => $name];
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

public function fetchPageWhatsAppNumbers(string $accessToken, string $pageId, array $context = []): array
{
    $stepContext = array_merge($context, ['step' => 'fetch_page_whatsapp_numbers', 'page_id' => $pageId]);
    $numbers = [];

    try {
        // Pedimos todos os campos possÃ­veis de contato da pÃ¡gina
        $pageRes = $this->client->get($pageId, $accessToken, [
            'fields' => 'whatsapp_number,name' 
        ], $stepContext);

        if (!empty($pageRes['whatsapp_number'])) {
            $num = preg_replace('/\D/', '', $pageRes['whatsapp_number']);
            $numbers[$num] = $pageRes['whatsapp_number'] . " (Principal da PÃ¡gina)";
        }
    } catch (\Throwable $e) {
        $this->logMeta('debug', 'Erro ao ler whatsapp_number da pÃ¡gina', ['msg' => $e->getMessage()]);
    }

    return $numbers;
}

/**
 * FunÃ§Ã£o auxiliar para buscar nÃºmeros de uma WABA especÃ­fica
 */
private function fetchNumbersFromWaba(array $waba, string $accessToken, array &$numbers, array $stepContext): void
{
    try {
        $phones = $this->client->get("{$waba['id']}/phone_numbers", $accessToken, ['fields' => 'display_phone_number'], $stepContext);
        foreach (Arr::get($phones, 'data', []) as $phone) {
            if (!empty($phone['display_phone_number'])) {
                $num = preg_replace('/\D/', '', $phone['display_phone_number']);
                $numbers[$num] = $phone['display_phone_number'] . " (WABA: {$waba['name']})";
            }
        }
    } catch (\Throwable) {}
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
        array $context = [],
        ?array $specialAdCategories = null
    ): ?string {
        $categories = ['NONE'];

        $stepContext = $this->withStep($context, 'create_campaign');
        $this->logMeta('info', 'MetaAdsService create campaign', array_merge($stepContext, [
            'objective' => $objective,
            'name' => $name,
            'status' => $status,
            'special_ad_categories' => $categories,
        ]));

        $response = $this->client->post(sprintf('%s/campaigns', $this->formatAdAccountId($adAccountId)), $accessToken, [
            'name' => $name,
            'objective' => $objective,
            'status' => $status,
            'special_ad_categories' => json_encode($categories),
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
        ?string $pixelId,
        ?string $conversionEvent,
        string $status,
        array $context = [],
        array $options = []
    ): ?string {
        $destinationType = $options['destination_type'] ?? null;
        $pageId = $options['page_id'] ?? null;
        $optimizationGoal = $options['optimization_goal'] ?? 'OFFSITE_CONVERSIONS';

        $stepContext = $this->withStep($context, 'create_ad_set');
        $this->logMeta('info', 'MetaAdsService create ad set', array_merge($stepContext, [
            'campaign_id' => $campaignId,
            'name' => $name,
            'city_key' => $cityKey,
            'destination_type' => $destinationType,
            'optimization_goal' => $optimizationGoal,
        ]));

        $promotedObject = [];
        if ($destinationType == 'WHATSAPP') {
            if ($pageId) {
                $promotedObject['page_id'] = $pageId;
            }
        } else {
            if ($pixelId) {
                $promotedObject['pixel_id'] = $pixelId;
            }
            if ($conversionEvent) {
                $promotedObject['custom_event_type'] = $conversionEvent;
            }
        }

        $payload = [
            'name' => $name,
            'campaign_id' => $campaignId,
            'daily_budget' => $dailyBudgetCents,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $optimizationGoal,
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode([
                'geo_locations' => [
                    'cities' => [
                        ['key' => $cityKey],
                    ],
                ],
                'device_platforms' => ['mobile'],
            ]),
            'status' => $status,
            'start_time' => $startTimeUtc,
            'is_optimized_for_quality' => false,
        ];

        if ($destinationType) {
            $payload['destination_type'] = $destinationType;
        }

        if ($promotedObject) {
            $payload['promoted_object'] = json_encode($promotedObject);
        }

        $response = $this->client->post(sprintf('%s/adsets', $this->formatAdAccountId($adAccountId)), $accessToken, $payload, $stepContext);

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

    public function uploadVideo(string $accessToken, string $adAccountId, string $filePath, array $context = []): ?string
    {
        $stepContext = $this->withStep($context, 'upload_video');
        $this->logMeta('info', 'MetaAdsService upload video', array_merge($stepContext, [
            'file' => basename($filePath),
        ]));

        $response = $this->client->postWithFile(
            sprintf('%s/advideos', $this->formatAdAccountId($adAccountId)),
            $accessToken,
            'source',
            $filePath,
            [],
            $stepContext
        );

        $videoId = $response['id'] ?? null;
        if ($videoId) {
            $this->logMeta('info', 'MetaAdsService upload video result', array_merge($stepContext, [
                'video_id' => $videoId,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService upload video failed', $stepContext);
        }

        return $videoId;
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
        array $context = [],
        array $options = []
    ): ?string {
        $stepContext = $this->withStep($context, 'create_creative');
        $this->logMeta('info', 'MetaAdsService create creative', array_merge($stepContext, [
            'name' => $name,
            'enroll_status' => $enrollStatus,
        ]));

        $destinationType = $options['destination_type'] ?? null;
        $whatsappNumber = $options['whatsapp_number'] ?? null;
        if (is_string($whatsappNumber)) {
            $whatsappNumber = preg_replace('/\D/', '', $whatsappNumber);
        }

        if ($whatsappNumber === '') {
            $whatsappNumber = null;
        }

        $payloadLink = $options['link'] ?? $url;
        if ($destinationType === 'WHATSAPP') {
            $payloadLink = sprintf('https://www.facebook.com/%s', $pageId);
        }

        $callToAction = [
            'type' => $destinationType === 'WHATSAPP' ? 'WHATSAPP_MESSAGE' : 'LEARN_MORE',
            'value' => [
                'link' => $payloadLink,
            ],
        ];

        if ($destinationType === 'WHATSAPP') {
            $callToAction['value'] = [
                'app_destination' => 'WHATSAPP',
                'link' => $payloadLink,
            ];

            if ($whatsappNumber) {
                $callToAction['value']['whatsapp_number'] = $whatsappNumber;
            }
        }

        $storySpec = [
            'page_id' => $pageId,
            'link_data' => [
                'image_hash' => $imageHash,
                'link' => $payloadLink,
                'message' => $body,
                'name' => $title,
                'description' => $body,
                'call_to_action' => $callToAction,
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

    public function createVideoCreative(
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
        array $context = [],
        array $options = []
    ): ?string {
        $stepContext = $this->withStep($context, 'create_video_creative');
        $this->logMeta('info', 'MetaAdsService create video creative', array_merge($stepContext, [
            'name' => $name,
            'video_id' => $videoId,
            'enroll_status' => $enrollStatus,
        ]));

        $destinationType = $options['destination_type'] ?? null;
        $whatsappNumber = $options['whatsapp_number'] ?? null;
        if (is_string($whatsappNumber)) {
            $whatsappNumber = preg_replace('/\D/', '', $whatsappNumber);
        }

        if ($whatsappNumber === '') {
            $whatsappNumber = null;
        }

        $payloadLink = $options['link'] ?? $url;
        if ($destinationType === 'WHATSAPP') {
            $payloadLink = sprintf('https://www.facebook.com/%s', $pageId);
        }

        $callToAction = [
            'type' => $destinationType === 'WHATSAPP' ? 'WHATSAPP_MESSAGE' : 'LEARN_MORE',
            'value' => [
                'link' => $payloadLink,
            ],
        ];

        if ($destinationType === 'WHATSAPP') {
            $callToAction['value'] = [
                'app_destination' => 'WHATSAPP',
                'link' => $payloadLink,
            ];

            if ($whatsappNumber) {
                $callToAction['value']['whatsapp_number'] = $whatsappNumber;
            }
        }

        $storySpec = [
            'page_id' => $pageId,
            'video_data' => [
                'video_id' => $videoId,
                'link' => $payloadLink,
                'message' => $body,
                'title' => $title,
                'call_to_action' => $callToAction,
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
            $this->logMeta('info', 'MetaAdsService create video creative result', array_merge($stepContext, [
                'creative_id' => $id,
            ]));
        } else {
            $this->logMeta('warning', 'MetaAdsService create video creative failed', $stepContext);
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
        return $labelBase;
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
