<?php

namespace App\Http\Controllers\admin;

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {

        $totalCustomers = Customer::count();
        $totalConversations = Conversation::count();
        $totalMessages = Message::count();
        $totalDocuments = Document::count();
        $conversationStatus = Conversation::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $messageStatistics = Message::select('sender', DB::raw('COUNT(*) as total'))->groupBy('sender')->pluck('total', 'sender');
        $totalBotMessages = Message::where('sender','bot')->count();
        $successfulAI = Message::where('sender','bot')->where('confidence','>=',70)->count();
        $aiSuccessRate = 0;

        if($totalBotMessages > 0){$aiSuccessRate = round(($successfulAI / $totalBotMessages) * 100,2);}

        $chatActivity = Message::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total')
            )
            ->where(
                'created_at',
                '>=',
                Carbon::now()->subDays(7)
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Customer terbaru
        $recentCustomers = Customer::latest()
            ->limit(5)
            ->get();

        // Conversation terbaru
        $recentConversations = Conversation::with(
                'customer'
            )
            ->latest()
            ->limit(5)
            ->get();

        return view(
            'admin.dashboard.index',
            compact(
                'totalCustomers',
                'totalConversations',
                'totalMessages',
                'totalDocuments',
                'conversationStatus',
                'messageStatistics',
                'aiSuccessRate',
                'chatActivity',
                'recentCustomers',
                'recentConversations'
            )
        );

    }
}
