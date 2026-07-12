<?php

namespace App\Services\Search;

use App\Models\Document;

class SearchService implements SearchServiceInterface
{
    public function search(string $question): ?Document
    {
        // Ubah ke huruf kecil
        $question = strtolower($question);

        // Pecah menjadi keyword
        $keywords = preg_split('/\s+/', $question);

        // Stopword sederhana
        $stopWords = [
            'yang',
            'dan',
            'atau',
            'di',
            'ke',
            'dari',
            'cara',
            'bagaimana',
            'apa',
            'apakah',
            'untuk',
            'dengan',
            'itu',
            'ini',
            'ada',
            'saya',
            'kami'
        ];

        $keywords = array_filter($keywords, function ($word) use ($stopWords) {

            return strlen($word) >= 3 &&
                !in_array($word, $stopWords);

        });

        $documents = Document::all();

        $bestDocument = null;

        $highestScore = 0;

        foreach ($documents as $document) {

            $score = 0;

            $text = strtolower(
                $document->title .
                ' ' .
                $document->content
            );

            foreach ($keywords as $keyword) {

                if (str_contains($text, $keyword)) {

                    $score++;

                }

            }

            if ($score > $highestScore) {

                $highestScore = $score;

                $bestDocument = $document;

            }

        }

        return $bestDocument;
    }
}