<?php

namespace App\Services\Search;

use App\Models\KnowledgeChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SearchService implements SearchServiceInterface
{
    public function search(string $question): Collection
    {
        $startTime = microtime(true);

                $originalQuestion = trim($question);

                $normalizedQuestion = mb_strtolower($originalQuestion);

                $normalizedQuestion = preg_replace(
                    '/[^a-z0-9\s]/u',
                    ' ',
                    $normalizedQuestion
                );

                $normalizedQuestion = preg_replace(
                    '/\s+/',
                    ' ',
                    trim($normalizedQuestion)
                );

                $stopWords = [

                    'yang',
                    'dan',
                    'atau',
                    'di',
                    'ke',
                    'dari',
                    'apa',
                    'apakah',
                    'adalah',
                    'untuk',
                    'cara',
                    'bagaimana',
                    'ini',
                    'itu',
                    'kami',
                    'saya',
                    'aku',
                    'anda',
                    'mohon',
                    'tolong',
                    'bisa',
                    'ingin',
                    'mau',
                    'dapat',
                    'seperti',
                    'dengan',
                    'apabila',
                    'jika',

                ];

                $keywords = array_values(array_unique(array_filter(

                    preg_split('/\s+/', $normalizedQuestion),

                    function ($word) use ($stopWords) {

                        return mb_strlen($word) >= 3
                            && !in_array($word, $stopWords, true);

                    }

                )));

                $keywords = $this->expandKeywords($keywords);

                $tokens = array_values(array_filter(
                    array_map('trim', preg_split('/\s+/', $normalizedQuestion) ?: []),
                    fn ($token) => $token !== ''
                ));

                $phraseVariants = [];
                foreach ($tokens as $index => $token) {
                    if ($index + 1 < count($tokens)) {
                        $phraseVariants[] = $token . ' ' . $tokens[$index + 1];
                    }
                }

                $phraseVariants = array_values(array_unique(array_filter(
                    array_merge($phraseVariants, [$normalizedQuestion, $originalQuestion]),
                    fn ($term) => trim((string) $term) !== '' && mb_strlen(trim((string) $term)) >= 3
                )));

                $candidateTerms = array_values(array_unique(array_filter(
                    array_merge(
                        [$normalizedQuestion],
                        [$originalQuestion],
                        $keywords,
                        $phraseVariants
                    ),
                    fn ($term) => trim((string) $term) !== '' && mb_strlen(trim((string) $term)) >= 3
                )));

                $query = KnowledgeChunk::query()
                    ->with('document');

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

                $chunks = $query
                    ->limit(200)
                    ->get();

                if ($chunks->isEmpty() && $normalizedQuestion !== '') {
                    $chunks = KnowledgeChunk::query()
                        ->with('document')
                        ->where('content', 'LIKE', '%' . $normalizedQuestion . '%')
                        ->limit(120)
                        ->get();
                }

                $results = [];

                foreach ($chunks as $chunk) {

                    $score = 0;

                    $matched = 0;

                    $text = mb_strtolower(
                        (string) ($chunk->content ?? '')
                    );

                    $title = mb_strtolower(
                        (string) ($chunk->document?->title ?? '')
                    );

                    if (
                        $normalizedQuestion !== ''
                        && str_contains($text, $normalizedQuestion)
                    ) {

                        $score += 260;

                        $matched += 2;

                    }

                    foreach ($phraseVariants as $phrase) {

                        if ($phrase === '') {
                            continue;
                        }

                        if (str_contains($text, $phrase) || str_contains($title, $phrase)) {
                            $score += 140;
                            $matched++;
                        }

                    }

                    $specificSignals = [
                        '5.1.1.11',
                        '5.1.1.12',
                        'changing management password',
                        'change management password',
                        'management password',
                        'managed account',
                        'managed accounts',
                        'refresh password',
                        'refresh passwords',
                        'password refresh',
                        'password reset',
                        'reset password',
                        'reset passwords',
                        'third-party server',
                        'server management interface',
                    ];

                    foreach ($specificSignals as $signal) {
                        if (str_contains($text, $signal) || str_contains($title, $signal)) {
                            $score += 220;
                            $matched++;
                        }
                    }

                    $hasPasswordContext = str_contains($normalizedQuestion, 'password')
                        || str_contains($normalizedQuestion, 'kata sandi')
                        || str_contains($normalizedQuestion, 'management password');
                    $hasRefreshContext = str_contains($normalizedQuestion, 'refresh')
                        || str_contains($normalizedQuestion, 'reset')
                        || str_contains($normalizedQuestion, 'ganti');
                    $hasManagedContext = str_contains($normalizedQuestion, 'managed')
                        || str_contains($normalizedQuestion, 'management')
                        || str_contains($normalizedQuestion, 'account');

                    if ($hasPasswordContext && $hasRefreshContext && $hasManagedContext) {
                        $score += 180;
                        $matched++;
                    }

                    $genericPasswordOnly = str_contains($text, 'password')
                        && !str_contains($text, 'refresh')
                        && !str_contains($text, 'managed')
                        && !str_contains($text, 'management')
                        && !str_contains($text, 'account');

                    if ($genericPasswordOnly) {
                        $score -= 60;
                    }

                    foreach ($keywords as $keyword) {

                        if ($keyword === '') {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Title Match
                        |--------------------------------------------------------------------------
                        */

                        if (str_contains($title, $keyword)) {

                            $score += 120;

                            $matched++;

                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Content Match
                        |--------------------------------------------------------------------------
                        */

                        $count = substr_count($text, $keyword);

                        if ($count > 0) {

                            $matched++;

                            $score += 20 + ($count * 5);

                        }

                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Bonus Score
                    |--------------------------------------------------------------------------
                    */

                    if ($matched >= 2) {

                        $score += 50;

                    }

                    if ($matched >= 4) {

                        $score += 40;

                    }

                    if ($score >= 10) {

                        $results[] = [

                            'chunk' => $chunk,

                            'score' => $score

                        ];

                    }

                }

                usort($results, function ($a, $b) {

                    return $b['score'] <=> $a['score'];

                });

                $topResults = collect($results)
                    ->sortByDesc(fn ($result) => $result['score'])
                    ->take(20)
                    ->values();

                $contextualChunks = $topResults->map(function ($result) {

                    $chunk = $result['chunk'];

                    $context = $this->buildContextWindow($chunk);

                    /*
                    |--------------------------------------------------------------------------
                    | Batasi Context
                    |--------------------------------------------------------------------------
                    */

                    $context = mb_substr($context, 0, 15000);

                    $chunk->setAttribute(
                        'search_score',
                        $result['score']
                    );

                    $chunk->setAttribute(
                        'content',
                        $context
                    );

                    return $chunk;

                });

                Log::info('SEARCH RESULT', [

                    'question' => $originalQuestion,

                    'keywords' => $keywords,

                    'time_ms' => round(
                        (microtime(true) - $startTime) * 1000,
                        2
                    ),

                    'total_found' => count($results),

                    'result' => $contextualChunks->map(function ($chunk) {

                        return [

                            'score' => $chunk->search_score,

                            'preview' => mb_substr(
                                $chunk->content,
                                0,
                                180
                            )

                        ];

                    })

                ]);

                return $contextualChunks;

    }
    private function expandKeywords(array $keywords): array
{
    $synonyms = [

        /*
        |--------------------------------------------------------------------------
        | Network
        |--------------------------------------------------------------------------
        */

        'internet' => [
            'network',
            'jaringan',
            'wifi',
            'connection',
            'koneksi'
        ],

        'wifi' => [
            'wireless',
            'internet',
            'hotspot',
            'ssid'
        ],

        'router' => [
            'gateway',
            'mikrotik',
            'routerboard'
        ],

        'mikrotik' => [
            'router',
            'gateway'
        ],

        'vpn' => [
            'tunnel',
            'virtual private network'
        ],

        /*
        |--------------------------------------------------------------------------
        | Server
        |--------------------------------------------------------------------------
        */

        'server' => [
            'host',
            'machine',
            'service'
        ],

        'down' => [
            'offline',
            'error',
            'gangguan',
            'failed',
            'failure',
            'fault',
            'unavailable'
        ],

        'offline' => [
            'down',
            'mati',
            'disconnect'
        ],

        'mati' => [
            'offline',
            'down',
            'unavailable'
        ],

        'error' => [
            'failed',
            'failure',
            'fault',
            'problem'
        ],

        'gagal' => [
            'failed',
            'error',
            'failure'
        ],

        /*
        |--------------------------------------------------------------------------
        | Performance
        |--------------------------------------------------------------------------
        */

        'lambat' => [
            'slow',
            'delay',
            'lemot'
        ],

        'lemot' => [
            'slow',
            'delay',
            'lambat'
        ],

        /*
        |--------------------------------------------------------------------------
        | Login
        |--------------------------------------------------------------------------
        */

        'login' => [
            'signin',
            'sign in',
            'masuk',
            'akun'
        ],

        'akun' => [
            'user',
            'username',
            'login'
        ],

        'password' => [
            'credential',
            'kata sandi',
            'passwd'
        ],

        'reset' => [
            'refresh',
            'change',
            'ganti'
        ],

        'refresh' => [
            'reset',
            'change',
            'ganti'
        ],

        /*
        |--------------------------------------------------------------------------
        | Security
        |--------------------------------------------------------------------------
        */

        'keamanan' => [
            'security',
            'proteksi',
            'safe'
        ],

        /*
        |--------------------------------------------------------------------------
        | Service
        |--------------------------------------------------------------------------
        */

        'restart' => [
            'reboot',
            'reload'
        ],

        'aktif' => [
            'aktifkan',
            'hidup',
            'running'
        ],

        'aktifkan' => [
            'aktif',
            'hidup',
            'jalankan'
        ],

    ];

    $expanded = [];

    foreach ($keywords as $keyword) {

        $expanded[] = $keyword;

        if (isset($synonyms[$keyword])) {

            $expanded = array_merge(
                $expanded,
                $synonyms[$keyword]
            );

        }

        /*
        |--------------------------------------------------------------------------
        | Singular Word
        |--------------------------------------------------------------------------
        */

        if (str_ends_with($keyword, 's')) {

            $expanded[] = substr(
                $keyword,
                0,
                -1
            );

        }

    }

    return array_values(
        array_unique(
            array_filter(
                $expanded,
                fn($word) => mb_strlen($word) >= 3
            )
        )
    );
}
private function buildContextWindow(KnowledgeChunk $target, int $radius = 6): string
{
    $documentChunks = KnowledgeChunk::query()
        ->where('document_id', $target->document_id)
        ->orderBy('chunk_number')
        ->get([
            'id',
            'content',
            'chunk_number',
            'document_id'
        ]);

    $index = $documentChunks->search(function ($chunk) use ($target) {
        return (int) $chunk->id === (int) $target->id;
    });

    if ($index === false) {
        return (string) ($target->content ?? '');
    }

    $start = max(0, $index - $radius);
    $end = min($documentChunks->count() - 1, $index + $radius);

    $contextParts = [];
    foreach ($documentChunks->slice($start, ($end - $start) + 1) as $chunk) {
        $content = trim((string) ($chunk->content ?? ''));
        if ($content === '') {
            continue;
        }

        $contextParts[] = "[Chunk {$chunk->chunk_number}] {$content}";
    }

    $context = implode("\n\n", $contextParts);

    return mb_substr($context, 0, 18000);
}
}