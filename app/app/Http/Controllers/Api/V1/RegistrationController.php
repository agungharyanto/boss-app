<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ReferrerReferralResource;
use App\Models\Referrer;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    use ApiResponds;

    /**
     * Register a new customer, matching Livewire\Customers\RegisterCustomer's
     * referrer-attribution rule: a caller linked to a Referrer record always
     * registers under their own referrer (referred_by_referrer_id in the
     * request body is ignored), everyone else may optionally attribute the
     * referral to any referrer in their tenant via referred_by_referrer_id.
     */
    public function store(StoreRegistrationRequest $request, RegistrationService $service): JsonResponse
    {
        $data = $request->validated();

        $linkedReferrer = Referrer::where('user_id', $request->user()->id)->first();
        $referrer = $linkedReferrer ?? (isset($data['referred_by_referrer_id']) ? Referrer::find($data['referred_by_referrer_id']) : null);
        unset($data['referred_by_referrer_id']);

        // v0.9.4 — scheme diteruskan terpisah; CommissionAttributionService
        // yang memutuskan apakah amount bisa di-resolve (kalau tidak, amount
        // NULL — backward compatible).
        $scheme = $data['scheme'] ?? null;
        unset($data['scheme']);

        $customer = $service->register($data, $referrer, $scheme);

        return $this->success(new CustomerResource($customer), 'Registrasi berhasil', [], 201);
    }

    /**
     * The acting user's own referred customers with their commission_ledger
     * status — the closest existing equivalent to "referral status" in this
     * codebase. There is no referral-code concept to look up here: referrers
     * are linked to a user 1:1 (Referrer::user_id), not by a generated code.
     */
    public function referrals(Request $request): JsonResponse
    {
        $this->authorize('register-customer');

        $referrer = Referrer::where('user_id', $request->user()->id)->firstOrFail();

        $referrals = $referrer->referrals()->with('commissionLedgerEntries')->latest()->get();

        return $this->success(ReferrerReferralResource::collection($referrals), 'Daftar referral Anda');
    }
}
