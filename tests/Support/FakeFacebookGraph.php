<?php

namespace Tests\Support;

use App\Services\Facebook\FacebookGraph;

class FakeFacebookGraph implements FacebookGraph
{
    /** @var array<int, array{id: string, name: string, access_token: string}> */
    public array $pages;

    public ?string $exchangedFrom = null;

    /** @var array<int, array{page_id: string, page_token: string}> */
    public array $subscribed = [];

    /** @var array<int, array{page_id: string, page_token: string, recipient_id: string, text: string}> */
    public array $sent = [];

    /** @param array<int, array{id: string, name: string, access_token: string}> $pages */
    public function __construct(array $pages = [['id' => '111', 'name' => 'Test Page', 'access_token' => 'page-token-111']])
    {
        $this->pages = $pages;
    }

    public function exchangeForLongLivedToken(string $userToken): string
    {
        $this->exchangedFrom = $userToken;

        return 'long-lived-'.$userToken;
    }

    public function pages(string $userToken): array
    {
        return $this->pages;
    }

    public function subscribeApp(string $pageId, string $pageToken): void
    {
        $this->subscribed[] = ['page_id' => $pageId, 'page_token' => $pageToken];
    }

    public function sendMessage(string $pageId, string $pageToken, string $recipientId, string $text): void
    {
        $this->sent[] = [
            'page_id' => $pageId,
            'page_token' => $pageToken,
            'recipient_id' => $recipientId,
            'text' => $text,
        ];
    }
}
