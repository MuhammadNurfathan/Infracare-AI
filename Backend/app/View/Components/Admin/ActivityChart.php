<?php

namespace App\View\Components\Admin;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ActivityChart extends Component
{
    public $activities;

    public function __construct($activities)
    {
        $this->activities = $activities;
    }

    public function render(): View|Closure|string
    {
        return view('components.admin.activity-chart');
    }
}