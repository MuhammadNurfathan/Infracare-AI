<?php

namespace App\View\Components\Admin;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class RecentCustomer extends Component
{
    public $customers;

    public function __construct($customers)
    {
        $this->customers = $customers;
    }

    public function render(): View|Closure|string
    {
        return view('components.admin.recent-customer');
    }
}