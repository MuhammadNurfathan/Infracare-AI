<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Chat\ChatServiceInterface;

class ChatController extends Controller
{
    public function __construct(
        private ChatServiceInterface $chatService
    ) {}

    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string'
        ]);

        $reply = $this->chatService->handle(
            $request->message
        );

        return response()->json([
            'success' => true,
            'message' => $request->message,
            'reply' => $reply
        ]);
    }
}