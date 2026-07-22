<?php

namespace App\View\Components\Admin;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AiPerformance extends Component
{

    public $rate;


    public function __construct($rate)
    {
        $this->rate = $rate;
    }


    public function render(): View|Closure|string
    {
        return view('components.admin.ai-performance');
    }
}