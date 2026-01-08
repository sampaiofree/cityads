<?php

namespace App\Http\Controllers;

use App\Models\MetaConnection;
use App\Services\Meta\MetaGraphClient;
use Illuminate\Http\Request;
use Throwable;

class MetaSdkController extends Controller
{
    public function storeToken(Request $request, MetaGraphClient $client)
    {
        $data = $request->validate([
            'accessToken' => ['required', 'string'],
            'expiresIn' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $connection = MetaConnection::firstOrCreate(['user_id' => $user->id]);

        if (!$connection->app_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'App ID nao configurado.',
            ], 422);
        }

        $accessToken = $data['accessToken'];
        $expiresIn = $data['expiresIn'] ?? null;

        if ($connection->app_secret) {
            try {
                $longLived = $client->extendAccessToken($accessToken, $connection->app_id, $connection->app_secret);
                $accessToken = $longLived['access_token'] ?? $accessToken;
                $expiresIn = $longLived['expires_in'] ?? $expiresIn;
            } catch (Throwable) {
                // Keep the short-lived token if exchange fails.
            }
        }

        $connection->update([
            'access_token' => $accessToken,
            'token_expires_at' => $expiresIn ? now()->addSeconds((int) $expiresIn) : null,
        ]);

        return response()->json(['status' => 'success']);
    }
}
