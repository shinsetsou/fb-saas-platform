<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Channels\ChannelResolver;
use App\Services\Channels\WebChatChannel;
use App\Services\Facebook\FacebookMessengerChannel;
use Tests\TestCase;

class ChannelResolverTest extends TestCase
{
    public function test_resolves_web_channel(): void
    {
        $conversation = new Conversation(['channel' => 'web']);

        $this->assertInstanceOf(WebChatChannel::class, app(ChannelResolver::class)->for($conversation));
    }

    public function test_resolves_facebook_channel(): void
    {
        $conversation = new Conversation(['channel' => 'facebook']);

        $this->assertInstanceOf(FacebookMessengerChannel::class, app(ChannelResolver::class)->for($conversation));
    }
}
