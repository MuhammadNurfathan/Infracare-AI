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
            try {
                $apiKey = trim((string) env('OPENROUTER_API_KEY'));

                if ($apiKey === '') {
                    Log::warning('OpenRouter API key is not configured');

                    return null;
                }

                $response = Http::timeout(15)
                    ->connectTimeout(5)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $apiKey,
                        'HTTP-Referer' => env('APP_URL'),
                        'X-Title' => env('APP_NAME'),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ])
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => env('OPENROUTER_MODEL'),
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a professional customer service assistant.'],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        // Some free/reasoning models spend the whole max_tokens
                        // budget on hidden reasoning and return an empty final
                        // answer. Explicitly disable it for a support chatbot.
                        'reasoning' => ['enabled' => false],
                        'temperature' => 0,
                        'top_p' => 1,
                        'max_tokens' => 900,
                        'frequency_penalty' => 0,
                        'presence_penalty' => 0,
                        'seed' => 123,
                    ]);

                if (!$response->successful()) {
                    $status = $response->status();

                    Log::error('OpenRouter Error', [
                        'status' => $status,
                        'body' => mb_substr($response->body(), 0, 500),
                    ]);

                    if (in_array($status, [402, 429], true)) {
                        Log::warning('OpenRouter quota/rate-limit hit — check credits or switch OPENROUTER_MODEL', [
                            'model' => env('OPENROUTER_MODEL'),
                        ]);
                    }

                    return null;
                }

                $reply = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

                if ($reply === '') {
                    Log::warning('OpenRouter returned an empty completion', [
                        'model' => env('OPENROUTER_MODEL'),
                        'raw' => $response->json(),
                    ]);

                    return null;
                }

                if ($this->containsBlockedWord($reply)) {
                    Log::warning('AI reply contained a blocked word, discarding', [
                        'preview' => mb_substr($reply, 0, 200),
                    ]);

                    return null;
                }

                Log::info('AI Response', [
                    'time_ms' => round((microtime(true) - $start) * 1000, 2),
                ]);

                return $this->formatForWhatsApp($reply);
            } catch (\Throwable $e) {
                Log::error('AI Exception', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return null;
            }
        });
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
     */
    private function formatForWhatsApp(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        // Literal "\n" sequences that sometimes leak through from the API response.
        $text = str_replace('\n', "\n", $text);

        // Markdown bold -> WhatsApp bold. Keeps the wrapped text (was dropping it before).
        $text = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $text) ?? $text;

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

        // Put numbered steps and bullets on their own line.
        $text = preg_replace('/(?<!\n)(\d+\.\s)/u', "\n$1", $text) ?? $text;
        $text = preg_replace('/(?<!\n)([-•])\s+/u', "\n- ", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}