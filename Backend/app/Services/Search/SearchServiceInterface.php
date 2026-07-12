<?php

namespace App\Services\Search;

use App\Models\Document;

interface SearchServiceInterface
{
    /**
     * Mencari manual book yang paling relevan berdasarkan pertanyaan user.
     */
    public function search(string $question): ?Document;
}