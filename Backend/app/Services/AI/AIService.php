<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

class AIService implements AIServiceInterface
{
    public function generateResponse(
        string $question,
        string $context
    ): string
    {
        $prompt = "
Kamu adalah Customer Service perusahaan.

Jawab HANYA berdasarkan informasi berikut.

========================
INFORMASI
========================

$context

========================
PERTANYAAN
========================

$question

========================
ATURAN
========================

- Jangan mengarang.
- Jangan memakai pengetahuan lain.
- Jika tidak ada jawabannya, balas:
Mohon maaf, informasi tersebut belum tersedia pada manual perusahaan.
- Maksimal 5 kalimat.
";

        try {

            $response = Http::timeout(60)
                ->connectTimeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'HTTP-Referer'  => env('APP_URL'),
                    'X-Title'       => env('APP_NAME'),
                    'Content-Type'  => 'application/json',
                ])
                ->post(
                    'https://openrouter.ai/api/v1/chat/completions',
                    [

                        // GANTI MODEL
                        'model' => 'nvidia/nemotron-3-ultra-550b-a55b:free',

                        'messages' => [

                            [
                                'role' => 'system',
                                'content' => 'Kamu adalah Customer Service perusahaan.'
                            ],

                            [
                                'role' => 'user',
                                'content' => $prompt
                            ]

                        ],

                        'temperature' => 0.2,
                        'max_tokens' => 300

                    ]
                );

            if (!$response->successful()) {
                return "OpenRouter Error : ".$response->body();
            }

            $json = $response->json();

            // Ambil jawaban AI
            $reply = data_get(
                $json,
                'choices.0.message.content'
            );

            if (!empty($reply)) {
                return trim($reply);
            }

            return "AI tidak memberikan jawaban.";

        } catch (\Throwable $e) {

            return "Server AI Error : ".$e->getMessage();

        }
    }
}