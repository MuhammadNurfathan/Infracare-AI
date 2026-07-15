<?php

namespace App\Services\Chat;

interface ChatServiceInterface
{
    public function receiveMessage(array $data): string;
}