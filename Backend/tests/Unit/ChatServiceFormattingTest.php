<?php

namespace Tests\Unit;

use App\Services\Chat\ChatService;
use Tests\TestCase;

class ChatServiceFormattingTest extends TestCase
{
    public function test_user_friendly_layout_removes_chunk_style_phrases(): void
    {
        $service = $this->app->make(ChatService::class);

        $method = new \ReflectionMethod(ChatService::class, 'improveUserFriendlyLayout');
        $method->setAccessible(true);

        $reply = "I looked through the context and found information about the restart process.\n\n- Chunk 1: restart steps\n- Chunk 2: additional note";
        $result = $method->invoke($service, $reply, 'bagaimana cara restart vm');
        $normalized = mb_strtolower($result);

        $this->assertStringNotContainsString('chunk', $normalized);
        $this->assertStringNotContainsString('i looked', $normalized);
        $this->assertStringContainsString('berikut langkah', $normalized);
    }
}
