<?php

namespace App\Services\Intent;

class ServiceIntent implements ServiceIntentInterface
{
    public function detect(string $message): string
    {
        $message = mb_strtolower(trim($message ?? ''));
        $message = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message);

        /*
        |--------------------------------------------------------------------------
        | Greeting
        |--------------------------------------------------------------------------
        */

        $greetings = [
            'halo',
            'hallo',
            'hai',
            'hi',
            'hello',
            'hy',
            'hey',
            'pagi',
            'siang',
            'sore',
            'malam',
            'permisi',
            'assalamualaikum',
            'assalamu alaikum',
            'selamat pagi',
            'selamat siang',
            'selamat sore',
            'selamat malam',
            'salam',
        ];

        foreach ($greetings as $word) {
            if (str_contains($message, $word)) {
                return 'greeting';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Thanks
        |--------------------------------------------------------------------------
        */

        $thanks = [
            'terima kasih',
            'makasih',
            'makasih ya',
            'makasih banyak',
            'thanks',
            'thank you',
            'thx',
            'ty',
            'sip makasih',
            'oke makasih',
            'terima kasih banyak',
        ];

        foreach ($thanks as $word) {
            if (str_contains($message, $word)) {
                return 'thanks';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Admin / Human
        |--------------------------------------------------------------------------
        */

        $admins = [
            'admin',
            'cs',
            'customer service',
            'customer support',
            'support',
            'tim support',
            'operator',
            'human',
            'orang',
            'staff',
            'pegawai',
            'teknisi',
            'hubungi admin',
            'hubungi cs',
            'hubungi support',
            'bicara admin',
            'bicara cs',
            'bicara support',
            'chat admin',
            'chat dengan admin',
            'contact admin',
            'contact support',
            'talk to admin',
            'live agent',
            'agent',
        ];

        foreach ($admins as $word) {
            if (str_contains($message, $word)) {
                return 'admin';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Default
        |--------------------------------------------------------------------------
        */

        return 'question';
    }
}