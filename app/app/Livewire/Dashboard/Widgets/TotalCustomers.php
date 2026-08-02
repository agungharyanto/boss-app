<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\Customer;
use Livewire\Component;

class TotalCustomers extends Component
{
    public function render()
    {
        return view('livewire.dashboard.widgets.total-customers', [
            'total' => Customer::count(),
        ]);
    }
}
