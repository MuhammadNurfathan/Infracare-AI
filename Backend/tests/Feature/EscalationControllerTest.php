<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EscalationControllerTest extends TestCase
{
    public function test_escalation_email_endpoint_returns_success(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/chat/escalate-email', [
            'phone' => '6281234567890',
            'name' => 'Test User',
            'message' => 'Escalation from test',
            'reply' => 'Please contact admin',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }
}
