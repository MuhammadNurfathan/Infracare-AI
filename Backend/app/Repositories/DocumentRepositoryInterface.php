<?php

namespace App\Repositories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;

interface DocumentRepositoryInterface
{
    public function getAll(): Collection;

    public function findById(int $id): ?Document;

    public function create(array $data): Document;

    public function update(Document $document, array $data): bool;

    public function delete(Document $document): bool;
}