<?php

namespace App\Services\Document;

use App\Models\Document;
use App\Repositories\DocumentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

class DocumentService implements DocumentServiceInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documentRepository
    ) {}

    public function upload(array $data): Document
    {
        return DB::transaction(function () use ($data) {

            $file = $data['document'];

            // Generate nama file unik
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();

            // Simpan file ke storage/app/public/documents
            $filePath = $file->storeAs(
                'documents',
                $fileName,
                'public'
            );

            // Parse isi PDF
            $parser = new Parser();

            $pdf = $parser->parseFile(
                storage_path('app/public/' . $filePath)
            );

            $content = $pdf->getText();

            // Data yang akan disimpan ke database
            $documentData = [
                'title'         => $data['title'],
                'file_name'     => $fileName,
                'file_path'     => $filePath,
                'content'       => $content,
                'file_type'     => $file->getClientOriginalExtension(),
                'total_chunks'  => 0,
                'status'        => 'uploaded',
            ];

            return $this->documentRepository->create($documentData);
        });
    }

    public function getAll()
    {
        return $this->documentRepository->getAll();
    }

    public function findById(int $id): ?Document
    {
        return $this->documentRepository->findById($id);
    }

    public function delete(Document $document): bool
    {
        return DB::transaction(function () use ($document) {

            if (
                $document->file_path &&
                Storage::disk('public')->exists($document->file_path)
            ) {
                Storage::disk('public')->delete($document->file_path);
            }

            return $this->documentRepository->delete($document);
        });
    }
}