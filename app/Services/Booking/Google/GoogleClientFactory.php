<?php

namespace App\Services\Booking\Google;

use App\Models\GoogleAccount;
use Carbon\Carbon;
use Google\Client;

class GoogleClientFactory
{
    public function for(GoogleAccount $account): Client
    {
        $client = new Client();
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->expires_at
                ? max(0, now()->diffInSeconds($account->expires_at, false))
                : 0,
            'created' => now()->timestamp,
        ]);

        if ($client->isAccessTokenExpired() && $account->refresh_token) {
            $new = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            if (! isset($new['error'])) {
                $account->update([
                    'access_token' => $new['access_token'],
                    'expires_at' => Carbon::now()->addSeconds((int) ($new['expires_in'] ?? 3600)),
                ]);
            }
        }

        return $client;
    }
}
