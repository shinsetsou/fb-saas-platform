<?php

namespace App\Services\Facebook;

use Illuminate\Support\Facades\Http;

class HttpFacebookGraph implements FacebookGraph
{
    private function base(): string
    {
        $version = config('services.facebook.graph_version', 'v21.0');

        return "https://graph.facebook.com/{$version}";
    }

    public function exchangeForLongLivedToken(string $userToken): string
    {
        $response = Http::get($this->base().'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $userToken,
        ])->throw();

        return (string) $response->json('access_token');
    }

    public function pages(string $userToken): array
    {
        $response = Http::get($this->base().'/me/accounts', [
            'fields' => 'id,name,access_token',
            'access_token' => $userToken,
        ])->throw();

        $pages = array_filter(
            $response->json('data', []),
            fn (array $page) => ! empty($page['access_token']),
        );

        return array_values(array_map(fn (array $page) => [
            'id' => (string) $page['id'],
            'name' => (string) ($page['name'] ?? ''),
            'access_token' => (string) $page['access_token'],
        ], $pages));
    }

    public function subscribeApp(string $pageId, string $pageToken): void
    {
        Http::asForm()->post($this->base()."/{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'messages',
            'access_token' => $pageToken,
        ])->throw();
    }

    public function sendMessage(string $pageId, string $pageToken, string $recipientId, string $text): void
    {
        Http::withToken($pageToken)->post($this->base()."/{$pageId}/messages", [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $text],
            'messaging_type' => 'RESPONSE',
        ])->throw();
    }
}
