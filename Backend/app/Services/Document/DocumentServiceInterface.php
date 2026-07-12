<?php

namespace App\Services\Document;

use App\Models\Document;

interface DocumentServiceInterface
{
    /**
     * Upload manual book
     */
    public function upload(array $data): Document;

    /**
     * Ambil semua document
     */
    public function getAll();

    /**
     * Ambil document berdasarkan ID
     */
    public function findById(int $id): ?Document;

    /**
     * Hapus document
     */
    public function delete(Document $document): bool;
}