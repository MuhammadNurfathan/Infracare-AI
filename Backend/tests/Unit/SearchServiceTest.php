<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\KnowledgeChunk;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_neighbor_chunks_for_context(): void
    {
        $document = Document::create([
            'title' => 'Panduan fitur keamanan',
            'file_name' => 'manual.pdf',
            'file_path' => 'documents/manual.pdf',
            'content' => 'Panduan fitur keamanan',
            'file_type' => 'pdf',
            'status' => 'uploaded',
            'total_chunks' => 2,
        ]);

        KnowledgeChunk::create([
            'document_id' => $document->id,
            'chunk_number' => 1,
            'content' => 'Untuk mengaktifkan fitur keamanan, buka menu Pengaturan dan pilih opsi keamanan.',
            'embedding' => null,
        ]);

        KnowledgeChunk::create([
            'document_id' => $document->id,
            'chunk_number' => 2,
            'content' => 'Setelah itu, aktifkan fitur keamanan dan pastikan email Anda terverifikasi.',
            'embedding' => null,
        ]);

        $service = new SearchService();

        $results = $service->search('bagaimana cara mengaktifkan fitur keamanan');

        $this->assertGreaterThanOrEqual(2, $results->count());
        $this->assertTrue($results->contains(fn ($chunk) => $chunk->chunk_number === 1));
        $this->assertTrue($results->contains(fn ($chunk) => $chunk->chunk_number === 2));
    }

    public function test_it_prioritizes_specific_password_reset_chunks_over_generic_password_chunks(): void
    {
        $relevantDocument = Document::create([
            'title' => 'Panduan refresh password akun terkelola',
            'file_name' => 'password.pdf',
            'file_path' => 'documents/password.pdf',
            'content' => 'Panduan refresh password akun terkelola',
            'file_type' => 'pdf',
            'status' => 'uploaded',
            'total_chunks' => 2,
        ]);

        KnowledgeChunk::create([
            'document_id' => $relevantDocument->id,
            'chunk_number' => 1,
            'content' => 'Untuk melakukan refresh password akun terkelola, pilih server lalu pilih opsi refresh password.',
            'embedding' => null,
        ]);

        $genericDocument = Document::create([
            'title' => 'Panduan keamanan akun',
            'file_name' => 'generic.pdf',
            'file_path' => 'documents/generic.pdf',
            'content' => 'Panduan keamanan akun',
            'file_type' => 'pdf',
            'status' => 'uploaded',
            'total_chunks' => 1,
        ]);

        KnowledgeChunk::create([
            'document_id' => $genericDocument->id,
            'chunk_number' => 1,
            'content' => 'A password must contain at least three types of characters. Password cannot be the same as the account name. Password must be changed regularly.',
            'embedding' => null,
        ]);

        $service = new SearchService();

        $results = $service->search('cara reset password');
        $first = $results->first();

        $this->assertNotNull($first);
        $this->assertStringContainsString('refresh password', mb_strtolower((string) $first->content));
    }
}
