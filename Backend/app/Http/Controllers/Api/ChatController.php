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
            'phone' => 'required|string',
            'name' => 'nullable|string',
            'message' => 'required|string',
            
        ]);

        $result = $this->chatService->receiveMessage(
            $request->only([
                'phone',
                'name',
                'message'
            ])
        );

        return response()->json([
            'success' => true,
            'reply' => $result['reply'],
            'should_escalate' => $result['should_escalate'],
            'confidence' => $result['confidence'],
        ]);
    }
}