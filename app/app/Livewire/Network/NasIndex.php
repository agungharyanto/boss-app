<?php

namespace App\Livewire\Network;

use App\Enums\NasStatus;
use App\Enums\VpnAccountStatus;
use App\Exceptions\NasApiUserProvisioningException;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\Reseller;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\NasApiUserProvisioningService;
use App\Services\Network\NasService;
use App\Support\ResellerContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * List + create/edit NAS — the UI v0.6.1 deliberately shipped without
 * (API-only that sprint). Same admin-vs-reseller branching as
 * WhatsappGatewayIndex (v0.4.0): ISP admin picks a reseller or leaves it
 * for "direct"; a reseller owner/staff gets their own reseller_id
 * auto-applied and the picker hidden entirely.
 */
class NasIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showForm = false;

    public ?int $editingNasId = null;

    public string $name = '';

    public string $description = '';

    public string $timezone = 'Asia/Jakarta';

    public string $mikrotikIp = '';

    public int $apiPort = 8728;

    public string $apiUsername = '';

    public string $apiPassword = '';

    public string $radiusSecret = '';

    public ?int $resellerId = null;

    /** @var array{status: string, message: string}|null */
    public ?array $testConnectionResult = null;

    // v0.6.5 — "Provision User API" modal state, deliberately kept
    // completely separate from apiUsername/apiPassword above (the exact
    // mixup that caused a real credential-rotation bug this whole flow
    // replaces — see NasApiUserProvisioningService's docblock).
    // provisionAdminUsername/provisionAdminPassword are NEVER part of
    // save()'s $data and never bound to any Nas attribute directly.
    public bool $showProvisionApiModal = false;

    public ?int $provisioningNasId = null;

    public string $provisionAdminUsername = '';

    public string $provisionAdminPassword = '';

    /** @var array{status: string, message: string}|null */
    public ?array $provisionApiResult = null;

    // Revisi Grup Profil (Langkah 3) — "Profil Pelanggan Expired" modal
    // state, same separate-modal-state pattern as showProvisionApiModal
    // above rather than folding into the main NAS form.
    public bool $showExpiredProfileModal = false;

    public ?int $expiredProfileNasId = null;

    public string $expiredProfileIpPoolId = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Nas::class);
    }

    public function isAdmin(): bool
    {
        return auth()->user()->can('nas.manage') || auth()->user()->can('nas.view');
    }

    public function create(): void
    {
        $context = app(ResellerContext::class);

        $this->authorize('create', [Nas::class, $context->hasReseller() ? $context->reseller() : null]);

        $this->resetForm();
        $this->editingNasId = null;
        $this->showForm = true;
    }

    public function edit(int $nasId): void
    {
        $nas = Nas::findOrFail($nasId);
        $this->authorize('manage', $nas);

        $this->editingNasId = $nas->id;
        $this->name = $nas->name;
        $this->description = (string) $nas->description;
        $this->timezone = (string) ($nas->timezone ?: 'Asia/Jakarta');
        $this->mikrotikIp = (string) $nas->mikrotik_ip;
        $this->apiPort = $nas->api_port;
        $this->apiUsername = (string) $nas->api_username;
        $this->apiPassword = '';
        $this->radiusSecret = '';
        $this->resellerId = $nas->reseller_id;
        $this->testConnectionResult = null;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * True once a NAS has any active VPN account — mikrotik_ip is meant to
     * be VPN-managed from that point on (see docs/API.md / CLAUDE.md v0.6.3),
     * even though nothing yet actually copies internal_ip into this column
     * (a documented, deliberately separate gap — this UI only locks the
     * field so an admin doesn't fight the eventual auto-management, it does
     * not fabricate an auto-sync that doesn't exist). Always false for a
     * brand-new NAS, which can't possibly have a VPN account yet.
     */
    public function isMikrotikIpLocked(): bool
    {
        if ($this->editingNasId === null) {
            return false;
        }

        return Nas::findOrFail($this->editingNasId)
            ->vpnAccounts()
            ->where('status', VpnAccountStatus::Active)
            ->exists();
    }

    /**
     * v0.6.5 — real per-NAS auth/acct/coa ports (NasPortAllocatorService),
     * not the old shared-default 1812/1813 placeholder. Null for a
     * brand-new NAS not saved yet (ports are only allocated by
     * NasService::create()).
     *
     * @return array{auth_port: ?int, acct_port: ?int, coa_port: ?int}
     */
    public function currentPorts(): array
    {
        if ($this->editingNasId === null) {
            return ['auth_port' => null, 'acct_port' => null, 'coa_port' => null];
        }

        $nas = Nas::findOrFail($this->editingNasId);

        return ['auth_port' => $nas->auth_port, 'acct_port' => $nas->acct_port, 'coa_port' => $nas->coa_port];
    }

    /**
     * v0.6.5 — only meaningful for an already-saved NAS (needs a real
     * mikrotik_ip to connect to). Opens a modal with its OWN two fields,
     * never pre-filled from anything already stored — the whole point is
     * this credential is the router owner's real admin login, typed fresh
     * every time, never persisted.
     */
    public function openProvisionApiModal(int $nasId): void
    {
        $nas = Nas::findOrFail($nasId);
        $this->authorize('manage', $nas);

        $this->provisioningNasId = $nasId;
        $this->provisionAdminUsername = '';
        $this->provisionAdminPassword = '';
        $this->provisionApiResult = null;
        $this->showProvisionApiModal = true;
    }

    public function closeProvisionApiModal(): void
    {
        $this->provisioningNasId = null;
        $this->provisionAdminUsername = '';
        $this->provisionAdminPassword = '';
        $this->provisionApiResult = null;
        $this->showProvisionApiModal = false;
    }

    public function provisionApiUser(NasApiUserProvisioningService $service): void
    {
        $this->provisionApiResult = null;

        $this->validate([
            'provisionAdminUsername' => ['required', 'string', 'max:255'],
            'provisionAdminPassword' => ['required', 'string', 'max:255'],
        ]);

        $nas = Nas::findOrFail($this->provisioningNasId);
        $this->authorize('manage', $nas);

        try {
            $service->provisionWithAdminCredential($nas, $this->provisionAdminUsername, $this->provisionAdminPassword);
            $this->provisionApiResult = ['status' => 'success', 'message' => 'User API berhasil dibuat/diperbarui di router.'];
        } catch (NasApiUserProvisioningException $e) {
            $this->provisionApiResult = ['status' => 'failed', 'message' => $e->getMessage()];
        } finally {
            // The admin credential is never kept in component state one
            // moment longer than the single request that used it —
            // success or failure.
            $this->provisionAdminUsername = '';
            $this->provisionAdminPassword = '';
        }
    }

    /**
     * Revisi Grup Profil (Langkah 3) — opens the small "Profil Pelanggan
     * Expired" modal, pre-filled with whatever is already set (unlike the
     * Provision API modal above, this value is safe to show back — it's
     * just an IP Pool reference, not a secret).
     */
    public function openExpiredProfileModal(int $nasId): void
    {
        $nas = Nas::findOrFail($nasId);
        $this->authorize('manage', $nas);

        $this->expiredProfileNasId = $nasId;
        $this->expiredProfileIpPoolId = (string) $nas->expired_ip_pool_id;
        $this->showExpiredProfileModal = true;
    }

    public function closeExpiredProfileModal(): void
    {
        $this->expiredProfileNasId = null;
        $this->expiredProfileIpPoolId = '';
        $this->showExpiredProfileModal = false;
    }

    public function saveExpiredProfile(NasService $service): void
    {
        $nas = Nas::findOrFail($this->expiredProfileNasId);
        $this->authorize('manage', $nas);

        $this->validate(['expiredProfileIpPoolId' => ['nullable', 'integer']]);

        try {
            $service->updateExpiredIpPool($nas, $this->expiredProfileIpPoolId !== '' ? (int) $this->expiredProfileIpPoolId : null);
            session()->flash('status', 'Profil Pelanggan Expired berhasil diperbarui.');
        } catch (InvalidArgumentException $e) {
            $this->addError('expiredProfileIpPoolId', $e->getMessage());

            return;
        }

        $this->closeExpiredProfileModal();
    }

    public function save(NasService $service): void
    {
        $context = app(ResellerContext::class);
        $isAdmin = $this->isAdmin();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'mikrotikIp' => ['nullable', 'ip'],
            'apiPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'apiUsername' => ['nullable', 'string', 'max:255'],
            'apiPassword' => ['nullable', 'string', 'max:255'],
            'radiusSecret' => ['nullable', 'string', 'max:255'],
        ];

        if ($isAdmin) {
            $rules['resellerId'] = ['nullable', 'exists:resellers,id'];
        }

        $this->validate($rules);

        $data = [
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,
            'timezone' => $this->timezone !== '' ? $this->timezone : null,
            'api_port' => $this->apiPort,
            'api_username' => $this->apiUsername !== '' ? $this->apiUsername : null,
        ];

        // Blank submit never erases an already-saved secret (same posture
        // as PaymentGatewaySettings, v0.3.5) — only overwrite when the
        // admin actually typed something.
        if ($this->apiPassword !== '') {
            $data['api_password'] = $this->apiPassword;
        }
        if ($this->radiusSecret !== '') {
            $data['radius_secret'] = $this->radiusSecret;
        }

        if ($this->editingNasId === null) {
            // radius_secret is required on create (matches StoreNasRequest's
            // REST contract) — there's no existing value to fall back on.
            if ($this->radiusSecret === '') {
                $this->addError('radiusSecret', 'Secret RADIUS wajib diisi untuk NAS baru.');

                return;
            }

            $resellerId = $context->hasReseller() ? $context->reseller()->id : ($isAdmin ? $this->resellerId : null);

            $this->authorize('create', [Nas::class, $resellerId ? Reseller::find($resellerId) : null]);

            $data['mikrotik_ip'] = $this->mikrotikIp !== '' ? $this->mikrotikIp : null;

            $service->create($data, auth()->user()->tenant_id, $resellerId);
            session()->flash('status', 'NAS berhasil dibuat.');
        } else {
            $nas = Nas::findOrFail($this->editingNasId);
            $this->authorize('manage', $nas);

            if (! $this->isMikrotikIpLocked()) {
                $data['mikrotik_ip'] = $this->mikrotikIp !== '' ? $this->mikrotikIp : null;
            }

            $service->update($nas, $data);
            session()->flash('status', 'NAS berhasil diperbarui.');
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $nasId): void
    {
        $nas = Nas::findOrFail($nasId);
        $this->authorize('manage', $nas);

        app(NasService::class)->delete($nas);

        session()->flash('status', 'NAS berhasil dihapus.');
    }

    /**
     * Tests against the CURRENTLY TYPED form values (not necessarily saved
     * yet) via RouterOsGateway directly — NasService::testConnection() isn't
     * reused here because it requires an already-persisted Nas row (it ends
     * with ->update()), which doesn't exist yet for a brand-new NAS being
     * tested before its first save. For an EXISTING NAS being edited,
     * status/last_ping_at ARE still persisted onto the real row afterward —
     * only the ping itself uses the in-form (possibly unsaved) values.
     */
    public function testConnection(RouterOsGateway $gateway): void
    {
        $this->testConnectionResult = null;

        if ($this->mikrotikIp === '') {
            $this->testConnectionResult = ['status' => 'failed', 'message' => 'IP Router belum diisi.'];

            return;
        }

        if ($this->editingNasId !== null) {
            $nas = Nas::findOrFail($this->editingNasId);
            $this->authorize('manage', $nas);
        } else {
            $context = app(ResellerContext::class);
            $resellerId = $context->hasReseller() ? $context->reseller()->id : ($this->isAdmin() ? $this->resellerId : null);
            $this->authorize('create', [Nas::class, $resellerId ? Reseller::find($resellerId) : null]);
            $nas = null;
        }

        $probe = new Nas([
            'mikrotik_ip' => $this->mikrotikIp,
            'api_port' => $this->apiPort,
            'api_username' => $this->apiUsername,
            'api_password' => $this->apiPassword !== '' ? $this->apiPassword : $nas?->api_password,
        ]);

        $result = $gateway->ping($probe);

        if ($nas !== null) {
            $updates = [
                'status' => $result['online'] ? NasStatus::Online : NasStatus::Offline,
                'last_ping_at' => now(),
            ];

            // Real bug found 2026-08-04: a successful test using a freshly
            // TYPED password (different from what's stored) used to leave
            // nas.api_password untouched — the list would show "online"
            // right after, but the very next connection attempt using the
            // STORED credential (script generation, this same test next
            // time with a blank password field, etc.) would fail again,
            // looking like the NAS randomly went offline with nothing
            // changed. A successful test IS proof this typed credential is
            // the NAS's real current one — persist it, same as any other
            // masked-field "only overwrite when actually typed" field in
            // this form.
            if ($result['online'] && $this->apiPassword !== '') {
                $updates['api_username'] = $this->apiUsername;
                $updates['api_password'] = $this->apiPassword;
            }

            $nas->update($updates);
        }

        $this->testConnectionResult = $result['online']
            ? ['status' => 'success', 'message' => 'Koneksi berhasil.']
            : ['status' => 'failed', 'message' => $result['message'] ?? 'Koneksi gagal (tidak ada pesan detail dari RouterOS API).'];
    }

    public function render()
    {
        $context = app(ResellerContext::class);
        $isAdmin = $this->isAdmin();

        return view('livewire.network.nas-index', [
            'isAdmin' => $isAdmin,
            'nasList' => Nas::query()->with(['reseller', 'expiredIpPool:id,name'])->orderBy('name')->paginate(15),
            'resellers' => $isAdmin ? Reseller::orderBy('name')->get() : collect(),
            'currentResellerName' => $context->hasReseller() ? $context->reseller()->name : null,
            'mikrotikIpLocked' => $this->isMikrotikIpLocked(),
            // Revisi Grup Profil (Langkah 3) — only this NAS's own pools,
            // same "belongs to the same NAS" rule NasService::
            // updateExpiredIpPool() enforces server-side too.
            'expiredProfilePoolOptions' => $this->expiredProfileNasId !== null
                ? CustomerIpPool::query()->where('nas_id', $this->expiredProfileNasId)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'name', 'description', 'mikrotikIp', 'apiUsername', 'apiPassword',
            'radiusSecret', 'resellerId', 'testConnectionResult',
        ]);
        $this->timezone = 'Asia/Jakarta';
        $this->apiPort = 8728;
        $this->resetErrorBag();
    }

    /**
     * Revisi Pesan Error Bahasa Indonesia — hanya field milik modal "Profil
     * Pelanggan Expired" (bagian dari cluster "Profil Paket") yang relevan
     * di komponen ini; field NAS lainnya di luar scope revisi ini.
     */
    public function validationAttributes(): array
    {
        return ['expiredProfileIpPoolId' => 'IP Pool'];
    }
}
