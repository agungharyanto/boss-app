<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\VpnAccountResource;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Services\Network\VpnProvisioningService;
use Illuminate\Http\JsonResponse;

class VpnAccountController extends Controller
{
    use ApiResponds;

    /**
     * Authorization mirrors NasController — provisioning a VPN account is a
     * "manage" action on the NAS itself (no separate VpnAccountPolicy;
     * vpn_accounts has no reseller_id/tenant_id of its own, same pattern as
     * odp_ports/work_order_photos — scoped implicitly through its parent).
     */
    public function provision(Nas $nas, VpnProvisioningService $service): JsonResponse
    {
        $this->authorize('manage', $nas);

        $account = $service->provision($nas);

        return $this->success(new VpnAccountResource($account), 'Akun VPN berhasil dibuat', [], 201);
    }

    public function revoke(VpnAccount $vpn_account, VpnProvisioningService $service): JsonResponse
    {
        $this->authorize('manage', $vpn_account->nas);

        $account = $service->revoke($vpn_account);

        return $this->success(new VpnAccountResource($account), 'Akun VPN berhasil dicabut');
    }
}
