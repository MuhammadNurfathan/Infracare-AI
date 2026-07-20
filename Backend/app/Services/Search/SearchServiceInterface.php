<?php

namespace App\Services\Search;

use Illuminate\Support\Collection;

interface SearchServiceInterface
{
    public function search(string $question): Collection;
}