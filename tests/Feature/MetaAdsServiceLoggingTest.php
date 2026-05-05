<?php

use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaGraphClient;
use Illuminate\Support\Facades\Log;

test('create ad logs meta response when response has no id', function () {
    $client = \Mockery::mock(MetaGraphClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturn([
            'success' => true,
        ]);

    Log::shouldReceive('channel')->with('meta')->twice()->andReturnSelf();
    Log::shouldReceive('log')->once()->with('info', 'MetaAdsService create ad', \Mockery::type('array'));
    Log::shouldReceive('log')
        ->once()
        ->with('warning', 'MetaAdsService create ad failed', \Mockery::on(function (array $context): bool {
            return ($context['expected_field'] ?? null) === 'id'
                && ($context['meta_response']['success'] ?? null) === true;
        }));

    $service = new MetaAdsService($client);

    expect($service->createAd(
        'token',
        '123',
        'adset_123',
        'creative_123',
        'Ad',
        'PAUSED',
        ['batch_id' => 1]
    ))->toBeNull();
});
