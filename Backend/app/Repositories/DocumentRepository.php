<?php

namespace App\Repositories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;

class DocumentRepository implements DocumentRepositoryInterface
{
    public function getAll(): Collection
    {
        return Document::latest()->get();
    }

    public function findById(int $id): ?Document
    {
        return Document::find($id);
    }

    public function create(array $data): Document
    {
        $data['status'] = 'uploaded';
        return Document::create($data);
    }

    public function update(Document $document, array $data): bool
    {
        return $document->update($data);
    }

    public function delete(Document $document): bool
    {
        return $document->delete();
    }
}