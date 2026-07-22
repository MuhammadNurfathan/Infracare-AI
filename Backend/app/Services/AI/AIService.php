<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService implements AIServiceInterface
{
    /**
     * Returns the AI-generated answer, or null if no genuine answer could be
     * produced (missing key, API error, empty/blocked reply, exception).
     * Returning null instead of a "fallback sentence" string means callers
     * don't have to pattern-match text to detect failure.
     */
    public function generateResponse(string $question, string $context): ?string
    {
        $start = microtime(true);

        $context = mb_substr(trim($context), 0, 60000);
        $language = $this->detectLanguage($question);

        $languageInstruction = $language === 'indonesia'
            ? 'Reply in Indonesian.'
            : 'Reply in English.';

        $prompt = $this->buildPrompt($question, $context, $languageInstruction);
        $cacheKey = 'ai_' . md5($question . $context);

        // Cache::remember treats a null callback result as a miss, so a failed
        // call is naturally retried on the next request instead of being
        // stuck "cached as broken" for 30 minutes.
        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($prompt, $start) {
            $apiKey = trim((string) env('OPENROUTER_API_KEY'));

            if ($apiKey === '') {
                Log::warning('OpenRouter API key is not configured');

                return null;
            }

            foreach ($this->getModelList() as $model) {
                $reply = $this->callModel($model, $apiKey, $prompt, $start);

                if ($reply !== null) {
                    return $reply;
                }
            }

            Log::error('All configured OpenRouter models failed for this request');

            return null;
        });
    }

    /**
     * Reads OPENROUTER_MODELS (comma-separated priority list) if set, falling
     * back to the single OPENROUTER_MODEL for backward compatibility. Put at
     * least one paid model last in the list — free models can all be
     * rate-limited/overloaded/unavailable at the same time, a paid model is
     * the only thing that reliably won't be.
     */
    private function getModelList(): array
    {
        $list = trim((string) env('OPENROUTER_MODELS'));

        if ($list !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $list))));
        }

        $single = trim((string) env('OPENROUTER_MODEL'));

        return $single !== '' ? [$single] : [];
    }

    /**
     * Attempts one model. Returns the formatted reply on success, or null on
     * any failure (so the caller can move on to the next model in the list).
     */
    private function callModel(string $model, string $apiKey, string $prompt, float $start): ?string
    {
        try {
          $response = Http::timeout((int) env('OPENROUTER_TIMEOUT', 60))
    ->connectTimeout((int) env('OPENROUTER_CONNECT_TIMEOUT', 10))
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'HTTP-Referer' => env('APP_URL'),
                    'X-Title' => env('APP_NAME'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a professional customer service assistant.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    // NOTE: do NOT force 'reasoning' => ['enabled' => false] here.
                    // Some models (e.g. Gemma) reject the request outright with a
                    // 400 if reasoning is disabled — it's mandatory for them.
                    // Others (e.g. Hy3) silently spend the whole token budget on
                    // reasoning if left default. There's no single setting that's
                    // safe for every model, so we don't touch it and instead give
                    // enough max_tokens headroom to survive either behavior.
                    'temperature' => 0,
                    'top_p' => 1,
                    'max_tokens' => 1500,
                    'frequency_penalty' => 0,
                    'presence_penalty' => 0,
                    'seed' => 123,
                ]);

            if (!$response->successful()) {
                $status = $response->status();

                Log::warning('OpenRouter model failed, trying next', [
                    'model' => $model,
                    'status' => $status,
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $reply = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            if ($reply === '') {
                Log::warning('OpenRouter model returned an empty completion, trying next', [
                    'model' => $model,
                    'raw' => $response->json(),
                ]);

                return null;
            }

            if ($this->containsBlockedWord($reply)) {
                Log::warning('AI reply contained a blocked word, discarding', [
                    'model' => $model,
                    'preview' => mb_substr($reply, 0, 200),
                ]);

                return null;
            }

            Log::info('AI Response', [
                'model' => $model,
                'time_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);

            return $this->formatForWhatsApp($reply);
        } catch (\Throwable $e) {
            Log::warning('OpenRouter model threw an exception, trying next', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildPrompt(string $question, string $context, string $languageInstruction): string
    {
        return <<<PROMPT
You are a professional customer service assistant.

Rules:
- Answer ONLY using INFORMATION from the provided context. Never guess, never use outside knowledge.
- Never mention AI, ChatGPT, PDF, documents, manuals, Laravel, backend ticketing, thesis, or final project.
- {$languageInstruction}
- If the information is spread across multiple parts of the context, combine it into one coherent, complete answer.
- Write in simple language. Use short paragraphs, and numbered steps for procedures — one step per line.
- Do NOT use markdown headings (#, ##, ###). Do not use markdown tables. Plain paragraphs and numbered/bulleted lists only.
- Separate distinct ideas or steps with a blank line so the message is easy to read in a chat window.
- If the context contains section references or partial instructions, summarize them clearly instead of telling the user to read the manual.
- If the information is incomplete, still give the most helpful answer available from the context rather than giving up.
- Do not mention internal searching, chunking, or context retrieval in the final answer.
- For password or managed-account questions, clearly separate the normal case and the third-party server case when both appear in the context.

=========================
INFORMATION
=========================

{$context}

=========================
QUESTION
=========================

{$question}

PROMPT;
    }

    private function containsBlockedWord(string $reply): bool
    {
        $replyLower = mb_strtolower($reply);

        $blockedWords = [
            'chatgpt', 'openai', 'laravel', 'backend ticketing', 'thesis', 'final project',
            'according to the document', 'according to the manual', 'according to the pdf', 'pdf',
            'silakan baca manual', 'harus membaca manual', 'baca manual', 'refer to the manual',
        ];

        foreach ($blockedWords as $word) {
            if (str_contains($replyLower, $word)) {
                return true;
            }
        }

        return false;
    }

    private function detectLanguage(string $text): string
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return 'indonesia';
        }

        $indonesian = [
            'apa', 'bagaimana', 'cara', 'tolong', 'mohon', 'bisa', 'apakah', 'kenapa', 'mengapa',
            'dimana', 'kapan', 'siapa', 'halo', 'hai', 'terima kasih', 'makasih', 'selamat',
            'langkah', 'gunakan', 'pakai', 'login', 'akun', 'jaringan', 'server', 'kirim', 'email',
        ];

        $english = [
            'what', 'how', 'where', 'when', 'who', 'why', 'please', 'thanks', 'thank you', 'hello',
            'hi', 'guide', 'step', 'steps', 'login', 'account', 'network', 'server', 'vpn', 'help',
        ];

        $idScore = 0;
        $enScore = 0;

        foreach ($indonesian as $word) {
            if (str_contains($text, $word)) {
                $idScore++;
            }
        }

        foreach ($english as $word) {
            if (str_contains($text, $word)) {
                $enScore++;
            }
        }

        return $enScore > $idScore ? 'english' : 'indonesia';
    }

    /**
     * Convert the model's markdown-ish output into something clean for WhatsApp.
     *
     * NOTE: earlier versions of this method had regexes that actually deleted
     * content (e.g. stripping trailing punctuation, or turning "**bold**" into
     * a literal "**"). Every replacement below keeps the captured text.
     *
     * FIX (layout bug): the previous version dropped every blank line while
     * cleaning noise lines, which merged all paragraphs/list items into one
     * dense block with no line breaks between them. Blank lines are now kept
     * as paragraph separators and only collapsed (not deleted) if excessive.
     */
    private function formatForWhatsApp(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        // Literal "\n" sequences that sometimes leak through from the API response.
        $text = str_replace('\n', "\n", $text);

        // Markdown bold -> WhatsApp bold. Keeps the wrapped text (was dropping it before).
        $text = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $text) ?? $text;

        // Markdown headings ("# Title", "## Title") -> plain bold line, no
        // literal "#" characters leaking into the chat.
        $text = preg_replace('/^#{1,6}\s*(.+)$/mu', '*$1*', $text) ?? $text;

        // "Step 1: ..." / "Langkah 1: ..." -> "1. ..."
        $text = preg_replace('/\b(?:Step|Langkah)\s+(\d+)\s*[:.]?\s*/iu', '$1. ', $text) ?? $text;

        // Trim whitespace before punctuation WITHOUT deleting the punctuation.
        $text = preg_replace('/[ \t]+([,.!?;:])/u', '$1', $text) ?? $text;

        $lines = preg_split('/\n/', $text) ?: [];
        $cleanLines = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            $line = preg_replace('/[ \t]+/', ' ', $line) ?? $line;

            if ($line === '') {
                // Keep as a paragraph separator instead of discarding it —
                // this is what makes the reply readable in a chat window.
                $cleanLines[] = '';
                continue;
            }

            if (preg_match('/^(issue|copyright|end|----end|page)\b/i', $line) === 1) {
                continue;
            }

            if (preg_match('/^\d+\.?$/', $line) === 1) {
                continue;
            }

            if (preg_match('/^\[chunk\s*\d+\]/i', $line) === 1) {
                continue;
            }

            $cleanLines[] = $line;
        }

        $text = implode("\n", $cleanLines);

        // Collapse 3+ blank lines down to a single blank line, and trim any
        // leading/trailing blank lines left over from the noise filtering above.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/^\n+|\n+$/', '', $text) ?? $text;

        // Put numbered steps and bullets on their own line. Includes "⚫",
        // the bullet glyph used in the source manuals — the AI sometimes
        // echoes it back verbatim from the context it was given.
        $text = preg_replace('/(?<!\n)(\d+\.\s)/u', "\n$1", $text) ?? $text;
        $text = preg_replace('/(?<!\n)([-•⚫])\s+/u', "\n- ", $text) ?? $text;
        $text = preg_replace('/^[⚫•]\s*/mu', '- ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}