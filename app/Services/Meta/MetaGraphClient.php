<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MetaGraphClient
{
    private const MAX_BODY_LENGTH = 10000;
    private const SENSITIVE_KEYS = [
        'access_token',
        'client_secret',
        'fb_exchange_token',
        'appsecret_proof',
        'app_secret',
    ];

    public function __construct(
        private readonly string $version = ''
    ) {
    }

    public function get(string $endpoint, string $accessToken, array $query = [], array $context = []): array
    {
        $url = $this->graphUrl($endpoint);
        $payload = array_merge($query, [
            'access_token' => $accessToken,
        ]);

        return $this->request('GET', $url, $payload, $context, fn ($request) => $request->get($url, $payload));
    }

    public function post(string $endpoint, string $accessToken, array $data = [], array $context = []): array
    {
        $url = $this->graphUrl($endpoint);
        $payload = array_merge($data, [
            'access_token' => $accessToken,
        ]);

        return $this->request('POST', $url, $payload, $context, fn ($request) => $request->asForm()->post($url, $payload));
    }

    public function postWithFile(
        string $endpoint,
        string $accessToken,
        string $field,
        string $filePath,
        array $data = [],
        array $context = []
    ): array {
        $url = $this->graphUrl($endpoint);
        $payload = array_merge($data, [
            'access_token' => $accessToken,
        ]);

        $fileMeta = [
            'field' => $field,
            'filename' => basename($filePath),
            'size' => is_file($filePath) ? filesize($filePath) : null,
        ];

        return $this->request(
            'POST',
            $url,
            $payload,
            $context,
            fn ($request) => $request
                ->attach($field, file_get_contents($filePath), basename($filePath))
                ->post($url, $payload),
            $fileMeta
        );
    }

    public function exchangeCodeForToken(string $code, string $redirectUri, ?string $appId = null, ?string $appSecret = null, array $context = []): array
    {
        $appId ??= config('meta.app_id');
        $appSecret ??= config('meta.app_secret');

        $url = $this->graphUrl('oauth/access_token');
        $payload = [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ];

        return $this->request('GET', $url, $payload, $context, fn ($request) => $request->get($url, $payload));
    }

    public function extendAccessToken(
        string $shortLivedToken,
        ?string $appId = null,
        ?string $appSecret = null,
        array $context = []
    ): array {
        $appId ??= config('meta.app_id');
        $appSecret ??= config('meta.app_secret');

        $url = $this->graphUrl('oauth/access_token');
        $payload = [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ];

        return $this->request('GET', $url, $payload, $context, fn ($request) => $request->get($url, $payload));
    }

    private function graphUrl(string $endpoint): string
    {
        $version = $this->version ?: config('meta.graph_version', 'v20.0');

        return sprintf('https://graph.facebook.com/%s/%s', $version, ltrim($endpoint, '/'));
    }

    private function decode(Response $response): array
    {
        $payload = $response->json();

        if ($response->failed()) {
            $error = is_array($payload) ? Arr::get($payload, 'error') : null;
            $message = $this->formatErrorMessage($error, $response->status());
            throw new \RuntimeException($message);
        }

        return $payload ?? [];
    }

    private function withVerify(): \Illuminate\Http\Client\PendingRequest
    {
        $verify = config('meta.curl_verify', env('META_CURL_VERIFY'));

        $options = array_filter([
            'verify' => $verify,
        ]);

        return Http::withOptions($options);
    }

    private function request(string $method, string $url, array $payload, array $context, callable $sender, ?array $fileMeta = null): array
    {
        $logger = Log::channel('meta');
        $baseContext = array_merge($context, [
            'method' => $method,
            'url' => $url,
            'payload' => $this->sanitizePayload($payload),
        ]);

        if ($fileMeta) {
            $baseContext['file'] = $fileMeta;
        }

        $logger->debug('MetaGraph request', $baseContext);

        $start = microtime(true);

        try {
            $response = $sender($this->withVerify());
        } catch (Throwable $exception) {
            $logger->error('MetaGraph transport error', array_merge($baseContext, [
                'exception' => $exception->getMessage(),
            ]));
            throw $exception;
        }

        $this->logResponse($logger, $response, $start, $baseContext);

        return $this->decode($response);
    }

    private function logResponse($logger, Response $response, float $start, array $context): void
    {
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $body = $response->body();
        $payload = $response->json();
        $error = is_array($payload) ? Arr::get($payload, 'error') : null;

        $logger->debug('MetaGraph response', array_merge($context, [
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'ok' => $response->ok(),
            'graph_error' => $error ? $this->sanitizePayload($error) : null,
            'response' => $this->truncateBody($body),
        ]));
    }

    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = $this->maskValue($value);
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function maskValue($value): string
    {
        if (!is_string($value)) {
            return '***';
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 6) . '...' . substr($value, -4);
    }

    private function truncateBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return Str::limit($body, self::MAX_BODY_LENGTH, '...(truncated)');
    }

    private function formatErrorMessage($error, int $status): string
    {
        if (!is_array($error) || empty($error)) {
            return sprintf('Meta API request failed with status %d.', $status);
        }

        $code = $error['code'] ?? $status;
        $message = $error['message'] ?? 'Erro desconhecido.';
        $subcode = $error['error_subcode'] ?? null;
        $userMessage = $error['error_user_msg'] ?? null;

        $detail = $subcode ? sprintf(' subcode %s', $subcode) : '';
        $formatted = sprintf('Meta API error (%s%s): %s', $code, $detail, $message);

        if ($userMessage) {
            $formatted .= sprintf(' (%s)', $userMessage);
        }

        return $formatted;
    }
}
