<?php

namespace App\Services\Chat;

use App\Models\Customer;
use App\Models\Conversation;
use App\Models\Message;

use App\Services\AI\AIServiceInterface;
use App\Services\Search\SearchServiceInterface;
use App\Services\Intent\ServiceIntentInterface;

class ChatService implements ChatServiceInterface
{
    public function __construct(
        private SearchServiceInterface $searchService,
        private AIServiceInterface $aiService,
        private ServiceIntentInterface $intentService,
    ) {}

    public function receiveMessage(array $data): string
    {

        /*
        |--------------------------------------------------------------------------
        | CUSTOMER
        |--------------------------------------------------------------------------
        */

        $customer = Customer::updateOrCreate(

            [
                'phone' => $data['phone']
            ],

            [
                'name' => $data['name'] ?? 'Customer',
                'last_chat_at' => now()
            ]

        );

        /*
        |--------------------------------------------------------------------------
        | CONVERSATION
        |--------------------------------------------------------------------------
        */

        $conversation = Conversation::firstOrCreate(

            [
                'customer_id' => $customer->id,
                'status' => 'open'
            ],

            [
                'started_at' => now()
            ]

        );

        /*
        |--------------------------------------------------------------------------
        | SAVE CUSTOMER MESSAGE
        |--------------------------------------------------------------------------
        */

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'message' => $data['message'],
        ]);

        $intent = $this->intentService->detect(
    $data['message']
);

switch ($intent) {

    case 'greeting':

        $reply = "Halo 👋\n\nSelamat datang di Customer Service PT Infracare.\n\nAda yang bisa kami bantu hari ini?";

        break;

    case 'thanks':

        $reply = "Sama-sama 😊\n\nSenang bisa membantu Anda.";

        break;

    case 'admin':

        $reply = "Baik, permintaan Anda akan diteruskan kepada Admin. Mohon tunggu sebentar ya.";

        break;

    default:

        $document = $this->searchService->search(
            $data['message']
        );

        if (!$document) {

            $reply = "Mohon maaf, informasi tersebut belum tersedia pada manual perusahaan.";

        } else {

            $reply = $this->aiService->generateResponse(
                $data['message'],
                $document->content
            );

        }

}
        /*
        |--------------------------------------------------------------------------
        | SEARCH DOCUMENT
        |--------------------------------------------------------------------------
        */

        $document = $this->searchService->search(
            $data['message']
        );

        if (!$document) {

            $reply = "Mohon maaf, informasi tersebut belum tersedia pada manual perusahaan.";

        } else {

            $reply = $this->aiService->generateResponse(
                $data['message'],
                $document->content
            );

        }

        /*
        |--------------------------------------------------------------------------
        | SAVE BOT MESSAGE
        |--------------------------------------------------------------------------
        */

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'message' => $reply,
            'confidence' => $document ? 100 : 0
        ]);

        return $reply;

    }
}