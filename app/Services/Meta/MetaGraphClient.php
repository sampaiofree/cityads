<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MetaGraphClient
{
    public function __construct(
        private readonly string $version = ''
    ) {
    }

    public function get(string $endpoint, string $accessToken, array $query = []): array
    {
        $response = Http::get($this->graphUrl($endpoint), array_merge($query, [
            'access_token' => $accessToken,
        ]));

        return $this->decode($response);
    }

    public function post(string $endpoint, string $accessToken, array $data = []): array
    {
        $response = Http::asForm()->post($this->graphUrl($endpoint), array_merge($data, [
            'access_token' => $accessToken,
        ]));

        return $this->decode($response);
    }

    public function postWithFile(string $endpoint, string $accessToken, string $field, string $filePath, array $data = []): array
    {
        $response = Http::attach($field, file_get_contents($filePath), basename($filePath))
            ->post($this->graphUrl($endpoint), array_merge($data, [
                'access_token' => $accessToken,
            ]));

        return $this->decode($response);
    }

    public function exchangeCodeForToken(string $code, string $redirectUri, ?string $appId = null, ?string $appSecret = null): array
    {
        $appId ??= config('meta.app_id');
        $appSecret ??= config('meta.app_secret');

        $response = Http::get($this->graphUrl('oauth/access_token'), [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        return $this->decode($response);
    }

    public function extendAccessToken(string $shortLivedToken, ?string $appId = null, ?string $appSecret = null): array
    {
        $appId ??= config('meta.app_id');
        $appSecret ??= config('meta.app_secret');

        $response = Http::get($this->graphUrl('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        return $this->decode($response);
    }

    private function graphUrl(string $endpoint): string
    {
        $version = $this->version ?: config('meta.graph_version', 'v20.0');

        return sprintf('https://graph.facebook.com/%s/%s', $version, ltrim($endpoint, '/'));
    }

    private function decode(Response $response): array
    {
        $response->throw();

        return $response->json() ?? [];
    }
}
