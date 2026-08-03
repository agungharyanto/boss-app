<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Resources\AgentReferralResource;
use App\Http\Resources\CustomerResource;
use App\Models\Agent;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    use ApiResponds;

    /**
     * Register a new customer, matching Livewire\Customers\RegisterCustomer's
     * agent-attribution rule: a caller linked to an Agent record always
     * registers under their own agent (referred_by_agent_id in the request
     * body is ignored), everyone else may optionally attribute the referral
     * to any agent in their tenant via referred_by_agent_id.
     */
    public function store(StoreRegistrationRequest $request, RegistrationService $service): JsonResponse
    {
        $data = $request->validated();

        $linkedAgent = Agent::where('user_id', $request->user()->id)->first();
        $agent = $linkedAgent ?? (isset($data['referred_by_agent_id']) ? Agent::find($data['referred_by_agent_id']) : null);
        unset($data['referred_by_agent_id']);

        $customer = $service->register($data, $agent);

        return $this->success(new CustomerResource($customer), 'Registrasi berhasil', [], 201);
    }

    /**
     * The acting user's own referred customers with their commission_ledger
     * status — the closest existing equivalent to "referral status" in this
     * codebase. There is no referral-code concept to look up here: agents
     * are linked to a user 1:1 (Agent::user_id), not by a generated code.
     */
    public function referrals(Request $request): JsonResponse
    {
        $this->authorize('register-customer');

        $agent = Agent::where('user_id', $request->user()->id)->firstOrFail();

        $referrals = $agent->referrals()->with('commissionLedgerEntries')->latest()->get();

        return $this->success(AgentReferralResource::collection($referrals), 'Daftar referral Anda');
    }
}
