<?php

namespace App\Services\WhatsApp;

interface WhatsAppServiceInterface
{
    public function sendMessage(string $phone, string $message): bool;
}