<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Services\AI\AIServiceInterface;
use App\Services\Intent\ServiceIntentInterface;
use App\Services\Search\SearchServiceInterface;
use Illuminate\Support\Facades\Log;

class ChatService implements ChatServiceInterface
{
    public function __construct(
        private SearchServiceInterface $searchService,
        private AIServiceInterface $aiService,
        private ServiceIntentInterface $intentService
    ) {}

    public function receiveMessage(array $data): array
    {
        $phone = (string) ($data['phone'] ?? '');
        $name = trim((string) ($data['name'] ?? 'Customer')) ?: 'Customer';
        $message = trim((string) ($data['message'] ?? ''));

        $customer = Customer::updateOrCreate(
            ['phone' => $phone],
            ['name' => $name, 'last_chat_at' => now()]
        );

        $conversation = Conversation::firstOrCreate(
            ['customer_id' => $customer->id, 'status' => 'open'],
            ['started_at' => now()]
        );

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'message' => $message !== '' ? $message : '(pesan kosong)',
        ]);

        $intent = $this->intentService->detect($message);

        if (in_array($intent, ['greeting', 'thanks', 'admin'], true)) {
            return $this->respondToSimpleIntent($conversation->id, $intent, $message);
        }

        return $this->respondFromKnowledgeBase($conversation->id, $message);
    }

    private function respondToSimpleIntent(int $conversationId, string $intent, string $message): array
    {
        [$reply, $confidence, $shouldEscalate] = match ($intent) {
            'greeting' => [$this->buildGreetingReply($message), 92, false],
            'thanks' => [$this->buildThanksReply($message), 90, false],
            'admin' => [$this->buildAdminReply($message), 88, true],
        };

        Message::create([
            'conversation_id' => $conversationId,
            'sender' => 'bot',
            'message' => $reply,
            'confidence' => $confidence,
        ]);

        return [
            'reply' => $reply,
            'should_escalate' => $shouldEscalate,
            'confidence' => (float) $confidence,
        ];
    }

    private function respondFromKnowledgeBase(int $conversationId, string $message): array
    {
        $searchResults = collect();

        try {
            $searchResults = $this->searchService->search($message);
        } catch (\Throwable $e) {
            Log::error('Chat search failed', [
                'message' => $message,
                'exception' => $e->getMessage(),
            ]);
        }

        $contextChunks = $searchResults
            ->map(fn ($chunk) => trim((string) ($chunk->content ?? '')))
            ->filter(fn ($chunk) => $chunk !== '')
            ->values();

        $context = $contextChunks->implode("\n\n");
        $bestScore = (int) ($searchResults->first()?->getAttribute('search_score') ?? 0);
        $hasContext = $contextChunks->isNotEmpty();

        $usedAi = false;

        // Only ask the AI to synthesize when there's actually something worth
        // synthesizing from. A low search score does NOT disqualify a good AI
        // answer later — it's only used here to decide whether to bother calling
        // the AI at all.
        $reply = ($hasContext && ($bestScore >= 20 || $contextChunks->count() >= 2))
            ? $this->tryGenerateAiReply($message, $context)
            : null;

        if ($reply !== null) {
            $usedAi = true;
        } elseif ($hasContext) {
            // AI failed, returned nothing usable, or context was too thin to
            // bother calling it — merge the most relevant chunks directly
            // instead of dumping a single raw, truncated chunk.
            $reply = $this->buildContextReply($message, $contextChunks);
        } else {
            $reply = $this->buildEscalationReply($message);
        }

        $reply = $this->finalizeReply($reply, $message, $usedAi);

        $confidence = match (true) {
            !$hasContext => 40,
            $usedAi && $bestScore >= 20 => 78,
            $usedAi => 65,
            default => 55,
        };

        $shouldEscalate = !$hasContext;

        Message::create([
            'conversation_id' => $conversationId,
            'sender' => 'bot',
            'message' => $reply,
            'confidence' => $confidence,
        ]);

        return [
            'reply' => $reply,
            'should_escalate' => $shouldEscalate,
            'confidence' => (float) $confidence,
        ];
    }

    /**
     * Returns null (instead of a fallback string) when the AI reply isn't usable,
     * so the caller can fall back to raw context without checking magic strings twice.
     */
    private function tryGenerateAiReply(string $message, string $context): ?string
    {
        try {
            return $this->aiService->generateResponse($message, $context);
        } catch (\Throwable $e) {
            Log::error('AI reply generation failed', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Builds an answer directly from the retrieved context when the AI step
     * isn't usable. Merges the most relevant chunks (not just the first one)
     * so the reply reads as one answer instead of a single truncated dump.
     */
    private function buildContextReply(string $message, $contextChunks): string
    {
        $normalizedQuestion = mb_strtolower(trim($message));
        $normalizedQuestion = preg_replace('/[^a-z0-9\s]/u', ' ', $normalizedQuestion) ?? '';
        $normalizedQuestion = preg_replace('/\s+/', ' ', trim($normalizedQuestion)) ?? '';
        $keywords = array_values(array_filter(
            preg_split('/\s+/', $normalizedQuestion) ?: [],
            fn ($word) => mb_strlen($word) >= 3
        ));

        $ranked = $contextChunks
            ->map(fn ($chunk) => (string) $chunk)
            ->filter(fn ($chunk) => trim($chunk) !== '')
            ->sortByDesc(function ($content) use ($keywords) {
                $lower = mb_strtolower($content);
                $hits = 0;

                foreach ($keywords as $keyword) {
                    if (str_contains($lower, $keyword)) {
                        $hits++;
                    }
                }

                return $hits;
            })
            ->values();

        if ($ranked->isEmpty()) {
            return 'Mohon maaf, informasi yang Anda butuhkan belum tersedia pada manual perusahaan.';
        }

        $relevant = $ranked->filter(function ($content) use ($keywords) {
            $lower = mb_strtolower($content);
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return true;
                }
            }

            return false;
        });

        // Only merge chunks that actually share vocabulary with the question.
        // Falling back to unrelated chunks just to fill space is what produces
        // answers that jump between unrelated topics.
        $selected = $relevant->isNotEmpty() ? $relevant : $ranked->take(1);

        $cleaned = $selected
            ->take(3)
            ->map(fn ($chunk) => $this->cleanRawChunk($chunk))
            ->filter(fn ($chunk) => $chunk !== '')
            ->unique()
            ->values();

        $merged = $this->mergeChunksDeduplicatingHeadings($cleaned);

        return $this->truncateAtSentence($merged, 1600);
    }

    /**
     * Truncates without cutting a sentence (or a shell command line) in half.
     */
    private function truncateAtSentence(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $slice = mb_substr($text, 0, $maxLength);

        $lastBreak = max(
            mb_strrpos($slice, ". "),
            mb_strrpos($slice, ".\n"),
            mb_strrpos($slice, "\n")
        );

        if ($lastBreak !== false && $lastBreak > $maxLength * 0.4) {
            $slice = mb_substr($slice, 0, $lastBreak + 1);
        }

        return trim($slice);
    }

    /**
     * Structural cleanup for a single raw chunk before it's merged into a
     * reply: drops chunk markers, and normalizes inline "Step N" / "Langkah N"
     * labels onto their own numbered lines instead of running into the
     * previous sentence.
     */
    private function cleanRawChunk(string $chunk): string
    {
        $text = trim($chunk);
        $text = preg_replace('/\[Chunk\s*\d+\]\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\b(?:Step|Langkah)\s+(\d+)\s*[:.]?\s*/iu', "\n" . '$1. ', $text) ?? $text;

        // The source manuals use "⚫" as their bullet glyph (alongside the
        // occasional "•"). Neither was being put on its own line before, so
        // a run of bullet points like "⚫ You have logged in... ⚫ The HA
        // configuration..." rendered as one run-on sentence. Normalize both
        // to a plain "- " bullet and force each one onto a new line.
        $text = preg_replace('/(?<!\n)[⚫•]\s*/u', "\n- ", $text) ?? $text;
        $text = preg_replace('/^[⚫•]\s*/mu', '- ', $text) ?? $text;

        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/(?<!\n)(\d+\.\s)/u', "\n$1", $text) ?? $text;

        // Drop stray page-number-only lines (e.g. a lone "141") that leak in
        // when a page break lands in the middle of a section — the running
        // header/footer stripping in DocumentService doesn't catch every
        // layout variant, so this is a defensive second pass at merge time.
        $lines = preg_split('/\n/', $text) ?: [$text];
        $lines = array_values(array_filter(
            $lines,
            fn ($line) => preg_match('/^\d{1,4}$/', trim($line)) !== 1
        ));
        $text = implode("\n", $lines);

        $text = preg_replace('/\n{2,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Returns the title portion of a chunk's own heading line (e.g. "Renaming
     * Tag" from "3.1.2.5.2 Renaming Tag"), or '' if the chunk doesn't start
     * with a recognizable numbered heading. Mirrors SearchService's own
     * extraction so a merged reply can tell whether two chunks belong to the
     * same section.
     */
    private function extractHeadingTitle(string $content): string
    {
        $firstLine = trim(explode("\n", trim($content), 2)[0] ?? '');

        if (preg_match('/^\d{1,2}(?:\.\d{1,3}){1,6}\s+(.+)$/u', $firstLine, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * Merges already-cleaned chunks into one block of text, but drops a
     * repeated section heading when consecutive chunks are continuation
     * parts of the SAME section (produced by DocumentService's long-section
     * splitting, which re-prefixes every part with the same heading). Without
     * this, merging those parts back together in a reply shows the section
     * title twice — e.g. "3.1.2.1.2.3.4 Installing Ubuntu OS (ARM)" appearing
     * once before the intro and again before a later "Step 8".
     */
    private function mergeChunksDeduplicatingHeadings($chunks): string
    {
        $seenHeadings = [];
        $parts = [];

        foreach ($chunks as $chunk) {
            $chunk = (string) $chunk;
            $heading = $this->extractHeadingTitle($chunk);

            if ($heading !== '' && isset($seenHeadings[$heading])) {
                $lines = preg_split('/\n/', trim($chunk), 2) ?: [$chunk];
                $body = trim($lines[1] ?? '');
                $parts[] = $body !== '' ? $body : $chunk;
                continue;
            }

            if ($heading !== '') {
                $seenHeadings[$heading] = true;
            }

            $parts[] = $chunk;
        }

        return implode("\n\n", array_filter($parts, fn ($part) => trim((string) $part) !== ''));
    }

    /**
     * Single formatting pass. AI-generated replies already come pre-formatted
     * from AIService, so only light, non-destructive cleanup is applied here —
     * this used to be 3 separate regex passes stacked on top of each other,
     * which is what was mangling the layout.
     *
     * FIX (layout bug): the old $forbiddenPhrases list included plain words
     * like "section"/"sections"/"chunk"/"chunks" that also occur naturally
     * inside real manual text (e.g. "...pada bagian keamanan router...").
     * Blindly deleting those words out of a sentence left broken grammar,
     * double spaces, and stray punctuation behind — especially in the raw
     * context fallback path, which is verbatim manual text. Only multi-word
     * meta-commentary phrases (things a model says about its own reasoning,
     * never legitimate manual content) are stripped now, and any leftover
     * punctuation/spacing from a removal is cleaned up afterward.
     */
    private function finalizeReply(string $reply, string $message, bool $isAiGenerated): string
    {
        $reply = trim($reply);
        $reply = str_replace(["\r\n", "\r"], "\n", $reply);
        $reply = preg_replace('/\n{3,}/', "\n\n", $reply) ?? $reply;

        $forbiddenPhrases = [
            'i looked', 'i see information about', 'let me look',
            'looking at the chunks', 'looking at the context',
            'the context mentions', 'from the context', 'from the provided context',
            'based on the context',
        ];

        foreach ($forbiddenPhrases as $phrase) {
            // Also eat a trailing comma/space so removal doesn't leave the
            // sentence starting with stray punctuation.
            $reply = preg_replace('/\b' . preg_quote($phrase, '/') . '\b\s*,?\s*/i', '', $reply) ?? $reply;
        }

        // Clean up any leftover punctuation/spacing left behind by the
        // removals above (e.g. a line now starting with ", " or a dangling
        // space before punctuation).
        $reply = preg_replace('/^[ \t]*[,;:.]+[ \t]*/m', '', $reply) ?? $reply;
        $reply = preg_replace('/[ \t]+([,.!?;:])/u', '$1', $reply) ?? $reply;

        $reply = preg_replace('/[ \t]{2,}/u', ' ', $reply) ?? $reply;
        $reply = preg_replace('/\n{2,}/', "\n\n", $reply) ?? $reply;
        $reply = trim($reply);

        if ($isAiGenerated) {
            return $reply;
        }

        // Raw context text needs a bit more structure: break long single-paragraph
        // text into readable chunks and add a step-list lead-in for procedural asks.
        if (!str_contains($reply, "\n") && mb_strlen($reply) > 220) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $reply) ?: [];
            $sentences = array_values(array_filter(array_map('trim', $sentences), fn ($s) => $s !== ''));

            if (count($sentences) > 2) {
                $reply = implode("\n\n", $sentences);
            }
        }

        $normalized = mb_strtolower(trim($message));
        $isProcedural = str_contains($normalized, 'password')
            || str_contains($normalized, 'kata sandi')
            || str_contains($normalized, 'refresh')
            || str_contains($normalized, 'reset')
            || str_contains($normalized, 'restart')
            || str_contains($normalized, 'mulai ulang');

        if ($isProcedural && preg_match('/^(berikut langkah|langkah-langkah|langkah|untuk|to )/i', $reply) === 0) {
            $reply = "Berikut langkah yang bisa Anda ikuti:\n\n" . $reply;
        }

        return trim($reply);
    }

    private function buildGreetingReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? 'Hello! I can help you find the right answer from the available knowledge. Please describe the issue you are facing and I will guide you step by step.'
            : 'Halo! Saya siap membantu Anda mencari jawaban dari panduan yang tersedia. Jelaskan masalah Anda, lalu saya akan bantu dengan langkah yang jelas.';
    }

    private function buildThanksReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? 'You’re welcome. If you need anything else, just tell me what issue you are facing.'
            : 'Sama-sama. Kalau ada hal lain yang ingin Anda tanyakan, silakan jelaskan masalahnya.';
    }

    private function buildAdminReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? "I can help you connect to an admin. Please choose one option:\n1) Chat Admin\n2) Send Email to eyrehypercon@gmail.com"
            : "Saya bisa membantu mengarahkan Anda ke admin. Silakan pilih opsi berikut:\n1) Chat Admin\n2) Kirim Email ke eyrehypercon@gmail.com";
    }

    private function buildEscalationReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? "I’m not fully sure I can answer this from the available knowledge yet. Please choose one option:\n1) Chat Admin\n2) Send Email to eyrehypercon@gmail.com"
            : "Saya belum yakin bisa menjawab pertanyaan ini sepenuhnya dari pengetahuan yang tersedia saat ini. Silakan pilih opsi berikut:\n1) Chat Admin\n2) Kirim Email ke eyrehypercon@gmail.com";
    }

    private function detectLanguage(string $text): string
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return 'indonesia';
        }

        $indonesianMarkers = [
            'halo', 'hai', 'terima kasih', 'makasih', 'tolong', 'mohon', 'bantu', 'bagaimana',
            'cara', 'apa', 'apakah', 'kenapa', 'mengapa', 'dimana', 'kapan', 'siapa', 'saya',
            'anda', 'kami', 'silakan', 'langkah', 'gunakan', 'pakai', 'login', 'akun', 'server',
            'jaringan', 'kirim', 'email', 'admin', 'perlu', 'bisakah', 'gimana', 'buat',
        ];

        $englishMarkers = [
            'hello', 'hi', 'thank you', 'thanks', 'please', 'how', 'what', 'where', 'when', 'who',
            'why', 'can you', 'guide', 'step', 'steps', 'support', 'contact', 'admin', 'email',
            'login', 'account', 'server', 'network', 'vpn', 'help',
        ];

        $idScore = 0;
        $enScore = 0;

        foreach ($indonesianMarkers as $word) {
            if (str_contains($text, $word)) {
                $idScore++;
            }
        }

        foreach ($englishMarkers as $word) {
            if (str_contains($text, $word)) {
                $enScore++;
            }
        }

        return $enScore > $idScore ? 'english' : 'indonesia';
    }
}