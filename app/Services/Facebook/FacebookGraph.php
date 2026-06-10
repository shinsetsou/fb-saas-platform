<?php

namespace App\Services\Facebook;

interface FacebookGraph
{
    public function exchangeForLongLivedToken(string $userToken): string;

    /** @return array<int, array{id: string, name: string, access_token: string}> */
    public function pages(string $userToken): array;

    public function subscribeApp(string $pageId, string $pageToken): void;

    public function sendMessage(string $pageId, string $pageToken, string $recipientId, string $text): void;
}
