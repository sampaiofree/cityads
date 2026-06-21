<?php

namespace App\Services\PortalJe;

use App\Exceptions\PortalJeUnavailableException;
use App\Exceptions\PortalJeUserNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PortalJeUserClient
{
    /**
     * @return array{name: string, email: string, dominio: string}
     */
    public function findByEmail(string $email): array
    {
        $url = rtrim((string) config('services.portalje.url'), '/')
            .'/buscar_user/'
            .rawurlencode($email);

        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::warning('Portal JE user lookup connection failed.', [
                'exception' => $exception::class,
            ]);

            throw new PortalJeUnavailableException(previous: $exception);
        }

        if ($response->status() === 404) {
            throw new PortalJeUserNotFoundException;
        }

        if (! $response->successful()) {
            Log::warning('Portal JE user lookup returned an unsuccessful response.', [
                'status' => $response->status(),
            ]);

            throw new PortalJeUnavailableException;
        }

        try {
            $payload = $response->json();
        } catch (Throwable $exception) {
            Log::warning('Portal JE user lookup returned invalid JSON.', [
                'exception' => $exception::class,
            ]);

            throw new PortalJeUnavailableException(previous: $exception);
        }

        if (! is_array($payload)) {
            $this->throwInvalidPayload();
        }

        $name = $this->nonEmptyString($payload['name'] ?? null);
        $returnedEmail = $this->nonEmptyString($payload['email'] ?? null);
        $externalDomain = $this->nonEmptyString($payload['dominio_externo'] ?? null);
        $domain = $externalDomain ?? $this->nonEmptyString($payload['dominio'] ?? null);

        if ($name === null || $returnedEmail === null || $domain === null
            || filter_var($returnedEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->throwInvalidPayload();
        }

        return [
            'name' => $name,
            'email' => $returnedEmail,
            'dominio' => $domain,
        ];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function throwInvalidPayload(): never
    {
        Log::warning('Portal JE user lookup returned an invalid payload.');

        throw new PortalJeUnavailableException;
    }
}
