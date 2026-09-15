<?php

namespace App\Livewire\Staff;

use App\Models\User;
use App\Services\StaffService;
use App\Support\WhatsappPhone;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * v0.22.1 — CRUD Staff (Manajemen User). Struktur mirror BandwidthProfileIndex
 * (list/create/edit/toggle) + pola "tampilkan password sekali" dari
 * ReferrerIndex — lihat CLAUDE.md untuk keputusan yang sudah dikunci.
 *
 * `User` TIDAK pakai `BelongsToTenant` (tidak ada TenantScope otomatis) —
 * setiap query di sini WAJIB eksplisit `tenant_id = auth()->user()->tenant_id`,
 * termasuk findOrFail() per baris (disable/enable/edit/delete).
 */
class StaffIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    // v0.22.2 — jadi OPSIONAL (dulu wajib). Tipe nullable supaya bisa
    // di-null-kan sebelum validate() saat dikosongkan — lihat
    // createStaff()/updateStaff().
    #[Validate('nullable|email|max:255')]
    public ?string $email = '';

    // v0.22.2 — jadi WAJIB (dulu opsional), alat login utama.
    #[Validate('required|string|max:30')]
    public string $phone = '';

    #[Validate('required|string')]
    public string $role = '';

    /**
     * Shown exactly once right after a staff account is created — never
     * re-derivable/re-shown once dismissed, StaffService never persists it
     * anywhere beyond this in-memory property (sama pola ReferrerIndex).
     */
    public ?string $generatedPassword = null;

    public ?string $generatedPasswordForName = null;

    public ?int $editingUserId = null;

    #[Validate('required|string|max:255')]
    public string $editName = '';

    #[Validate('nullable|email|max:255')]
    public ?string $editEmail = '';

    #[Validate('required|string|max:30')]
    public string $editPhone = '';

    #[Validate('required|string')]
    public string $editRole = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function createStaff(StaffService $service): void
    {
        $this->authorize('create', User::class);

        // Normalize/null-kan SEBELUM validate() — supaya Rule::unique()
        // membandingkan nilai yang SUDAH sama bentuknya dengan yang
        // tersimpan di DB (StaffService sendiri menormalisasi lagi saat
        // simpan, idempoten — lihat docblock-nya), dan supaya string
        // kosong dianggap benar-benar kosong oleh rule 'nullable' (bukan
        // bergantung perilaku implisit Livewire terhadap '').
        $this->phone = WhatsappPhone::normalize($this->phone);
        $this->email = $this->email !== '' ? $this->email : null;

        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['required', 'string', 'max:30', Rule::unique(User::class, 'phone')],
            'role' => 'required|string|in:'.implode(',', Role::pluck('name')->all()),
        ]);

        $result = $service->create([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'tenant_id' => auth()->user()->tenant_id,
        ]);

        $this->generatedPassword = $result['generated_password'];
        $this->generatedPasswordForName = $result['user']->name;

        $this->reset(['name', 'email', 'phone', 'role', 'showCreateForm']);
    }

    public function edit(int $userId): void
    {
        $user = User::where('tenant_id', auth()->user()->tenant_id)->findOrFail($userId);
        $this->authorize('update', $user);

        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editPhone = $user->phone ?? '';
        $this->editRole = $user->roles->first()?->name ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingUserId', 'editName', 'editEmail', 'editPhone', 'editRole']);
    }

    public function updateStaff(StaffService $service): void
    {
        $user = User::where('tenant_id', auth()->user()->tenant_id)->findOrFail($this->editingUserId);
        $this->authorize('update', $user);

        $this->editPhone = WhatsappPhone::normalize($this->editPhone);
        $this->editEmail = $this->editEmail !== '' ? $this->editEmail : null;

        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => ['nullable', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)],
            'editPhone' => ['required', 'string', 'max:30', Rule::unique(User::class, 'phone')->ignore($user->id)],
            'editRole' => 'required|string|in:'.implode(',', Role::pluck('name')->all()),
        ]);

        $service->update($user, [
            'name' => $this->editName,
            'email' => $this->editEmail,
            'phone' => $this->editPhone,
            'role' => $this->editRole,
        ]);

        $this->cancelEdit();
    }

    public function toggleDisable(int $userId, StaffService $service): void
    {
        $user = User::where('tenant_id', auth()->user()->tenant_id)->findOrFail($userId);
        $this->authorize('update', $user);

        $user->is_disabled ? $service->enable($user) : $service->disable($user);
    }

    /**
     * Delete permanen (hard delete, `users` tidak pakai SoftDeletes) —
     * StaffService::delete() sendiri yang menolak dengan pesan spesifik
     * kalau masih ada relasi yang nyantol (reseller_users/technicians/
     * cpe_action_logs, lihat docblock method itu). Error ditangkap di sini
     * dan ditampilkan ke admin lewat addError() — TIDAK pernah silent fail.
     */
    public function deleteStaff(int $userId, StaffService $service): void
    {
        $user = User::where('tenant_id', auth()->user()->tenant_id)->findOrFail($userId);
        $this->authorize('delete', $user);

        try {
            $service->delete($user);
        } catch (RuntimeException $e) {
            $this->addError('deleteStaff', $e->getMessage());
        }
    }

    public function dismissGeneratedPassword(): void
    {
        $this->reset(['generatedPassword', 'generatedPasswordForName']);
    }

    public function render()
    {
        // Root cause insiden Kamisem (2026-09-15) — query ini dulu
        // TIDAK memfilter "punya role Spatie", jadi akun Referrer portal
        // (zero-role by design, lihat ReferrerService::attachNewLoginAccount())
        // ikut muncul di "Manajemen Staff" tanpa tanda visual apa pun,
        // membuat siapa pun bisa salah klik Hapus terhadap akun login
        // produksi yang genuinely masih dipakai. `whereHas('roles')` —
        // SATU-SATUNYA hal yang membedakan akun staff sungguhan dari akun
        // portal-only di skema ini — memastikan cakupan halaman ini
        // benar-benar staff, bukan "semua baris users".
        $staff = User::where('tenant_id', auth()->user()->tenant_id)
            ->whereHas('roles')
            ->when($this->search, fn ($query) => $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            }))
            ->with('roles')
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.staff.staff-index', [
            'staff' => $staff,
            'roles' => Role::orderBy('name')->pluck('name'),
            'canManage' => auth()->user()->can('create', User::class),
        ]);
    }

    public function validationAttributes(): array
    {
        return [
            'name' => 'Nama',
            'email' => 'Email',
            'phone' => 'Nomor HP',
            'role' => 'Role',
            'editName' => 'Nama',
            'editEmail' => 'Email',
            'editPhone' => 'Nomor HP',
            'editRole' => 'Role',
        ];
    }
}
