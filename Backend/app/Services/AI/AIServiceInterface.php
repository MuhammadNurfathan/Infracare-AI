<?php

namespace App\Services\AI;

interface AIServiceInterface
{
    public function generateResponse(
        string $question,
        string $context
    ): string;
}