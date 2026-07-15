<?php

namespace App\Services\Intent;

interface ServiceIntentInterface
{
    public function detect(string $message): string;
}