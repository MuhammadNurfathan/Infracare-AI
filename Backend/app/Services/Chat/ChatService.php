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
            [
                'name' => $name,
                'last_chat_at' => now(),
            ]
        );

        $conversation = Conversation::firstOrCreate(
            [
                'customer_id' => $customer->id,
                'status' => 'open',
            ],
            [
                'started_at' => now(),
            ]
        );

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'message' => $message !== '' ? $message : '(pesan kosong)',
        ]);

        $intent = $this->intentService->detect($message);

        if ($intent === 'greeting') {
            $reply = $this->buildGreetingReply($message);
            $confidence = 92;
            $shouldEscalate = false;

            Message::create([
                'conversation_id' => $conversation->id,
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

        if ($intent === 'thanks') {
            $reply = $this->buildThanksReply($message);
            $confidence = 90;
            $shouldEscalate = false;

            Message::create([
                'conversation_id' => $conversation->id,
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

        if ($intent === 'admin') {
            $reply = $this->buildAdminReply($message);
            $confidence = 88;
            $shouldEscalate = true;

            Message::create([
                'conversation_id' => $conversation->id,
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
        $hasMeaningfulContext = $contextChunks->isNotEmpty() && ($bestScore >= 20 || $contextChunks->count() >= 2);

        $reply = $hasMeaningfulContext
            ? $this->tryGenerateFastReply($message, $context, $contextChunks)
            : 'Mohon maaf, informasi yang Anda butuhkan belum tersedia pada manual perusahaan.';

        $replyLower = mb_strtolower($reply);
        $fallbackLike = str_contains($replyLower, 'informasi yang diminta tidak tersedia')
            || str_contains($replyLower, 'tidak tersedia pada manual')
            || str_contains($replyLower, 'saat ini sistem sedang mengalami gangguan')
            || str_contains($replyLower, 'silakan coba beberapa saat lagi')
            || str_contains($replyLower, 'belum tersedia')
            || str_contains($replyLower, 'belum bisa')
            || str_contains($replyLower, 'maaf, saya belum bisa')
            || str_contains($replyLower, 'sistem sedang mengalami gangguan');

        if ($fallbackLike && $context !== '') {
            $reply = $this->buildContextReply($message, $contextChunks);
        }

        $reply = $this->formatReadableReply($reply);
        $reply = $this->improveUserFriendlyLayout($reply, $message);

        $confidence = 0;
        $shouldEscalate = false;

        if ($hasMeaningfulContext && !$fallbackLike) {
            $confidence = 78;
        } elseif ($contextChunks->isNotEmpty() && $bestScore >= 10) {
            $confidence = 55;
            $shouldEscalate = true;
        } else {
            $confidence = 20;
            $shouldEscalate = true;
        }

        if ($fallbackLike) {
            $shouldEscalate = true;
            $confidence = 35;
        }

        if ($contextChunks->isEmpty() || $bestScore < 15) {
            $reply = $this->buildEscalationReply($message);
            $shouldEscalate = true;
            $confidence = 40;
        }

        Message::create([
            'conversation_id' => $conversation->id,
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

    private function tryGenerateFastReply(string $message, string $context, $contextChunks): string
    {
        try {
            $reply = $this->aiService->generateResponse($message, $context);
            $replyLower = mb_strtolower($reply);
            $fallbackLike = str_contains($replyLower, 'informasi yang diminta tidak tersedia')
                || str_contains($replyLower, 'tidak tersedia pada manual')
                || str_contains($replyLower, 'saat ini sistem sedang mengalami gangguan')
                || str_contains($replyLower, 'silakan coba beberapa saat lagi')
                || str_contains($replyLower, 'belum tersedia')
                || str_contains($replyLower, 'belum bisa')
                || str_contains($replyLower, 'maaf, saya belum bisa')
                || str_contains($replyLower, 'sistem sedang mengalami gangguan');

            if ($fallbackLike) {
                return $this->buildContextReply($message, $contextChunks);
            }

            return $reply;
        } catch (\Throwable $e) {
            return $this->buildContextReply($message, $contextChunks);
        }
    }

    private function buildContextReply(string $message, $contextChunks): string
    {
        $normalizedQuestion = mb_strtolower(trim($message));
        $normalizedQuestion = preg_replace('/[^a-z0-9\s]/u', ' ', $normalizedQuestion);
        $normalizedQuestion = preg_replace('/\s+/', ' ', trim($normalizedQuestion));
        $keywords = array_values(array_filter(preg_split('/\s+/', $normalizedQuestion) ?: [], fn ($word) => mb_strlen($word) >= 3));

        $candidate = $contextChunks->first(function ($chunk) use ($keywords) {
            $content = mb_strtolower((string) $chunk);
            foreach ($keywords as $keyword) {
                if (str_contains($content, $keyword)) {
                    return true;
                }
            }

            return false;
        });

        $selected = $candidate ?? $contextChunks->first();
        if ($selected === null) {
            return 'Mohon maaf, informasi yang Anda butuhkan belum tersedia pada manual perusahaan.';
        }

        $reply = trim((string) $selected);
        $reply = preg_replace('/\s+/', ' ', $reply);

        return mb_substr($reply, 0, 900);
    }

    private function formatReadableReply(string $reply): string
    {
        $reply = trim($reply);
        $reply = str_replace(["\r\n", "\r"], "\n", $reply);
        $reply = preg_replace('/[ \t]+/', ' ', $reply) ?? $reply;
        $reply = preg_replace('/\n{3,}/', "\n\n", $reply) ?? $reply;

        if (preg_match('/\n/', $reply) === 0 && mb_strlen($reply) > 220) {
            $parts = preg_split('/(?<=[.!?])\s+/', $reply);
            $cleanParts = array_values(array_filter(array_map('trim', $parts), fn ($item) => $item !== ''));

            if (count($cleanParts) > 2) {
                $reply = implode("\n\n", $cleanParts);
            }
        }

        $reply = preg_replace('/(^|\n)(\d+)\.\s+/u', "$1$2. ", $reply);
        $reply = preg_replace('/(^|\n)(-|•)\s+/u', "$1- ", $reply);
        $reply = preg_replace('/\n{2,}/', "\n\n", $reply) ?? $reply;

        return trim($reply);
    }

    private function improveUserFriendlyLayout(string $reply, string $message): string
    {
        $normalized = mb_strtolower(trim($message));
        $isProcedural = str_contains($normalized, 'password')
            || str_contains($normalized, 'kata sandi')
            || str_contains($normalized, 'refresh')
            || str_contains($normalized, 'reset')
            || str_contains($normalized, 'managed account')
            || str_contains($normalized, 'management password');

        if (!$isProcedural) {
            return $reply;
        }

        $reply = preg_replace('/\n\s*\n/', "\n", $reply) ?? $reply;
        $reply = preg_replace('/(?<=\.)\s*(?=\d)/u', "\n", $reply) ?? $reply;

        if (preg_match('/^to refresh|^untuk refresh|^untuk mereset|^untuk mengubah/i', $reply) === 0) {
            $prefix = "Berikut langkah yang bisa Anda ikuti:\n\n";
            $reply = $prefix . $reply;
        }

        return trim($reply);
    }

    private function buildGreetingReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? "Hello! I can help you find the right answer from the available knowledge. Please describe the issue you are facing and I will guide you step by step."
            : "Halo! Saya siap membantu Anda mencari jawaban dari panduan yang tersedia. Jelaskan masalah Anda, lalu saya akan bantu dengan langkah yang jelas.";
    }

    private function buildThanksReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? "You’re welcome. If you need anything else, just tell me what issue you are facing."
            : "Sama-sama. Kalau ada hal lain yang ingin Anda tanyakan, silakan jelaskan masalahnya.";
    }

    private function buildAdminReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? "I can help you connect to an admin. Please choose one option:\n1) Chat Admin\n2) Send Email to eyre.hypercon@gmail.com"
            : "Saya bisa membantu mengarahkan Anda ke admin. Silakan pilih opsi berikut:\n1) Chat Admin\n2) Kirim Email ke eyre.hypercon@gmail.com";
    }

    private function buildEscalationReply(string $message): string
    {
        $language = $this->detectLanguage($message);

        return $language === 'english'
            ? "I’m not fully sure I can answer this from the available knowledge yet. Please choose one option:\n1) Chat Admin\n2) Send Email to eyre.hypercon@gmail.com"
            : "Saya belum yakin bisa menjawab pertanyaan ini sepenuhnya dari pengetahuan yang tersedia saat ini. Silakan pilih opsi berikut:\n1) Chat Admin\n2) Kirim Email ke eyre.hypercon@gmail.com";
    }

    private function detectLanguage(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $englishWords = ['hello', 'hi', 'thank', 'thanks', 'please', 'support', 'admin', 'email', 'guide', 'step'];
        $indonesianWords = ['halo', 'hai', 'terima kasih', 'makasih', 'tolong', 'mohon', 'bantu', 'admin', 'email', 'langkah', 'cara'];

        $enScore = 0;
        $idScore = 0;

        foreach ($englishWords as $word) {
            if (str_contains($text, $word)) {
                $enScore++;
            }
        }

        foreach ($indonesianWords as $word) {
            if (str_contains($text, $word)) {
                $idScore++;
            }
        }

        return $idScore >= $enScore ? 'indonesia' : 'english';
    }
}

