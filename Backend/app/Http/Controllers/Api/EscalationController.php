<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EscalationController extends Controller
{
    public function sendEmail(Request $request)
    {
        $data = $request->validate([
            'phone' => 'nullable|string',
            'name' => 'nullable|string',
            'message' => 'nullable|string',
            'reply' => 'nullable|string',
        ]);

        $subject = 'Escalation WhatsApp - ' . ($data['phone'] ?? 'unknown');
        $body = "Phone: " . ($data['phone'] ?? '-') . "\n";
        $body .= "Name: " . ($data['name'] ?? '-') . "\n";
        $body .= "Message: " . ($data['message'] ?? '-') . "\n";
        $body .= "Reply: " . ($data['reply'] ?? '-') . "\n";

        try {
            Mail::raw($body, function ($message) use ($subject) {
                $message->to('eyre.hypercon@gmail.com')
                    ->subject($subject);
            });

            Log::info('Escalation email sent', $data);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Escalation email failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false], 500);
        }
    }
}
