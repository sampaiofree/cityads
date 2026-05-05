<?php

use App\Services\Meta\MetaGraphClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('failed graph responses are logged with meta error details above debug level', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid ad creative',
                'type' => 'OAuthException',
                'code' => 100,
                'error_subcode' => 1885316,
                'fbtrace_id' => 'trace123',
            ],
        ], 400),
    ]);

    Log::shouldReceive('channel')->with('meta')->andReturnSelf();
    Log::shouldReceive('debug')->twice();
    Log::shouldReceive('warning')
        ->once()
        ->with('MetaGraph failed response', \Mockery::on(function (array $context): bool {
            return ($context['status'] ?? null) === 400
                && ($context['graph_error']['message'] ?? null) === 'Invalid ad creative'
                && ($context['graph_error']['code'] ?? null) === 100
                && ($context['graph_error']['error_subcode'] ?? null) === 1885316
                && str_contains($context['error_message'] ?? '', 'Meta API error (100 subcode 1885316)');
        }));

    $client = new MetaGraphClient('v20.0');

    expect(fn () => $client->post('act_123/ads', 'token', [
        'name' => 'Ad',
    ], [
        'step' => 'create_ad',
    ]))->toThrow(RuntimeException::class, 'Meta API error (100 subcode 1885316)');
});

test('successful http responses with graph error payload are treated as failures', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Creative is not valid',
                'code' => 100,
            ],
        ], 200),
    ]);

    Log::shouldReceive('channel')->with('meta')->andReturnSelf();
    Log::shouldReceive('debug')->twice();
    Log::shouldReceive('warning')
        ->once()
        ->with('MetaGraph failed response', \Mockery::on(function (array $context): bool {
            return ($context['status'] ?? null) === 200
                && ($context['graph_error']['message'] ?? null) === 'Creative is not valid'
                && str_contains($context['error_message'] ?? '', 'Creative is not valid');
        }));

    $client = new MetaGraphClient('v20.0');

    expect(fn () => $client->post('act_123/ads', 'token', [
        'name' => 'Ad',
    ], [
        'step' => 'create_ad',
    ]))->toThrow(RuntimeException::class, 'Creative is not valid');
});
