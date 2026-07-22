<?php

namespace App\View\Components\Admin;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ConversationStatus extends Component
{
    public $statuses;


    public function __construct($statuses)
    {
        $this->statuses = $statuses;
    }


    public function render(): View|Closure|string
    {
        return view('components.admin.conversation-status');
    }
}