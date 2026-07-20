<?php

namespace App\Services\Document;

    use App\Models\Document;
    use App\Models\KnowledgeChunk;
    use App\Repositories\DocumentRepositoryInterface;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Str;
    use Smalot\PdfParser\Parser;

    class DocumentService implements DocumentServiceInterface
    {
        public function __construct(
            private DocumentRepositoryInterface $documentRepository
        ) {
        }

        public function upload(array $data): Document
        {
            return DB::transaction(function () use ($data) {

                /*
                |--------------------------------------------------------------------------
                | Upload File
                |--------------------------------------------------------------------------
                */

                $file = $data['document'];

                $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();

                $filePath = $file->storeAs(
                    'documents',
                    $fileName,
                    'public'
                );

                /*
                |--------------------------------------------------------------------------
                | Parse PDF
                |--------------------------------------------------------------------------
                */

                $parser = new Parser();

                $pdf = $parser->parseFile(
                    storage_path('app/public/' . $filePath)
                );

                $content = $pdf->getText();

                /*
                |--------------------------------------------------------------------------
                | Clean Text
                |--------------------------------------------------------------------------
                */

                $content = $this->cleanText($content);

                /*
                |--------------------------------------------------------------------------
                | Save Document
                |--------------------------------------------------------------------------
                */

                $document = $this->documentRepository->create([

                    'title'         => $data['title'],
                    'file_name'     => $fileName,
                    'file_path'     => $filePath,
                    'content'       => $content,
                    'file_type'     => $file->getClientOriginalExtension(),
                    'total_chunks'  => 0,
                    'status'        => 'uploaded',

                ]);

                /*
                |--------------------------------------------------------------------------
                | Delete Old Chunk
                |--------------------------------------------------------------------------
                */

                KnowledgeChunk::where(
                    'document_id',
                    $document->id
                )->delete();

                /*
                |--------------------------------------------------------------------------
                | Split Chunk
                |--------------------------------------------------------------------------
                */

                $chunks = $this->splitIntoChunks($content);

                foreach ($chunks as $index => $chunk) {

                    KnowledgeChunk::create([

                        'document_id'  => $document->id,
                        'chunk_number' => $index + 1,
                        'content'      => $chunk,
                        'embedding'    => null,

                    ]);

                }

                /*
                |--------------------------------------------------------------------------
                | Update Total Chunk
                |--------------------------------------------------------------------------
                */

                $document->update([
                    'total_chunks' => count($chunks)
                ]);

                return $document;
            });
        }

        /**
         * Bersihkan text hasil parser PDF
         */
        private function cleanText(string $text): string
        {
            $text = mb_convert_encoding(
                $text,
                'UTF-8',
                'auto'
            );

            $text = iconv(
                'UTF-8',
                'UTF-8//IGNORE',
                $text
            );

            $text = preg_replace('/[[:^print:]]/', ' ', $text);

            $text = preg_replace('/\s+/', ' ', $text);

            return trim($text);
        }

        /**
         * Split document menjadi chunk
         */
        private function splitIntoChunks(string $text): array
        {
            $sentences = preg_split(
                '/(?<=[.?!])\s+/',
                $text
            );

            $chunks = [];

            $current = '';

            foreach ($sentences as $sentence) {

                if (
                    strlen($current . ' ' . $sentence) > 1200
                ) {

                    $chunks[] = trim($current);

                    $current = $sentence;

                } else {

                    $current .= ' ' . $sentence;

                }

            }

            if (!empty(trim($current))) {
                $chunks[] = trim($current);
            }

            return $chunks;
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

                KnowledgeChunk::where(
                    'document_id',
                    $document->id
                )->delete();

                if (
                    $document->file_path &&
                    Storage::disk('public')->exists($document->file_path)
                ) {

                    Storage::disk('public')->delete(
                        $document->file_path
                    );

                }

                return $this->documentRepository->delete($document);
            });
        }
    }