<?php

namespace App\Livewire\Staff;

use App\Enums\ReferrerType;
use App\Models\Referrer;
use App\Models\User;
use App\Services\ReferrerService;
use App\Services\StaffService;
use App\Support\WhatsappPhone;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
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

    /**
     * v0.22.3 — role yang boleh dicentang "Jadikan juga Referrer". Dikunci
     * eksplisit di decision-gate v0.22.0 — role lain (superadmin,
     * administrator, noc, billing, finance) tidak pernah menampilkan
     * checkbox ini sama sekali.
     */
    public const REFERRER_ELIGIBLE_ROLES = ['sales_internal', 'sales_freelance', 'customer_service', 'teknisi'];

    /**
     * Auto-prefill `App\Enums\ReferrerType` (nilai backed enum-nya) sesuai
     * role staff — cuma buat 3 dari 4 role yang ada padanan jelas. Role
     * `customer_service` SENGAJA tidak ada di sini (`ReferrerType` tidak
     * punya case untuk itu, dikonfirmasi Agung) — admin wajib pilih manual
     * lewat dropdown, tidak ada default.
     */
    private const ROLE_TO_REFERRER_TYPE = [
        'sales_internal' => 'sales',
        'sales_freelance' => 'freelance',
        'teknisi' => 'teknisi',
    ];

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
     * v0.22.3 — checkbox "Jadikan juga Referrer", cuma relevan (dan cuma
     * dirender) untuk role di `REFERRER_ELIGIBLE_ROLES`. `updatedRole()`
     * mereset ini otomatis kalau role diganti ke yang tidak diizinkan —
     * `wire:model.live="role"` di blade memastikan reset ini terjadi
     * SEBELUM submit, bukan cuma dicegah di server saat validate().
     */
    public bool $wantsReferrer = false;

    public string $referrerType = '';

    /**
     * Shown exactly once right after a staff account is created — never
     * re-derivable/re-shown once dismissed, StaffService never persists it
     * anywhere beyond this in-memory property (sama pola ReferrerIndex).
     */
    public ?string $generatedPassword = null;

    public ?string $generatedPasswordForName = null;

    /**
     * v0.22.3 — diisi setelah createStaff() SUKSES kalau checkbox Referrer
     * dicentang: pesan sukses/gagal link Referrer, ditampilkan berdampingan
     * dengan panel password (staff-nya SENDIRI tetap dianggap berhasil
     * dibuat terlepas dari hasil ini — lihat StaffService::create()).
     */
    public ?string $referrerLinkResultMessage = null;

    public bool $referrerLinkFailed = false;

    /**
     * v0.22.8 — diisi kalau collision terjadi ke Referrer ORPHAN (belum ada
     * akun login siapa pun) — beda dari collision ke Referrer yang sudah
     * taken (hard block biasa, tanpa data ini). Dipakai untuk menampilkan
     * tombol "Link ke Referrer lama ini" berdampingan dengan banner error,
     * lengkap dengan konteks (nama/tipe/tanggal dibuat/jumlah data nyantol)
     * supaya admin sadar apa yang dia klik.
     *
     * @var array{referrer_id: int, referrer_name: string, referrer_type: string, referrer_created_at: string, commission_ledger_count: int, customer_count: int}|null
     */
    public ?array $orphanCollision = null;

    /**
     * User (staff) yang BARU SAJA berhasil dibuat pada percobaan createStaff()
     * yang mengalami orphan collision — target link kalau tombol di-klik.
     */
    public ?int $orphanCollisionStaffUserId = null;

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

    /**
     * v0.22.3 — dipanggil otomatis oleh Livewire (`wire:model.live="role"`)
     * setiap kali role di dropdown create berubah. Role yang tidak
     * diizinkan checkbox Referrer mereset `wantsReferrer`/`referrerType`
     * seketika (bukan cuma disembunyikan di UI) — mencegah nilai lama
     * "nyangkut" kalau admin sempat centang lalu ganti pikiran soal role.
     */
    public function updatedRole(string $value): void
    {
        if (! in_array($value, self::REFERRER_ELIGIBLE_ROLES, true)) {
            $this->wantsReferrer = false;
            $this->referrerType = '';

            return;
        }

        $this->referrerType = self::ROLE_TO_REFERRER_TYPE[$value] ?? '';
    }

    public function referrerCheckboxVisible(): bool
    {
        return in_array($this->role, self::REFERRER_ELIGIBLE_ROLES, true);
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

        // wantsReferrer cuma efektif kalau role-nya genuinely diizinkan —
        // pertahanan server-side, bukan cuma andalkan updatedRole() sudah
        // membersihkan properti ini (payload Livewire bisa dimanipulasi
        // klien).
        $wantsReferrer = $this->wantsReferrer && in_array($this->role, self::REFERRER_ELIGIBLE_ROLES, true);

        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['required', 'string', 'max:30', Rule::unique(User::class, 'phone')],
            'role' => 'required|string|in:'.implode(',', Role::pluck('name')->all()),
            'referrerType' => $wantsReferrer
                ? ['required', 'string', Rule::in(array_column(ReferrerType::cases(), 'value'))]
                : ['nullable'],
        ]);

        $result = $service->create([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'tenant_id' => auth()->user()->tenant_id,
        ], $wantsReferrer ? ['type' => $this->referrerType] : null);

        $this->referrerLinkResultMessage = null;
        $this->referrerLinkFailed = false;
        $this->orphanCollision = null;
        $this->orphanCollisionStaffUserId = null;

        if ($wantsReferrer) {
            if ($result['referrer'] !== null) {
                $this->referrerLinkResultMessage = __('Akun Referrer juga berhasil dibuat & ter-link ke staff ini.');
            } else {
                $this->referrerLinkFailed = true;
                $this->referrerLinkResultMessage = __('Staff berhasil dibuat, TAPI gagal dijadikan Referrer: :error', ['error' => $result['referrer_link_error']]);

                // v0.22.8 — collision ke Referrer ORPHAN: simpan konteksnya
                // + id staff yang baru dibuat, supaya banner bisa
                // menawarkan tombol "Link ke Referrer lama ini".
                if ($result['referrer_orphan_collision'] !== null) {
                    $this->orphanCollision = $result['referrer_orphan_collision'];
                    $this->orphanCollisionStaffUserId = $result['user']->id;
                }
            }
        }

        $this->generatedPassword = $result['generated_password'];
        $this->generatedPasswordForName = $result['user']->name;

        $this->reset(['name', 'email', 'phone', 'role', 'showCreateForm', 'wantsReferrer', 'referrerType']);
    }

    /**
     * v0.22.8 — dipanggil dari tombol "Link ke Referrer lama ini" pada
     * banner collision-orphan (lihat createStaff()). Staff-nya SUDAH
     * berhasil dibuat sebelumnya — ini murni menyambungkan akun login-nya
     * ke Referrer orphan yang sudah ada, BUKAN membuat Referrer baru.
     * `wire:confirm` di view yang jadi lapis "admin tetap sadar apa yang
     * di-klik" — method ini sendiri tidak menampilkan konfirmasi lagi.
     */
    public function linkToOrphanReferrer(ReferrerService $service): void
    {
        $this->authorize('create', User::class);

        if ($this->orphanCollision === null || $this->orphanCollisionStaffUserId === null) {
            return;
        }

        $staffUser = User::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($this->orphanCollisionStaffUserId);
        $referrer = Referrer::withoutGlobalScopes()->findOrFail($this->orphanCollision['referrer_id']);

        try {
            $service->linkExistingUser($referrer, $staffUser);

            $this->referrerLinkFailed = false;
            $this->referrerLinkResultMessage = __('Berhasil di-link ke Referrer lama (:name).', ['name' => $referrer->name]);
        } catch (InvalidArgumentException $e) {
            // mis. race condition — Referrer itu sudah keburu ter-link ke
            // user lain di antara banner muncul dan tombol diklik.
            $this->referrerLinkFailed = true;
            $this->referrerLinkResultMessage = __('Gagal link: :error', ['error' => $e->getMessage()]);
        }

        $this->orphanCollision = null;
        $this->orphanCollisionStaffUserId = null;
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
        $this->reset(['generatedPassword', 'generatedPasswordForName', 'referrerLinkResultMessage', 'referrerLinkFailed']);
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
        //
        // v0.22.3 — eager load relasi `referrer` (User::referrer(), HasOne)
        // untuk kolom "Referrer?" di tabel + teks wire:confirm delete yang
        // dinamis di blade — tanpa ini setiap baris akan N+1 query.
        $staff = User::where('tenant_id', auth()->user()->tenant_id)
            ->whereHas('roles')
            ->when($this->search, fn ($query) => $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            }))
            ->with(['roles', 'referrer'])
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.staff.staff-index', [
            'staff' => $staff,
            'roles' => Role::orderBy('name')->pluck('name'),
            'canManage' => auth()->user()->can('create', User::class),
            'referrerEligibleRoles' => self::REFERRER_ELIGIBLE_ROLES,
            'referrerTypes' => ReferrerType::cases(),
        ]);
    }

    public function validationAttributes(): array
    {
        return [
            'name' => 'Nama',
            'email' => 'Email',
            'phone' => 'Nomor HP',
            'role' => 'Role',
            'referrerType' => 'Tipe Referrer',
            'editName' => 'Nama',
            'editEmail' => 'Email',
            'editPhone' => 'Nomor HP',
            'editRole' => 'Role',
        ];
    }
}
