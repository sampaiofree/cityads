<?php

namespace App\Http\Controllers;

use App\Models\MetaConnection;
use App\Services\Meta\MetaGraphClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MetaOAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);
        $request->session()->put('meta_oauth_popup', $request->boolean('popup'));

        $redirectUri = config('meta.redirect_uri') ?: route('meta.callback');
        [$appId, $appSecret] = $this->resolveAppCredentials($request);

        if (!$appId || !$appSecret) {
            abort(422, 'Configure o App ID e App Secret antes de conectar.');
        }

        $query = http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => config('meta.oauth_scopes'),
            'response_type' => 'code',
        ]);

        $version = config('meta.graph_version', 'v20.0');
        $url = sprintf('https://www.facebook.com/%s/dialog/oauth?%s', $version, $query);

        return redirect()->away($url);
    }

    public function callback(Request $request, MetaGraphClient $client)
    {
        $state = $request->session()->pull('meta_oauth_state');
        if (!$state || $state !== $request->input('state')) {
            abort(403);
        }

        $code = $request->input('code');
        if (!$code) {
            return redirect()->to('/dashboard/meta-ads');
        }

        $redirectUri = config('meta.redirect_uri') ?: route('meta.callback');
        [$appId, $appSecret] = $this->resolveAppCredentials($request);

        if (!$appId || !$appSecret) {
            abort(422, 'Configure o App ID e App Secret antes de conectar.');
        }

        $tokenResponse = $client->exchangeCodeForToken($code, $redirectUri, $appId, $appSecret);

        $accessToken = $tokenResponse['access_token'] ?? null;
        $expiresIn = $tokenResponse['expires_in'] ?? null;

        if ($accessToken) {
            $longLived = $client->extendAccessToken($accessToken, $appId, $appSecret);
            $accessToken = $longLived['access_token'] ?? $accessToken;
            $expiresIn = $longLived['expires_in'] ?? $expiresIn;
        }

        MetaConnection::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'access_token' => $accessToken,
                'token_expires_at' => $expiresIn ? now()->addSeconds((int) $expiresIn) : null,
            ]
        );

        if ($request->session()->pull('meta_oauth_popup')) {
            return view('meta.oauth-popup-close');
        }

        return redirect()->to('/dashboard/meta-ads');
    }

    public function disconnect(Request $request)
    {
        $connection = $request->user()->metaConnection;
        if ($connection) {
            $connection->update([
                'access_token' => null,
                'token_expires_at' => null,
            ]);
        }

        return redirect()->to('/dashboard/meta-ads');
    }

    private function resolveAppCredentials(Request $request): array
    {
        $connection = $request->user()?->metaConnection;

        return [
            $connection?->app_id ?: config('meta.app_id'),
            $connection?->app_secret ?: config('meta.app_secret'),
        ];
    }
}
