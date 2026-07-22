<?php

namespace App\View\Components\Admin;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class RecentConversation extends Component
{
    public $conversations;

    public function __construct($conversations)
    {
        $this->conversations = $conversations;
    }

    public function render(): View|Closure|string
    {
        return view('components.admin.recent-conversation');
    }
}