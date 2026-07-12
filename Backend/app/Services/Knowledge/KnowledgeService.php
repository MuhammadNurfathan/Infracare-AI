<?php

namespace App\Services\Knowledge;

use App\Models\Document;

class KnowledgeService implements KnowledgeServiceInterface
{
    public function process(Document $document): bool
    {
        return true;
    }
}