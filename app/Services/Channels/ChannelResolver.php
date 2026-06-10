<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Services\Facebook\FacebookMessengerChannel;
use Illuminate\Contracts\Container\Container;

class ChannelResolver
{
    public function __construct(private Container $container) {}

    public function for(Conversation $conversation): ConversationChannel
    {
        return match ($conversation->channel) {
            'facebook' => $this->container->make(FacebookMessengerChannel::class),
            default => $this->container->make(WebChatChannel::class),
        };
    }
}
