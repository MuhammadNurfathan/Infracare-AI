<?php

namespace App\Services\Intent;

class ServiceIntent implements  IntentServiceInterface
{
    public function detect(string $message): string
    {
        $text = strtoLower($message);

        $greetings =
        [
            'halo',
            'hello',
            'hi',
            'hey',
            'selamat pagi',
            'selamat siang',
            'selamat sore',
            'selamat malam'
        ];

        foreach ($greetings as $greeting) {
            if (str_contains($text, $greeting)) {
                return 'greeting';
            }
        }

        $thanks= 
        [
            'terima kasih',
            'makasih',
            'thanks',
            'thank you'
        ];

        foreach ($thanks as $thank) {
            if (str_contains($text, $thank)) {
                return 'thanks';
            }
        }

         $admins = [
            'admin',
            'cs',
            'customer service',
            'orang'
        ];

        foreach ($admins as $word) {

            if (str_contains($text, $word)) {
                return 'admin';
            }

        }

        return 'question';
    }

    }