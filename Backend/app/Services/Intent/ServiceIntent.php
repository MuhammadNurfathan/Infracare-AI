<?php

namespace App\Services\Intent;

class ServiceIntent implements ServiceIntentInterface
{
    /**
     * If a greeting/thanks keyword matches but the message still has more
     * than this many "meaningful" words left over (after removing the
     * matched keyword and common filler words), we assume the person rode
     * a real question in on the same message — e.g. "Halo min, gimana cara
     * reset password wifi saya?" — and let it fall through to the knowledge
     * base instead of answering only the greeting.
     *
     * This is the fix for the main bug: the old version used str_contains()
     * anywhere in the message, so ANY message that happened to include a
     * greeting word anywhere (even as a pleasantry before a real question)
     * was answered with just the canned greeting reply and the actual
     * question was silently dropped.
     */
    private const GREETING_LEFTOVER_WORD_LIMIT = 2;

    /**
     * Words that carry no intent signal on their own — ignored when deciding
     * whether a greeting/thanks match "used up" the whole message.
     */
    private const FILLER_WORDS = [
        'ya', 'dong', 'min', 'kak', 'gan', 'bro', 'sis', 'pak', 'bu', 'mas',
        'mbak', 'nih', 'deh', 'sih', 'lah', 'kok', 'tolong', 'mohon', 'permisi',
        'saya', 'aku', 'ini', 'itu', 'yg', 'yang', 'nya', 'dan', 'juga',
    ];

    public function detect(string $message): string
    {
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return 'question';
        }

        /*
        |--------------------------------------------------------------------------
        | Admin / Human — checked first and NOT subject to the short-message
        | gate below, since an explicit request for a human ("saya mau bicara
        | dengan admin karena belum selesai masalahnya") is a strong signal
        | worth honoring even in a longer message. The list below intentionally
        | excludes bare generic words (orang, operator, agent, support) that
        | collide with ordinary technical questions — see class docblock.
        |--------------------------------------------------------------------------
        */

        if ($this->matchesAny($normalized, $this->adminPhrases())) {
            return 'admin';
        }

        /*
        |--------------------------------------------------------------------------
        | Greeting
        |--------------------------------------------------------------------------
        */

        $greetingMatch = $this->firstMatch($normalized, $this->greetingPhrases());

        if ($greetingMatch !== null && $this->isEffectivelyJustThis($normalized, $greetingMatch)) {
            return 'greeting';
        }

        /*
        |--------------------------------------------------------------------------
        | Thanks
        |--------------------------------------------------------------------------
        */

        $thanksMatch = $this->firstMatch($normalized, $this->thanksPhrases());

        if ($thanksMatch !== null && $this->isEffectivelyJustThis($normalized, $thanksMatch)) {
            return 'thanks';
        }

        /*
        |--------------------------------------------------------------------------
        | Default
        |--------------------------------------------------------------------------
        */

        return 'question';
    }

    private function normalize(string $message): string
    {
        $message = mb_strtolower(trim($message ?? ''));
        $message = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $message) ?? $message;

        return trim(preg_replace('/\s+/', ' ', $message) ?? '');
    }

    private function greetingPhrases(): array
    {
        return [
            'assalamualaikum', 'assalamu alaikum', 'selamat pagi', 'selamat siang',
            'selamat sore', 'selamat malam', 'halo', 'hallo', 'hai', 'hello',
            'hey', 'hi', 'hy', 'permisi', 'salam', 'pagi', 'siang', 'sore', 'malam',
        ];
    }

    private function thanksPhrases(): array
    {
        return [
            'terima kasih banyak', 'terima kasih', 'makasih banyak', 'makasih ya',
            'sip makasih', 'oke makasih', 'makasih', 'thank you', 'thanks', 'thx', 'ty',
        ];
    }

    /**
     * Only multi-word or otherwise unambiguous phrases — a bare single word
     * like "orang", "operator", "agent", or "support" is deliberately left
     * out here because each one collides with normal technical questions
     * (telco "operator", browser "user agent", "wifi tidak support 5ghz",
     * "banyak orang mengalami hal ini").
     */
    private function adminPhrases(): array
    {
        return [
            'customer service', 'customer support', 'tim support', 'live agent',
            'hubungi admin', 'hubungi cs', 'hubungi support', 'hubungi operator',
            'bicara admin', 'bicara dengan admin', 'bicara cs', 'bicara dengan cs',
            'bicara support', 'bicara dengan operator', 'chat admin',
            'chat dengan admin', 'contact admin', 'contact support',
            'talk to admin', 'talk to human', 'ngobrol dengan admin',
            'connect ke admin', 'sambungkan ke admin', 'minta admin',
            'mau admin', 'panggil admin', 'panggilkan admin', 'panggil cs',
            'teknisi', 'pegawai', 'staff',
        ];
    }

    /**
     * Returns true if `$phrase` appears in `$text` as a whole word/phrase —
     * i.e. bounded by word boundaries — NOT merely as a substring somewhere
     * inside a longer word. This is what fixes matches like 'hi' firing
     * inside "hilang"/"hingga", 'hy' firing inside "hybrid", or 'cs' firing
     * inside an English word like "topics".
     */
    private function matches(string $text, string $phrase): bool
    {
        $pattern = '/\b' . preg_quote($phrase, '/') . '\b/u';

        return preg_match($pattern, $text) === 1;
    }

    private function matchesAny(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($this->matches($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function firstMatch(string $text, array $phrases): ?string
    {
        foreach ($phrases as $phrase) {
            if ($this->matches($text, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }

    /**
     * True if, after removing the matched phrase and common filler words,
     * the message has little to no real content left — meaning the whole
     * message really was "just" a greeting/thanks, not a real question that
     * happened to start with one.
     */
    private function isEffectivelyJustThis(string $normalized, string $matchedPhrase): bool
    {
        $remaining = trim(preg_replace('/\b' . preg_quote($matchedPhrase, '/') . '\b/u', ' ', $normalized) ?? '');

        $words = array_values(array_filter(
            preg_split('/\s+/', $remaining) ?: [],
            fn ($word) => $word !== '' && !in_array($word, self::FILLER_WORDS, true)
        ));

        return count($words) <= self::GREETING_LEFTOVER_WORD_LIMIT;
    }
}