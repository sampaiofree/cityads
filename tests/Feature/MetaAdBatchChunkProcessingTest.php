<?php

use App\Jobs\ProcessMetaAdBatch;
use App\Jobs\ProcessMetaAdBatchChunk;
use App\Models\City;
use App\Models\MetaAdBatch;
use App\Models\MetaAdBatchItem;
use App\Models\MetaConnection;
use App\Models\User;
use App\Services\Meta\MetaAdBatchProcessor;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function createChunkProcessingUser(): User
{
    $user = User::factory()->create();

    MetaConnection::query()->create([
        'user_id' => $user->id,
        'access_token' => 'token',
        'ad_account_id' => '123456',
        'page_id' => 'page_123',
        'pixel_id' => 'pixel_123',
    ]);

    return $user;
}

function createChunkProcessingBatch(User $user, array $overrides = []): MetaAdBatch
{
    return MetaAdBatch::query()->create(array_merge([
        'user_id' => $user->id,
        'objective' => 'OUTCOME_AWARENESS',
        'destination_type' => 'WEBSITE',
        'ad_account_id' => '123456',
        'page_id' => 'page_123',
        'pixel_id' => 'pixel_123',
        'url_template' => 'https://example.com/{cidade}',
        'title_template' => 'Titulo {cidade}',
        'body_template' => 'Texto {cidade}',
        'status' => 'processing',
        'meta_campaign_id' => 'campaign_123',
        'settings' => [
            'state' => 'Goias',
            'creative_source_mode' => 'existing_post',
            'existing_post_id' => '123_456',
        ],
    ], $overrides));
}

function bindMetaAdsServiceMock(): \Mockery\MockInterface
{
    $mock = \Mockery::mock(MetaAdsService::class);
    app()->instance(MetaAdsService::class, $mock);

    return $mock;
}

test('preparer creates city items and dispatches chunks of twenty five', function () {
    Queue::fake();

    $user = createChunkProcessingUser();
    $batch = createChunkProcessingBatch($user, [
        'status' => 'queued',
        'meta_campaign_id' => null,
    ]);

    foreach (range(1, 60) as $index) {
        City::query()->create([
            'name' => sprintf('Cidade %02d', $index),
            'state' => 'Goias',
            'state_code' => 'GO',
        ]);
    }

    $mock = bindMetaAdsServiceMock();
    $mock->shouldReceive('createCampaign')->once()->andReturn('campaign_123');

    (new ProcessMetaAdBatch($batch->id))->handle(app(MetaAdBatchProcessor::class));

    $batch->refresh();

    expect($batch->total_items)->toBe(60)
        ->and($batch->items()->where('status', 'pending')->count())->toBe(60)
        ->and($batch->meta_campaign_id)->toBe('campaign_123');

    Queue::assertPushed(ProcessMetaAdBatchChunk::class, 3);
    Queue::assertPushed(ProcessMetaAdBatchChunk::class, fn (ProcessMetaAdBatchChunk $job) => count($job->itemIds) === 25);
    Queue::assertPushed(ProcessMetaAdBatchChunk::class, fn (ProcessMetaAdBatchChunk $job) => count($job->itemIds) === 10);
    Queue::assertPushed(ProcessMetaAdBatchChunk::class, fn (ProcessMetaAdBatchChunk $job) => $job->queue === 'default');
});

test('preparer uploads single video once and stores it on batch settings', function () {
    Queue::fake();
    Storage::fake('public');
    Storage::disk('public')->put('meta_ads/source/video.mp4', 'video');

    $user = createChunkProcessingUser();
    $batch = createChunkProcessingBatch($user, [
        'status' => 'queued',
        'meta_campaign_id' => null,
        'image_path' => 'meta_ads/source/video.mp4',
        'settings' => [
            'state' => 'Goias',
            'creative_source_mode' => 'single_media',
            'creative_media_type' => 'video',
        ],
    ]);

    foreach (range(1, 2) as $index) {
        City::query()->create([
            'name' => sprintf('Cidade Video %02d', $index),
            'state' => 'Goias',
            'state_code' => 'GO',
        ]);
    }

    $mock = bindMetaAdsServiceMock();
    $mock->shouldReceive('createCampaign')->once()->andReturn('campaign_video');
    $mock->shouldReceive('uploadVideo')->once()->andReturn('video_123');

    (new ProcessMetaAdBatch($batch->id))->handle(app(MetaAdBatchProcessor::class));

    $batch->refresh();

    expect($batch->settings['video_id'])->toBe('video_123')
        ->and($batch->items()->count())->toBe(2);

    Queue::assertPushed(ProcessMetaAdBatchChunk::class, 1);
});

test('chunk processes pending item and updates counters once', function () {
    $user = createChunkProcessingUser();
    $batch = createChunkProcessingBatch($user);
    $item = MetaAdBatchItem::query()->create([
        'meta_ad_batch_id' => $batch->id,
        'city_name' => 'Goiania',
        'state_name' => 'Goias',
        'meta_city_key' => 'city_key_1',
        'status' => 'pending',
    ]);

    $mock = bindMetaAdsServiceMock();
    $mock->shouldReceive('createAdSet')->once()->andReturn('adset_1');
    $mock->shouldReceive('createExistingPostCreative')->once()->andReturn('creative_1');
    $mock->shouldReceive('createAd')->once()->andReturn('ad_1');

    app(MetaAdBatchProcessor::class)->processChunk($batch->id, [$item->id]);

    $item->refresh();
    $batch->refresh();

    expect($item->status)->toBe('success')
        ->and($item->ad_set_id)->toBe('adset_1')
        ->and($item->ad_creative_id)->toBe('creative_1')
        ->and($item->ad_id)->toBe('ad_1')
        ->and($batch->processed_items)->toBe(1)
        ->and($batch->success_count)->toBe(1)
        ->and($batch->error_count)->toBe(0)
        ->and($batch->status)->toBe('completed');
});

test('chunk caches city key on city and item after lookup', function () {
    $user = createChunkProcessingUser();
    $batch = createChunkProcessingBatch($user);
    $city = City::query()->create([
        'name' => 'Goiania',
        'state' => 'Goias',
        'state_code' => 'GO',
    ]);
    $item = MetaAdBatchItem::query()->create([
        'meta_ad_batch_id' => $batch->id,
        'city_id' => $city->id,
        'city_name' => 'Goiania',
        'state_name' => 'Goias',
        'status' => 'pending',
    ]);

    $mock = bindMetaAdsServiceMock();
    $mock->shouldReceive('findCityKey')->once()->andReturn('city_key_cached');
    $mock->shouldReceive('createAdSet')->once()->andReturn('adset_cached');
    $mock->shouldReceive('createExistingPostCreative')->once()->andReturn('creative_cached');
    $mock->shouldReceive('createAd')->once()->andReturn('ad_cached');

    app(MetaAdBatchProcessor::class)->processChunk($batch->id, [$item->id]);

    expect($item->fresh()->meta_city_key)->toBe('city_key_cached')
        ->and($city->fresh()->meta_city_key)->toBe('city_key_cached');
});

test('chunk resumes from saved ad set and creative without recreating them', function () {
    $user = createChunkProcessingUser();
    $batch = createChunkProcessingBatch($user);
    $item = MetaAdBatchItem::query()->create([
        'meta_ad_batch_id' => $batch->id,
        'city_name' => 'Anapolis',
        'state_name' => 'Goias',
        'meta_city_key' => 'city_key_2',
        'ad_set_id' => 'adset_existing',
        'ad_creative_id' => 'creative_existing',
        'status' => 'processing',
    ]);

    $mock = bindMetaAdsServiceMock();
    $mock->shouldNotReceive('createAdSet');
    $mock->shouldNotReceive('createExistingPostCreative');
    $mock->shouldReceive('createAd')->once()->andReturn('ad_resumed');

    app(MetaAdBatchProcessor::class)->processChunk($batch->id, [$item->id]);

    $item->refresh();
    $batch->refresh();

    expect($item->status)->toBe('success')
        ->and($item->ad_set_id)->toBe('adset_existing')
        ->and($item->ad_creative_id)->toBe('creative_existing')
        ->and($item->ad_id)->toBe('ad_resumed')
        ->and($batch->processed_items)->toBe(1)
        ->and($batch->success_count)->toBe(1);
});

test('last chunk marks batch completed with errors when any item failed', function () {
    $user = createChunkProcessingUser();
    $batch = createChunkProcessingBatch($user);

    MetaAdBatchItem::query()->create([
        'meta_ad_batch_id' => $batch->id,
        'city_name' => 'Cidade com erro',
        'state_name' => 'Goias',
        'status' => 'error',
        'error_message' => 'Falha anterior',
    ]);

    $item = MetaAdBatchItem::query()->create([
        'meta_ad_batch_id' => $batch->id,
        'city_name' => 'Cidade ok',
        'state_name' => 'Goias',
        'meta_city_key' => 'city_key_3',
        'status' => 'pending',
    ]);

    $mock = bindMetaAdsServiceMock();
    $mock->shouldReceive('createAdSet')->once()->andReturn('adset_3');
    $mock->shouldReceive('createExistingPostCreative')->once()->andReturn('creative_3');
    $mock->shouldReceive('createAd')->once()->andReturn('ad_3');

    app(MetaAdBatchProcessor::class)->processChunk($batch->id, [$item->id]);

    $batch->refresh();

    expect($batch->processed_items)->toBe(2)
        ->and($batch->success_count)->toBe(1)
        ->and($batch->error_count)->toBe(1)
        ->and($batch->status)->toBe('completed_with_errors');
});

test('chunk cancellation stops before starting pending items', function () {
    $user = createChunkProcessingUser();
    $batch = createChunkProcessingBatch($user, [
        'status' => 'cancel_requested',
        'cancel_requested_at' => now(),
    ]);
    $item = MetaAdBatchItem::query()->create([
        'meta_ad_batch_id' => $batch->id,
        'city_name' => 'Goiania',
        'state_name' => 'Goias',
        'meta_city_key' => 'city_key_4',
        'status' => 'pending',
    ]);

    $mock = bindMetaAdsServiceMock();
    $mock->shouldNotReceive('createAdSet');
    $mock->shouldNotReceive('createExistingPostCreative');
    $mock->shouldNotReceive('createAd');

    app(MetaAdBatchProcessor::class)->processChunk($batch->id, [$item->id]);

    expect($batch->fresh()->status)->toBe('cancelled')
        ->and($item->fresh()->status)->toBe('pending');
});
