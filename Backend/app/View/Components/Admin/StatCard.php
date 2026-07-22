<?php

namespace App\View\Components\Admin;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class StatCard extends Component
{
    public $title;
    public $value;
    public $icon;

    public function __construct(
        $title,
        $value,
        $icon = null
    )
    {
        $this->title = $title;
        $this->value = $value;
        $this->icon = $icon;
    }


    public function render(): View|Closure|string
    {
        return view('components.admin.stat-card');
    }
}