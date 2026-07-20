<?php

namespace Tests\Unit;

use App\Services\Chat\ChatService;
use App\Services\Chat\ChatServiceInterface;
use Tests\TestCase;

class ChatServiceTest extends TestCase
{
    public function test_chat_service_can_be_resolved_from_container(): void
    {
        $service = $this->app->make(ChatServiceInterface::class);

        $this->assertInstanceOf(ChatService::class, $service);
    }
}
