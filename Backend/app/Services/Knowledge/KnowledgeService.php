<?php

namespace App\Services\Knowledge;

use App\Models\Document;
use App\Models\KnowledgeChunk;
use Illuminate\Support\Facades\Log;

/**
 * WHY THIS WAS EMPTY
 * ------------------
 * `knowledge_chunks.embedding` and this service look like the start of a
 * semantic/vector search feature that was never finished. Right now nothing
 * in the app writes to `embedding`, and SearchService does pure keyword
 * (LIKE + scoring) matching — it never reads `embedding` either. So the
 * stub `return true;` wasn't wired to anything and had no effect either way.
 *
 * WHAT THIS VERSION DOES INSTEAD
 * -------------------------------
 * Rather than leave it as literal dead code, this gives KnowledgeService a
 * real, modest job that fits the *current* (lexical-only) search pipeline:
 * after DocumentService creates a document + its chunks, this verifies the
 * chunks actually exist and look usable, then marks the document's status
 * so the rest of the app (e.g. an admin document list) can tell "uploaded"
 * apart from "confirmed ready to be searched".
 *
 * This does NOT implement embedding-based semantic search. That is a
 * separate, bigger feature (call an embeddings API per chunk, store the
 * vector, then have SearchService do a hybrid lexical + cosine-similarity
 * rerank). If/when you want that, this class is the natural place to add
 * it — see the `generateEmbeddings()` stub below for where it would plug in.
 *
 * WIRING THIS IN
 * ---------------
 * To actually have this run, call it after chunk creation in
 * DocumentService::upload(), right after `$document->update(['total_chunks' => ...])`:
 *
 *     $this->knowledgeService->process($document);
 *
 * (inject `KnowledgeServiceInterface` into DocumentService's constructor
 * the same way the other services are injected).
 */
class KnowledgeService implements KnowledgeServiceInterface
{
    /**
     * A document with fewer usable chunks than this is treated as failed
     * processing (e.g. PDF parsed to almost nothing) rather than "ready".
     */
    private const MIN_USABLE_CHUNKS = 1;

    public function process(Document $document): bool
    {
        $chunks = KnowledgeChunk::query()
            ->where('document_id', $document->id)
            ->get(['id', 'content']);

        $usableChunks = $chunks->filter(
            fn (KnowledgeChunk $chunk) => trim((string) $chunk->content) !== ''
        );

        if ($usableChunks->count() < self::MIN_USABLE_CHUNKS) {
            Log::warning('Knowledge processing found no usable chunks for document', [
                'document_id' => $document->id,
                'total_chunks' => $chunks->count(),
            ]);

            $document->update(['status' => 'failed']);

            return false;
        }

        $document->update([
            'status' => 'processed',
            'total_chunks' => $usableChunks->count(),
        ]);

        Log::info('Knowledge processing completed', [
            'document_id' => $document->id,
            'usable_chunks' => $usableChunks->count(),
        ]);

        return true;

        // --- Future extension point -----------------------------------
        // if (config('services.embeddings.enabled')) {
        //     $this->generateEmbeddings($usableChunks);
        // }
    }

    /**
     * NOT IMPLEMENTED YET — placeholder for the semantic-search feature
     * described above. Left unimplemented on purpose: it needs an embeddings
     * API key/endpoint decision and a migration change to SearchService to
     * actually use the vectors it would produce. Wire this up once that's
     * decided rather than half-implementing it here.
     */
    // private function generateEmbeddings(\Illuminate\Support\Collection $chunks): void
    // {
    //     foreach ($chunks as $chunk) {
    //         // $vector = Http::post('https://openrouter.ai/api/v1/embeddings', [...]);
    //         // $chunk->update(['embedding' => $vector]);
    //     }
    // }
}