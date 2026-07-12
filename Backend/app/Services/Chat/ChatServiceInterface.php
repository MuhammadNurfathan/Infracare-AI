<?php

namespace App\Services\Chat;

interface ChatServiceInterface
{
    /**
     * Memproses pertanyaan customer dan mengembalikan jawaban.
     */
    public function handle(string $message): string;
}