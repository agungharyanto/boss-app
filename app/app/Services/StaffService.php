<?php

namespace App\Services;

use App\Enums\TechnicianStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderStatus;
use App\Models\CpeActionLog;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\ResellerUser;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Services\Whatsapp\WhatsappTemplateService;
use App\Support\WhatsappPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * v0.22.1 — CRUD Staff (Manajemen User). Mirror pola ReferrerService untuk
 * generate password (Str::password(16) + Hash::make, ditampilkan SATU KALI
 * lewat return value, tidak pernah disimpan/logged) — bedanya di sini akun
 * staff MEMANG diberi satu role Spatie (termasuk superadmin — sudah dikunci
 * eksplisit: "semua 9 role selectable termasuk superadmin, single-choice"),
 * bukan zero-role seperti akun Referrer.
 *
 * Semua method di sini eksplisit tenant-scoped lewat parameter — `User`
 * model TIDAK pakai `BelongsToTenant` (lihat CLAUDE.md), jadi `tenant_id`
 * TIDAK pernah auto-fill dan query terhadap `User` TIDAK pernah otomatis
 * ter-scope. Caller (Livewire component) bertanggung jawab meneruskan
 * `tenant_id` yang benar (auth()->user()->tenant_id).
 */
class StaffService
{
    /**
     * Nama role Spatie yang jadi SUMBER KEBENARAN untuk sinkronisasi baris
     * `technicians` (lihat `syncTechnicianStatus()` di bawah) — satu tempat
     * literal 'teknisi' didefinisikan, dipakai method sinkronisasi DAN
     * command backfill (`technicians:sync-from-staff-roles`), bukan string
     * literal terpisah yang bisa drift dari `StaffIndex::
     * REFERRER_ELIGIBLE_ROLES`/dropdown role di UI (yang literal 'teknisi'
     * juga, tapi konsep berbeda — daftar role ITU untuk fitur Referrer,
     * bukan Technician).
     */
    public const TECHNICIAN_ROLE = 'teknisi';

    /**
     * `ReferrerService`/`WhatsappGatewayService` opsional dengan default
     * instantiate baru — supaya `new StaffService()` (dipakai luas di
     * test-test lama) tetap valid tanpa perlu diubah satu per satu,
     * sekaligus tetap bisa dependency-injected lewat container (Livewire
     * method injection) seperti method lain di kelas ini.
     */
    public function __construct(
        private ?ReferrerService $referrerService = null,
        private ?WhatsappGatewayService $whatsappGateway = null,
    ) {
        $this->referrerService ??= new ReferrerService;
        $this->whatsappGateway ??= new WhatsappGatewayService(new WhatsappTemplateService);
    }

    /**
     * v0.22.2 — `phone` sekarang WAJIB (alat login utama), `email` jadi
     * opsional. `WhatsappPhone::normalize()` dipanggil di sini SEBAGAI
     * DEFENSE-IN-DEPTH (caller Livewire sudah menormalisasi sebelum
     * `Rule::unique()`-nya sendiri dijalankan, supaya deteksi duplikat
     * apple-to-apple — lihat `StaffIndex::createStaff()`) — memanggilnya
     * lagi di sini idempoten (nomor yang sudah `62xxx` tidak berubah),
     * jadi aman dipanggil dua kali, dan `StaffService` tetap benar kalau
     * suatu saat dipanggil langsung tanpa lewat Livewire.
     *
     * v0.22.3 — `$referrerData` opsional: kalau diisi (checkbox "Jadikan
     * juga Referrer" dicentang), bikin `Referrer` baru + link ke User yang
     * baru dibuat lewat `ReferrerService::createAndLinkToStaff()` — REUSE,
     * bukan duplikat logic Referrer di sini. Dijalankan di TRANSAKSI
     * TERPISAH dari pembuatan User (bukan satu transaksi besar) — supaya
     * kegagalan link Referrer (mis. collision `(tenant_id, phone)`, lihat
     * docblock `createAndLinkToStaff()`) TIDAK ikut membatalkan User staff
     * yang sudah berhasil dibuat (dikunci eksplisit oleh Agung: "staff
     * tetap berhasil dibuat, cuma link Referrer-nya yang gagal").
     * Kegagalan itu dilaporkan lewat `referrer_link_error`, bukan
     * exception — caller (Livewire) yang menampilkannya ke admin.
     *
     * @param  array{name: string, email?: ?string, phone: string, role: string, tenant_id: int}  $data
     * @param  array{type: string}|null  $referrerData  null = staff biasa, tidak dijadikan Referrer
     * @return array{user: User, generated_password: string, referrer: ?Referrer, referrer_link_error: ?string}
     */
    public function create(array $data, ?array $referrerData = null): array
    {
        $result = DB::transaction(function () use ($data) {
            $generatedPassword = Str::password(16);
            $email = $data['email'] ?? null;

            $user = User::create([
                'tenant_id' => $data['tenant_id'],
                'name' => $data['name'],
                'email' => $email,
                'phone' => WhatsappPhone::normalize($data['phone']),
                'password' => Hash::make($generatedPassword),
                // Cuma masuk akal diberi tanggal verifikasi kalau memang
                // punya email untuk diverifikasi.
                'email_verified_at' => $email !== null ? now() : null,
                // v0.22.6 — password di sini SELALU random-generated
                // (Str::password(16) di atas), jadi WAJIB dipaksa ganti
                // saat login pertama. Lihat App\Http\Middleware\
                // EnsurePasswordChanged.
                'must_change_password' => true,
            ]);

            $user->assignRole($data['role']);

            // Auto-sync `technicians` — lihat docblock syncTechnicianStatus().
            // Dijalankan DI DALAM transaksi yang sama (bukan setelah commit
            // seperti bagian Referrer/WA di bawah) karena ini murni derivasi
            // dari role yang baru saja di-assign, tidak melibatkan panggilan
            // eksternal (WA/gateway) yang bisa gagal — kalau transaksi User
            // ini rollback, baris Technician yang baru dibuat ikut rollback,
            // tidak ada baris yatim tertinggal.
            $this->syncTechnicianStatus($user);

            return ['user' => $user->fresh(), 'generated_password' => $generatedPassword];
        });

        $referrer = null;
        $referrerLinkError = null;

        if ($referrerData !== null) {
            try {
                $referrer = $this->referrerService->createAndLinkToStaff([
                    'tenant_id' => $data['tenant_id'],
                    'name' => $data['name'],
                    // Format NORMALIZED (62xxx) — konsisten dengan
                    // users.phone, bukan raw seperti diketik admin (lihat
                    // keputusan kickoff v0.22.3). $result['user']->phone
                    // sudah pasti dinormalisasi dari langkah create() User
                    // di atas, dipakai apa adanya (bukan $data['phone']
                    // mentah).
                    'phone' => $result['user']->phone,
                    'type' => $referrerData['type'],
                ], $result['user']);
            } catch (Throwable $e) {
                $referrerLinkError = $e->getMessage();
            }
        }

        // v0.22.4 — dijalankan TERAKHIR, SETELAH bagian Referrer (bukan
        // sebelum) — User sudah pasti tersimpan sejak transaksi di atas
        // commit, jadi urutan relatif terhadap Referrer tidak krusial;
        // ditaruh di akhir supaya method ini tetap linear untuk dibaca.
        // Non-fatal by design (try-catch, sama posture referrer_link_error
        // di atas) — kegagalan kirim WA (mis. sesi "direct" belum
        // connected, template belum di-seed) TIDAK PERNAH menggagalkan
        // create() staff yang sudah berhasil disimpan.
        $this->sendInitialPasswordMessages($result['user'], $result['generated_password']);

        return [
            'user' => $result['user'],
            'generated_password' => $result['generated_password'],
            'referrer' => $referrer,
            'referrer_link_error' => $referrerLinkError,
        ];
    }

    /**
     * v0.22.4 — 2 PESAN TERPISAH ke `users.phone`, dikunci eksplisit oleh
     * Agung (bukan digabung 1 pesan panjang):
     *  1. `StaffInitialPasswordNotice` — teks penjelasan (template biasa,
     *     bisa diedit admin lewat UI Template WA).
     *  2. `StaffInitialPasswordValue` — password POLOS itu sendiri, tanpa
     *     karakter/format lain menempel (gampang tap-hold copy di WA).
     *     SENGAJA lewat `queueRawForRecipient()` (bukan
     *     `buildAndQueueForRecipient()`) — tidak pernah melalui sistem
     *     Template WA sama sekali, supaya isi pesan ini TERJAMIN SECARA
     *     STRUKTURAL tetap persis password, tidak bisa "dirusak" admin
     *     lewat edit template (lihat docblock `WhatsappEventType::
     *     StaffInitialPasswordValue`).
     *
     * Non-fatal — dibungkus try-catch di `create()` (pemanggil satu-
     * satunya), kegagalan di sini di-log tapi tidak pernah menggagalkan
     * pembuatan staff yang sudah tersimpan.
     */
    private function sendInitialPasswordMessages(User $staff, string $password): void
    {
        try {
            $this->whatsappGateway->buildAndQueueForRecipient(
                WhatsappEventType::StaffInitialPasswordNotice,
                $staff->tenant_id,
                $staff->phone,
                ['recipient_name' => $staff->name, 'company_name' => $staff->tenant?->name],
            );

            $this->whatsappGateway->queueRawForRecipient(
                WhatsappEventType::StaffInitialPasswordValue,
                $staff->tenant_id,
                $staff->phone,
                $password,
            );
        } catch (Throwable $e) {
            Log::warning("StaffService: gagal mengantre pesan password awal ke WhatsApp untuk staff #{$staff->id}: {$e->getMessage()}");
        }
    }

    /**
     * Nama/email/HP/role saja — TANPA password (password auto-sent saat
     * create() saja — lihat sendInitialPasswordMessages(); update() tidak
     * pernah mengubah/mengirim ulang password). `syncRoles()` dipakai
     * (bukan `assignRole()`) supaya role lama benar-benar lepas — satu
     * staff cuma boleh punya satu role di CRUD ini (single-choice, dikunci
     * eksplisit).
     *
     * @param  array{name: string, email?: ?string, phone: string, role: string}  $data
     */
    public function update(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => WhatsappPhone::normalize($data['phone']),
        ]);

        $user->syncRoles([$data['role']]);

        // Auto-sync `technicians` — role BISA berubah masuk/keluar
        // 'teknisi' lewat form edit ini, lihat docblock
        // syncTechnicianStatus().
        $this->syncTechnicianStatus($user);

        return $user->fresh();
    }

    /**
     * Soft-disable — bukan soft-delete Eloquent, murni flag boolean yang
     * dicek di titik login (lihat FortifyServiceProvider::authenticateUsing()
     * dan ReferrerLoginController::login()). UI harus pakai teks
     * "Disable"/"Enable", BUKAN "Aktifkan"/"Nonaktifkan" — dikunci eksplisit.
     *
     * v0.22.3 — kalau staff ini punya Referrer ter-link (checkbox "Jadikan
     * juga Referrer" saat create), Referrer ikut nonaktif otomatis
     * (dikunci Agung: status Referrer MENGIKUTI status staff). Cuma
     * disentuh kalau genuinely masih aktif — `ReferrerService::deactivate()`
     * idempoten juga, tapi mengecek dulu di sini menghindari `updated_at`
     * berubah tanpa perubahan nilai untuk Referrer yang sudah nonaktif.
     */
    public function disable(User $user): User
    {
        $user->update(['is_disabled' => true]);

        $referrer = $this->linkedReferrer($user);

        if ($referrer !== null && $referrer->is_active) {
            $this->referrerService->deactivate($referrer);
        }

        // Technician ter-link ikut nonaktif — staff yang tidak bisa login
        // tidak boleh terus menerima broadcast japri WO (v0.26.0) atau
        // lolos otorisasi WhatsappTechnicianAuthService (v0.13.3). TIDAK
        // bergantung pada role saat ini (beda dari enable() di bawah) —
        // staff yang di-disable ya di-disable, terlepas dari role apa pun.
        $technician = $this->findTechnicianFor($user);

        if ($technician !== null && $technician->status === TechnicianStatus::Active) {
            $technician->update(['status' => TechnicianStatus::Inactive]);
        }

        return $user->fresh();
    }

    /**
     * v0.22.3 — counterpart `disable()`: Referrer ter-link ikut aktif
     * lagi, mengikuti status staff.
     */
    public function enable(User $user): User
    {
        $user->update(['is_disabled' => false]);

        $referrer = $this->linkedReferrer($user);

        if ($referrer !== null && ! $referrer->is_active) {
            $this->referrerService->activate($referrer);
        }

        // Technician ter-link ikut aktif lagi — TAPI HANYA kalau role
        // 'teknisi' MASIH melekat ke staff ini saat ini. Staff yang
        // role-nya sudah dipindah ke non-teknisi SAAT MASIH disabled tidak
        // boleh diam-diam kembali jadi teknisi aktif cuma karena di-enable
        // ulang — enable() ini murni membalik disable(), tidak pernah
        // mengubah keputusan role yang sudah terjadi lewat update().
        if ($user->hasRole(self::TECHNICIAN_ROLE)) {
            $technician = $this->findTechnicianFor($user);

            if ($technician !== null && $technician->status === TechnicianStatus::Inactive) {
                $technician->update(['status' => TechnicianStatus::Active]);
            }
        }

        return $user->fresh();
    }

    /**
     * Referrer yang ter-link ke akun staff ini lewat `referrers.user_id`
     * (checkbox "Jadikan juga Referrer" saat create, v0.22.3) — `null`
     * kalau tidak ada (staff biasa). `withoutGlobalScopes()` sengaja —
     * method ini dipanggil dari `disable()`/`enable()`/`delete()` yang
     * bisa berjalan di luar konteks request Livewire/Auth (mis. command
     * line/queue), sama posture guard `delete()` lain di kelas ini.
     */
    public function linkedReferrer(User $user): ?Referrer
    {
        return Referrer::withoutGlobalScopes()->where('user_id', $user->id)->first();
    }

    /**
     * Baris `technicians` ter-link ke akun staff ini lewat `technicians.
     * user_id` — `null` kalau belum/tidak pernah ada (staff biasa, atau
     * belum pernah punya role 'teknisi'). `withoutGlobalScopes()` sama
     * posture `linkedReferrer()` di atas — dipanggil dari method yang bisa
     * berjalan di luar konteks request Livewire/Auth.
     */
    public function findTechnicianFor(User $user): ?Technician
    {
        return Technician::withoutGlobalScopes()->where('user_id', $user->id)->first();
    }

    /**
     * Sinkronisasi 1 ARAH: role Spatie 'teknisi' (lihat const
     * TECHNICIAN_ROLE) adalah SUMBER KEBENARAN, baris `technicians`
     * MENGIKUTI — menutup gap arsitektur yang didokumentasikan di
     * docs/ROADMAP.md ("Backlog — Gap Arsitektur: Tabel technicians Tidak
     * Tersinkron dengan users + Role Teknisi", ditemukan 2026-09-17).
     * Dipanggil dari `create()`/`update()` (SETELAH assignRole()/
     * syncRoles() — `$user->hasRole()` di sini WAJIB melihat role yang
     * baru saja di-assign, Spatie meng-update relasi in-memory instance
     * yang sama begitu assignRole()/syncRoles() dipanggil, jadi tidak
     * perlu `$user->fresh()` dulu) dan dipakai ulang PERSIS oleh command
     * backfill (`technicians:sync-from-staff-roles`) — satu implementasi,
     * bukan logic yang diduplikasi dan bisa drift.
     *
     * Match SATU-SATUNYA by `user_id` (bukan name/phone — keduanya bisa
     * berubah kapan saja lewat `update()` staff, `user_id` yang stabil).
     * Baris existing TIDAK PERNAH dihapus di sini, cuma status
     * Active<->Inactive — StaffService::delete() (baris ~321, TIDAK
     * diubah oleh method ini) memblokir hapus staff yang MASIH punya baris
     * Technician APA PUN (status apa pun), dan histori Work Order/
     * `work_order_technicians` harus tetap utuh. Konsekuensi yang
     * DISADARI, bukan bug: begitu seorang staff PERNAH diberi role
     * 'teknisi' sekali saja, baris Technician-nya (Inactive) permanen ada
     * — staff itu tidak akan pernah bisa dihapus lagi lewat
     * StaffService::delete() selama baris itu masih ada, konsisten dengan
     * guard yang sudah ada (bukan perilaku baru dari method ini).
     *
     * `reseller_id` diturunkan dari keanggotaan `reseller_users` staff ini
     * (null = ISP direct, pola sama seluruh modul ini) — bukan parameter,
     * supaya method ini bisa dipanggil ulang kapan saja dari mana saja
     * (termasuk command backfill) hanya dengan `$user` itu sendiri.
     */
    public function syncTechnicianStatus(User $user): void
    {
        $technician = $this->findTechnicianFor($user);

        if (! $user->hasRole(self::TECHNICIAN_ROLE)) {
            if ($technician !== null && $technician->status !== TechnicianStatus::Inactive) {
                $technician->update(['status' => TechnicianStatus::Inactive]);
            }

            return;
        }

        $resellerId = ResellerUser::where('user_id', $user->id)->value('reseller_id');

        Technician::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->tenant_id,
                'reseller_id' => $resellerId,
                'name' => $user->name,
                'phone' => WhatsappPhone::normalize($user->phone),
                'status' => TechnicianStatus::Active,
            ],
        );
    }

    /**
     * Hard delete beneran — `users` TIDAK pakai SoftDeletes (dikonfirmasi
     * langsung dari migration+model sebelum ditulis, bukan diasumsikan).
     *
     * WAJIB dicek dulu SEBELUM `$staff->delete()` — 3 relasi nyata yang
     * DB sendiri TIDAK akan menahan dengan pesan jelas (lihat investigasi
     * Langkah 0 v0.22.1 di CLAUDE.md untuk daftar FK lengkap):
     *  - `reseller_users.user_id` CASCADE — diam-diam menghapus baris
     *    keanggotaan reseller kalau tidak di-guard. Diblokir kalau ADA
     *    baris APA PUN (status apa pun), dikunci eksplisit — bukan cuma
     *    yang status=active.
     *  - `technicians.user_id` CASCADE — diam-diam menghapus baris
     *    Technician + histori klaim WO (`work_order_technicians`, juga
     *    cascade dari `technicians`). Diblokir kalau baris Technician ADA
     *    SAMA SEKALI, apa pun status Work Order-nya — dikunci eksplisit,
     *    bukan cuma yang masih punya WO aktif. Pesan dibedakan: kalau
     *    genuinely ada WO belum selesai, sebutkan jumlahnya; kalau tidak,
     *    tetap diblokir tapi dengan alasan generik "masih terdaftar
     *    sebagai akun Teknisi".
     *  - `cpe_action_logs.performed_by` — `constrained('users')` TANPA
     *    `nullOnDelete()` sama sekali di migration aslinya = default
     *    RESTRICT di level DB. Tanpa guard ini, `$staff->delete()` akan
     *    throw QueryException MENTAH (FK violation), bukan pesan yang
     *    bisa dibaca admin.
     *
     * Setiap relasi lain yang menunjuk ke `users` sudah `nullOnDelete()`
     * (histori tetap utuh, aktor jadi null) — TIDAK memblokir delete.
     *
     * v0.22.3 — `referrers.user_id` TERMASUK yang `nullOnDelete()` sejak
     * migration `agents` yang paling awal (BUKAN hal baru dari sub-versi
     * ini) — kalau staff yang punya Referrer ter-link di-delete, DB SENDIRI
     * otomatis men-set `referrers.user_id = NULL`, tanpa kode tambahan apa
     * pun di sini. Ini SENGAJA TIDAK diblokir (beda dari 3 guard di atas)
     * — dikunci eksplisit oleh Agung: pelajaran dari insiden Kamisem
     * (v0.22.1), Referrer harus tetap independen secara data, cuma
     * kehilangan akses login-nya. `linkedReferrer()` di atas dipanggil
     * CALLER (StaffIndex) SEBELUM `delete()` untuk menampilkan pesan
     * konfirmasi yang berbeda kalau staff ini genuinely Referrer aktif —
     * bukan blocking guard di service ini.
     *
     * @throws RuntimeException kalau masih ada relasi yang nyantol, pesan
     *                          spesifik per kasus (bukan generik).
     */
    public function delete(User $staff): void
    {
        $resellerMemberships = ResellerUser::where('user_id', $staff->id)
            ->with('reseller')
            ->get();

        if ($resellerMemberships->isNotEmpty()) {
            $names = $resellerMemberships->pluck('reseller.name')->filter()->unique()->implode(', ');

            throw new RuntimeException("Tidak bisa dihapus — staff ini masih terdaftar sebagai member reseller: {$names}.");
        }

        $technician = Technician::withoutGlobalScopes()->where('user_id', $staff->id)->first();

        if ($technician !== null) {
            $activeWorkOrderCount = WorkOrder::withoutGlobalScopes()
                ->where('technician_id', $technician->id)
                ->whereNotIn('status', [WorkOrderStatus::Completed->value, WorkOrderStatus::Cancelled->value])
                ->count();

            if ($activeWorkOrderCount > 0) {
                throw new RuntimeException("Tidak bisa dihapus — staff ini masih jadi teknisi penanggung jawab {$activeWorkOrderCount} Work Order aktif.");
            }

            throw new RuntimeException('Tidak bisa dihapus — staff ini masih terdaftar sebagai akun Teknisi. Lepas/hapus status teknisi ini dulu sebelum menghapus akun staff.');
        }

        $actionLogCount = CpeActionLog::withoutGlobalScopes()->where('performed_by', $staff->id)->count();

        if ($actionLogCount > 0) {
            throw new RuntimeException("Tidak bisa dihapus — staff ini tercatat pernah melakukan {$actionLogCount} aksi perangkat CPE (riwayat audit tidak boleh kehilangan aktornya).");
        }

        DB::transaction(function () use ($staff) {
            $this->lockCustomerReferralsForDeletedStaff($staff);

            $staff->delete();
        });
    }

    /**
     * v0.22.7 — kalau staff yang dihapus JUGA Referrer ter-link (checkbox
     * "Jadikan juga Referrer", v0.22.3), setiap `Customer` yang mengarah ke
     * Referrer itu (`referred_by_referrer_id`) TIDAK dibiarkan nyantol ke
     * Referrer yang login-nya sudah hilang — genuinely dikosongkan, dengan
     * jejak namanya disimpan supaya tidak hilang sama sekali dari tampilan
     * (`referral_locked`+`locked_former_referrer_name`, lihat migration
     * `add_referral_lock_to_customers_table`). Dipanggil SEBELUM
     * `$staff->delete()`, di transaksi yang sama — Referrer itu sendiri
     * TIDAK disentuh di sini (tetap ada, `user_id`-nya jadi null via
     * `nullOnDelete()` seperti sebelumnya, TIDAK berubah dari v0.22.3).
     *
     * TIDAK ada migration data retroaktif untuk kasus LAMA (Kamisem,
     * insiden v0.22.1) — dikonfirmasi Agung, kasus itu sudah dibereskan
     * manual sebelum kolom ini ada, dan datanya sendiri sudah bersih
     * (0 customer nyantol) saat diverifikasi ulang di kickoff v0.22.7.
     */
    private function lockCustomerReferralsForDeletedStaff(User $staff): void
    {
        $referrer = $this->linkedReferrer($staff);

        if ($referrer === null) {
            return;
        }

        Customer::withoutGlobalScopes()
            ->where('referred_by_referrer_id', $referrer->id)
            ->update([
                'referral_locked' => true,
                'locked_former_referrer_name' => $referrer->name,
                'referred_by_referrer_id' => null,
            ]);
    }
}
