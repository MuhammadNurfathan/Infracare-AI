<?php

namespace App\Services\Chat;

use App\Services\AI\AIServiceInterface;
use App\Services\Search\SearchServiceInterface;

class ChatService implements ChatServiceInterface
{
    public function __construct(
        private SearchServiceInterface $searchService,
        private AIServiceInterface $aiService
    ) {}

    public function handle(string $message): string
    {
        $document = $this->searchService->search($message);

        if (!$document) {
            return "Maaf, saya belum menemukan informasi yang sesuai pada manual perusahaan.";
        }

        return $this->aiService->generateResponse(
            $message,
            $document->content
        );
    }
}