<?php

namespace App\Livewire\ReferrerPortal;

use App\Models\Referrer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * v0.9.2 — deliberately minimal scope: profile (read-only phone, editable
 * name), the Referrer's own referred customers list (Referrer::referrals(),
 * already built in v0.9.1), and a placeholder for commission recap. No
 * commission/rate/"Titip" logic is built here — that's v0.9.3-v0.9.6 — see
 * CLAUDE.md's own "Portal Referrer self-service is CREATE-ONLY" note for the
 * audit-trail principle that must be followed when that logic eventually
 * lands here.
 *
 * The active Referrer row is resolved once by EnsureReferrerPortalAccess and
 * stashed on the request (`referrer` attribute) — this component reads it
 * from there rather than re-querying, and re-authorizes independently
 * anyway (same defense-in-depth posture already established elsewhere in
 * this codebase, e.g. CpeSignalHistoryGraph).
 */
#[Layout('layouts.referrer-portal')]
class Dashboard extends Component
{
    public int $referrerId;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $phone = '';

    public string $typeLabel = '';

    public bool $nameUpdated = false;

    public function mount(): void
    {
        $referrer = request()->attributes->get('referrer');

        abort_if($referrer === null, 403);

        $this->referrerId = $referrer->id;
        $this->name = $referrer->name;
        $this->phone = $referrer->phone;
        $this->typeLabel = $referrer->type->label();
    }

    public function updateName(): void
    {
        $referrer = Referrer::findOrFail($this->referrerId);

        abort_if($referrer->user_id !== auth()->id(), 403);

        $this->validate();

        $referrer->update(['name' => $this->name]);

        $this->nameUpdated = true;
    }

    public function render(): View
    {
        $referrer = Referrer::findOrFail($this->referrerId);

        return view('livewire.referrer-portal.dashboard', [
            'referrals' => $referrer->referrals()->latest()->get(),
        ]);
    }
}
