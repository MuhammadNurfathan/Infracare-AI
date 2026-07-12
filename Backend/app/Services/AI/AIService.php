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

Jawablah pertanyaan customer HANYA berdasarkan informasi berikut.

INFORMASI:
$context

PERTANYAAN:
$question

ATURAN:
- Jangan mengarang.
- Jika jawabannya tidak ada di informasi di atas, jawab:
'Mohon maaf, informasi tersebut belum tersedia pada manual perusahaan.'
- Jawaban maksimal 5 kalimat.
";

        try {

            $response = Http::withHeaders([
    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
    'Content-Type'  => 'application/json',
    'HTTP-Referer'  => env('APP_URL'),
    'X-Title'       => env('APP_NAME'),
])->post(
    'https://openrouter.ai/api/v1/chat/completions',
    [
        'model' => 'tencent/hy3:free',

        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ]
);

            if (!$response->successful()) {
    return json_encode($response->json(), JSON_PRETTY_PRINT);
}

return data_get(
    $response->json(),
    'choices.0.message.content',
    'AI tidak memberikan jawaban.'
);

        } catch (\Throwable $e) {

            return $e->getMessage();

        }
    }
}