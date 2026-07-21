<?php

namespace App\Services\Search;

use App\Models\KnowledgeChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SearchService implements SearchServiceInterface
{
    /**
     * Indonesian + English stop words — words with no search signal on their own.
     */
    private const STOP_WORDS = [
        'yang', 'dan', 'atau', 'di', 'ke', 'dari', 'apa', 'apakah', 'adalah', 'untuk',
        'cara', 'bagaimana', 'ini', 'itu', 'kami', 'saya', 'aku', 'anda', 'mohon',
        'tolong', 'bisa', 'ingin', 'mau', 'dapat', 'seperti', 'dengan', 'apabila', 'jika',
        'the', 'and', 'for', 'with', 'this', 'that', 'please', 'how', 'what', 'where',
        'when', 'who', 'why', 'can', 'you', 'your',
    ];

    /**
     * Generic bilingual synonym map for common IT-support vocabulary.
     * Keep this domain-general — do NOT add phrases tied to a single specific
     * answer/section here, that overfits the ranking to one FAQ entry and hurts
     * every other question.
     */
    private const SYNONYMS = [
        'internet' => ['network', 'jaringan', 'wifi', 'connection', 'koneksi'],
        'wifi' => ['wireless', 'internet', 'hotspot', 'ssid'],
        'router' => ['gateway', 'mikrotik', 'routerboard'],
        'mikrotik' => ['router', 'gateway'],
        'vpn' => ['tunnel', 'virtual private network'],
        'server' => ['host', 'machine', 'service'],
        'down' => ['offline', 'error', 'gangguan', 'failed', 'failure', 'fault', 'unavailable'],
        'offline' => ['down', 'mati', 'disconnect'],
        'mati' => ['offline', 'down', 'unavailable'],
        'error' => ['failed', 'failure', 'fault', 'problem'],
        'gagal' => ['failed', 'error', 'failure'],
        'lambat' => ['slow', 'delay', 'lemot'],
        'lemot' => ['slow', 'delay', 'lambat'],
        'login' => ['signin', 'sign in', 'masuk', 'akun'],
        'akun' => ['user', 'username', 'login'],
        'password' => ['credential', 'kata sandi', 'passwd'],
        'reset' => ['refresh', 'change', 'ganti'],
        'refresh' => ['reset', 'change', 'ganti'],
        'keamanan' => ['security', 'proteksi', 'safe'],
        'restart' => ['reboot', 'reload'],
        'aktif' => ['aktifkan', 'hidup', 'running'],
        'aktifkan' => ['aktif', 'hidup', 'jalankan'],
    ];

    /**
     * Common junk that leaks out of Word/PDF exports and pollutes context:
     * broken field codes, repeated page footers/headers, copyright lines.
     * These carry zero answer content but eat into the context window and
     * make the merged answer look broken.
     */
    private const NOISE_PATTERNS = [
        '/Error!\s*No text of specified style in document\.?/iu',
        '/Issue\s*\d+\s*\(\d{4}-\d{2}-\d{2}\)/iu',
        '/Copyright\s*©[^\n]*/iu',
    ];

    /**
     * Tracks chunk IDs already included in some result's context window, so a
     * lower-ranked result's window doesn't re-include text a higher-ranked
     * result already brought in. Without this, overlapping windows around
     * nearby chunks silently crowd out the actually-relevant lower-ranked hit
     * once the AI-side context budget gets truncated.
     *
     * @var array<int, bool>
     */
    private array $includedChunkIds = [];

    /**
     * Per-request cache of a document's chunks, keyed by document_id.
     * Avoids re-querying the whole document for every single matched chunk
     * when building the surrounding context window.
     *
     * @var array<int, Collection>
     */
    private array $documentChunkCache = [];

    public function search(string $question): Collection
    {
        $startTime = microtime(true);
        $this->documentChunkCache = [];
        $this->includedChunkIds = [];

        $originalQuestion = trim($question);
        $normalizedQuestion = $this->normalize($originalQuestion);

        $keywords = $this->expandKeywords($this->extractKeywords($normalizedQuestion));
        $phrases = $this->buildPhrases($normalizedQuestion);
        $candidateTerms = $this->buildCandidateTerms($normalizedQuestion, $originalQuestion, $keywords, $phrases);

        $chunks = $this->fetchCandidateChunks($candidateTerms, $normalizedQuestion);

        $scored = $chunks
            ->map(fn ($chunk) => [
                'chunk' => $chunk,
                'score' => $this->scoreChunk($chunk, $normalizedQuestion, $keywords, $phrases),
            ])
            ->filter(fn ($result) => $result['score'] >= 10)
            ->sortByDesc('score')
            ->take(20)
            ->values();

        $contextualChunks = $scored->map(function ($result) {
            $chunk = $result['chunk'];
            $context = $this->buildContextWindow($chunk);

            $chunk->setAttribute('search_score', $result['score']);
            $chunk->setAttribute('content', $context);

            return $chunk;
        });

        Log::info('SEARCH RESULT', [
            'question' => $originalQuestion,
            'keywords' => $keywords,
            'time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'total_found' => $scored->count(),
            'result' => $contextualChunks->map(fn ($chunk) => [
                'score' => $chunk->search_score,
                'preview' => mb_substr($chunk->content, 0, 180),
            ]),
        ]);

        return $contextualChunks;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text) ?? $text;

        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }

    private function extractKeywords(string $normalizedQuestion): array
    {
        if ($normalizedQuestion === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            preg_split('/\s+/', $normalizedQuestion) ?: [],
            fn ($word) => mb_strlen($word) >= 3 && !in_array($word, self::STOP_WORDS, true)
        )));
    }

    private function buildPhrases(string $normalizedQuestion): array
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/', $normalizedQuestion) ?: [],
            fn ($token) => $token !== ''
        ));

        $phrases = [];
        foreach ($tokens as $index => $token) {
            if (isset($tokens[$index + 1])) {
                $phrases[] = $token . ' ' . $tokens[$index + 1];
            }
        }

        return array_values(array_unique(array_filter(
            $phrases,
            fn ($phrase) => mb_strlen($phrase) >= 3
        )));
    }

    private function buildCandidateTerms(string $normalizedQuestion, string $originalQuestion, array $keywords, array $phrases): array
    {
        return array_values(array_unique(array_filter(
            array_merge([$normalizedQuestion, $originalQuestion], $keywords, $phrases),
            fn ($term) => trim((string) $term) !== '' && mb_strlen(trim((string) $term)) >= 3
        )));
    }

    private function fetchCandidateChunks(array $candidateTerms, string $normalizedQuestion): Collection
    {
        $query = KnowledgeChunk::query()->with('document');

        if ($candidateTerms !== []) {
            $query->where(function ($subQuery) use ($candidateTerms) {
                foreach ($candidateTerms as $term) {
                    $term = trim((string) $term);
                    if ($term === '') {
                        continue;
                    }

                    $subQuery->orWhere('content', 'LIKE', '%' . $term . '%');
                    $subQuery->orWhereHas('document', function ($documentQuery) use ($term) {
                        $documentQuery->where('title', 'LIKE', '%' . $term . '%');
                    });
                }
            });
        }

        $chunks = $query->limit(200)->get();

        if ($chunks->isEmpty() && $normalizedQuestion !== '') {
            $chunks = KnowledgeChunk::query()
                ->with('document')
                ->where('content', 'LIKE', '%' . $normalizedQuestion . '%')
                ->limit(120)
                ->get();
        }

        return $chunks;
    }

    /**
     * Generic relevance score. No topic is special-cased here on purpose —
     * anything hardcoded for one FAQ answer will silently outrank every
     * other legitimate question.
     */
    private function scoreChunk(KnowledgeChunk $chunk, string $normalizedQuestion, array $keywords, array $phrases): int
    {
        $text = mb_strtolower((string) ($chunk->content ?? ''));
        $title = mb_strtolower((string) ($chunk->document?->title ?? ''));

        $score = 0;
        $matched = 0;

        // Strong signal: the whole (normalized) question appears near-verbatim.
        if ($normalizedQuestion !== '' && str_contains($text, $normalizedQuestion)) {
            $score += 200;
            $matched += 2;
        }

        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $score += 60;
                $matched++;
            }
            if (str_contains($title, $phrase)) {
                $score += 40;
                $matched++;
            }
        }

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            if (str_contains($title, $keyword)) {
                $score += 100;
                $matched++;
            }

            $count = substr_count($text, $keyword);
            if ($count > 0) {
                $matched++;
                // Cap the repetition bonus so a chunk can't game the score by
                // repeating one common word many times.
                $score += 15 + (min($count, 5) * 5);
            }
        }

        // Coverage: what fraction of the question's own keywords does this
        // chunk actually contain? This is what makes results track the
        // specific question instead of just "contains a password somewhere".
        if ($keywords !== []) {
            $coverage = min($matched / count($keywords), 1.0);
            $score += (int) round($coverage * 60);
        }

        if ($matched >= 2) {
            $score += 30;
        }

        if ($matched >= 4) {
            $score += 30;
        }

        return $score;
    }

    private function expandKeywords(array $keywords): array
    {
        $expanded = [];

        foreach ($keywords as $keyword) {
            $expanded[] = $keyword;

            if (isset(self::SYNONYMS[$keyword])) {
                $expanded = array_merge($expanded, self::SYNONYMS[$keyword]);
            }

            if (str_ends_with($keyword, 's')) {
                $expanded[] = substr($keyword, 0, -1);
            }
        }

        return array_values(array_unique(array_filter(
            $expanded,
            fn ($word) => mb_strlen($word) >= 3
        )));
    }

    private function buildContextWindow(KnowledgeChunk $target, int $radius = 3): string
    {
        $documentChunks = $this->documentChunkCache[$target->document_id]
            ??= KnowledgeChunk::query()
                ->where('document_id', $target->document_id)
                ->orderBy('chunk_number')
                ->get(['id', 'content', 'chunk_number', 'document_id']);

        $index = $documentChunks->search(
            fn ($chunk) => (int) $chunk->id === (int) $target->id
        );

        if ($index === false) {
            return (string) ($target->content ?? '');
        }

        $start = max(0, $index - $radius);
        $end = min($documentChunks->count() - 1, $index + $radius);

        $contextParts = [];
        foreach ($documentChunks->slice($start, ($end - $start) + 1) as $chunk) {
            $chunkId = (int) $chunk->id;
            $isTarget = $chunkId === (int) $target->id;

            // Skip neighbor chunks another (higher-ranked) result already
            // pulled in — always keep the target itself, since that's the
            // chunk that actually earned this result its score.
            if (!$isTarget && isset($this->includedChunkIds[$chunkId])) {
                continue;
            }

            $content = $this->stripNoise((string) ($chunk->content ?? ''));
            if ($content === '') {
                continue;
            }

            $contextParts[] = "[Chunk {$chunk->chunk_number}] {$content}";
            $this->includedChunkIds[$chunkId] = true;
        }

        return mb_substr(implode("\n\n", $contextParts), 0, 6000);
    }

    private function stripNoise(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace(self::NOISE_PATTERNS, ' ', $text) ?? $text;

        return preg_replace('/[ \t]+/', ' ', trim($text)) ?? '';
    }
}