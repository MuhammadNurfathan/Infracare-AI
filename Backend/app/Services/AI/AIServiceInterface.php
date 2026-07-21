<?php

namespace App\Services\AI;

interface AIServiceInterface
{
    /**
     * Generates an AI answer from the given context.
     *
     * @return string|null The generated answer, or null if no genuine answer
     *                      could be produced (missing config, API error,
     *                      empty/blocked reply, or exception). Callers should
     *                      treat null as "fall back to another source", not
     *                      pattern-match a magic string.
     */
    public function generateResponse(string $question, string $context): ?string;
}