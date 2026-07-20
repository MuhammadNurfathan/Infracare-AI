<?php

namespace Tests\Unit;

use App\Services\Intent\ServiceIntent;
use PHPUnit\Framework\TestCase;

class ServiceIntentTest extends TestCase
{
    public function test_detects_support_request_as_admin_intent(): void
    {
        $service = new ServiceIntent();

        $this->assertSame('admin', $service->detect('saya ingin menghubungi tim support sekarang'));
        $this->assertSame('admin', $service->detect('bisa chat dengan customer support'));
    }

    public function test_detects_greetings_and_thanks(): void
    {
        $service = new ServiceIntent();

        $this->assertSame('greeting', $service->detect('halo saya butuh bantuan'));
        $this->assertSame('thanks', $service->detect('terima kasih banyak'));
    }
}
