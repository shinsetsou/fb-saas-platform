<?php

namespace App\Services\Bot\Tools;

use App\Models\Conversation;
use App\Models\Workspace;

class ToolContext
{
    public function __construct(
        public Workspace $workspace,
        public Conversation $conversation,
    ) {
    }
}
