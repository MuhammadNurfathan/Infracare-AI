<?php

namespace App\Services\WhatsApp;

class WhatsAppService implements WhatsAppServiceInterface
{
    public function sendMessage(string $phone, string $message): bool
    {
        return true;
    }
}