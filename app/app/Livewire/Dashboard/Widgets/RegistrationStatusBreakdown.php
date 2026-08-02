<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Enums\RegistrationStatus;
use App\Models\Customer;
use Livewire\Component;

class RegistrationStatusBreakdown extends Component
{
    public function render()
    {
        $counts = Customer::selectRaw('registration_status, count(*) as total')
            ->groupBy('registration_status')
            ->pluck('total', 'registration_status');

        $breakdown = collect(RegistrationStatus::cases())->map(fn (RegistrationStatus $status) => [
            'label' => $status->label(),
            'total' => $counts->get($status->value, 0),
        ]);

        return view('livewire.dashboard.widgets.registration-status-breakdown', [
            'breakdown' => $breakdown,
        ]);
    }
}
