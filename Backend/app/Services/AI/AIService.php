<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService implements AIServiceInterface
{
    public function generateResponse(
        string $question,
        string $context
    ): string {

        $start = microtime(true);

        $context = mb_substr(trim($context), 0, 22000);

        $language = $this->detectLanguage($question);

        $languageInstruction = $language === 'indonesia'
            ? 'Reply in Indonesian.'
            : 'Reply in English.';

        $fallbackMessage = $language === 'indonesia'
            ? 'Informasi yang tersedia belum cukup untuk memberikan langkah rinci, tetapi saya akan rangkum poin-poin yang paling relevan dari konteks yang ada.'
            : 'The available information is not detailed enough to provide full step-by-step instructions, but I will summarize the most relevant points from the context.';

        $prompt = <<<PROMPT
You are a professional customer service assistant.

Rules:

- Answer ONLY using INFORMATION from the provided context.
- Never guess.
- Never use outside knowledge.
- Never mention AI, ChatGPT, PDF, documents, manuals, Laravel, backend ticketing, thesis or final project.
- {$languageInstruction}
- Prefer a rich, helpful answer that uses as much relevant context as possible.
- If the information is spread across multiple parts of the context, combine them into one coherent and complete answer.
- Write in simple, easy-to-understand language and keep the layout tidy.
- Make the response feel natural and helpful, like a support assistant, not like a copied excerpt.
- Use short paragraphs, bullet points, and numbered steps where appropriate.
- If the customer asks for steps, answer using numbered steps and put each step on its own line.
- If the context contains section references or partial instructions, summarize the relevant part clearly and helpfully instead of telling the user to read the manual.
- If the information is incomplete, still provide the most helpful answer available from the context instead of giving up immediately.
- Do not say that the user must read the manual book or refer to the manual when a useful summary can be extracted from the context.
- Do not mention internal searching, chunking, context retrieval, or similar process words in the final answer.
- If the context is completely unrelated or empty, use the fallback line below.
- For procedural questions, include the most relevant steps even if they are not fully complete, rather than stopping early.
- If the user asks in Indonesian, answer in Indonesian. If the user asks in English, answer in English.
- Prefer a polished response with a clear intro sentence, a concise explanation, and clean step-by-step structure.
- For password or management-account questions, clearly separate the normal case and the third-party server case when both are present in the context.

{$fallbackMessage}

=========================
INFORMATION
=========================

{$context}

=========================
QUESTION
=========================

{$question}

PROMPT;

        $cacheKey = 'ai_' . md5($question . $context);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(30),

            function () use (

                $prompt,
                $fallbackMessage,
                $start

            ) {

                try {

                    $apiKey = trim((string) env('OPENROUTER_API_KEY'));

                    if ($apiKey === '') {
                        return $fallbackMessage;
                    }

                    $response = Http::timeout(8)
                        ->connectTimeout(2)
                        ->retry(1, 200)
                        ->withHeaders([

                            'Authorization' => 'Bearer ' . $apiKey,
                            'HTTP-Referer'  => env('APP_URL'),
                            'X-Title'       => env('APP_NAME'),
                            'Content-Type'  => 'application/json',
                            'Accept'        => 'application/json',

                        ])
                                                ->post(
                            'https://openrouter.ai/api/v1/chat/completions',
                            [

                                'model' => env('OPENROUTER_MODEL'),

                                'messages' => [

                                    [
                                        'role' => 'system',
                                        'content' => 'You are a professional customer service assistant.'
                                    ],

                                    [
                                        'role' => 'user',
                                        'content' => $prompt
                                    ]

                                ],

                                'temperature' => 0,

                                'top_p' => 1,

                                'max_tokens' => 260,

                                'frequency_penalty' => 0,

                                'presence_penalty' => 0,

                                'seed' => 123,

                            ]
                        );

                    if (!$response->successful()) {

                        Log::error('OpenRouter Error', [

                            'status' => $response->status(),

                            'body' => $response->body(),

                        ]);

                        return "Mohon maaf, saat ini sistem sedang mengalami gangguan. Silakan coba beberapa saat lagi.";

                    }

                    $reply = trim((string) data_get(
                        $response->json(),
                        'choices.0.message.content',
                        ''
                    ));

                    if ($reply === '') {

                        return $fallbackMessage;

                    }

                    $replyLower = mb_strtolower($reply);

                    $blockedWords = [

                        'chatgpt',
                        'openai',
                        'laravel',
                        'backend ticketing',
                        'thesis',
                        'final project',
                        'according to the document',
                        'according to the manual',
                        'according to the pdf',
                        'pdf',
                        'silakan baca manual',
                        'harus membaca manual',
                        'baca manual',
                        'refer to the manual',
                    ];

                    foreach ($blockedWords as $word) {

                        if (str_contains($replyLower, $word)) {

                            return $fallbackMessage;

                        }

                    }

                    Log::info('AI Response', [

                        'time_ms' => round(
                            (microtime(true) - $start) * 1000,
                            2
                        ),

                        'tokens_limit' => 120,

                    ]);

                    return $reply;
                                    } catch (\Throwable $e) {

                    Log::error('AI Exception', [

                        'message' => $e->getMessage(),

                        'file' => $e->getFile(),

                        'line' => $e->getLine(),

                        'trace' => $e->getTraceAsString(),

                    ]);

                    return "Mohon maaf, saat ini sistem sedang mengalami gangguan. Silakan coba beberapa saat lagi.";

                }

            }

        );

    }
        private function detectLanguage(string $text): string
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return 'indonesia';
        }

        $indonesian = [
            'apa',
            'bagaimana',
            'cara',
            'tolong',
            'mohon',
            'bisa',
            'apakah',
            'kenapa',
            'mengapa',
            'dimana',
            'kapan',
            'siapa',
            'halo',
            'hai',
            'terima kasih',
            'makasih',
            'selamat',
            'langkah',
            'gunakan',
            'pakai',
            'login',
            'akun',
            'jaringan',
            'server',
            'kirim',
            'email'
        ];

        $english = [
            'what',
            'how',
            'where',
            'when',
            'who',
            'why',
            'please',
            'thanks',
            'thank you',
            'hello',
            'hi',
            'guide',
            'step',
            'steps',
            'login',
            'account',
            'network',
            'server',
            'vpn',
            'help',
            'please wait',
            'contact admin',
            'send email'
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

        $hasIndonesianPattern = preg_match('/\b(apa|bagaimana|cara|tolong|mohon|bisa|apakah|kenapa|mengapa|dimana|kapan|siapa|halo|hai|terima|makasih|selamat|langkah|gunakan|pakai|login|akun|jaringan|server|kirim|email)\b/i', $text) === 1;
        $hasEnglishPattern = preg_match('/\b(what|how|where|when|who|why|please|thanks|hello|hi|guide|step|steps|support|contact|admin|email|login|account|network|vpn|help)\b/i', $text) === 1;

        if ($hasIndonesianPattern && $idScore >= $enScore) {
            return 'indonesia';
        }

        if ($hasEnglishPattern && $enScore > $idScore) {
            return 'english';
        }

        return $idScore >= $enScore
            ? 'indonesia'
            : 'english';
    }
}