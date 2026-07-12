<?php

namespace App\Services\Knowledge;

use App\Models\Document;

interface KnowledgeServiceInterface
{
    public function process(Document $document): bool;
}