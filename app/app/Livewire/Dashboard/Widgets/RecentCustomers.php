<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\Customer;
use Livewire\Component;

class RecentCustomers extends Component
{
    public function render()
    {
        return view('livewire.dashboard.widgets.recent-customers', [
            'customers' => Customer::latest()->limit(5)->get(),
        ]);
    }
}
