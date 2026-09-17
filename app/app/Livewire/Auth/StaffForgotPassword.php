<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\StaffActionOtpService;
use App\Services\StaffOtpException;
use App\Support\WhatsappPhone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * v0.22.4 — "Lupa Password" untuk akun STAFF (bukan Referrer — lihat
 * `ReferrerForgotPassword` untuk jalur itu). Reuse pola/infrastruktur OTP
 * yang sama (`App\Services\Shared\ActionOtpService`, lewat
 * `StaffActionOtpService`) — TIDAK ada infrastruktur OTP baru dibangun di
 * sini, hanya thin wrapper baru + lookup `users.phone`.
 *
 * Scope OTP `"staff_password_reset:{userId}"` — BEDA dari scope
 * `"password_reset:{referrerId}"` milik Referrer (dan `recipientType`
 * 'staff' vs 'referrer' di `ActionOtpService` sudah membuat cache key-nya
 * sendiri terisolasi — prefix scope ini cuma untuk keterbacaan).
 *
 * `resolveStaff()` WAJIB `whereHas('roles')` — pelajaran insiden Kamisem
 * (v0.22.1): akun Referrer portal zero-role TIDAK boleh bisa reset
 * password lewat jalur STAFF ini (dan sebaliknya, `ReferrerForgotPassword`
 * sendiri tidak tersentuh sama sekali oleh perubahan ini). Staff yang
 * `is_disabled` juga ditolak — sama logic "tidak boleh login sama sekali",
 * jadi juga tidak boleh reset password sendiri.
 *
 * Anti-enumerasi: nomor HP yang tidak ditemukan / bukan staff sungguhan /
 * disabled TIDAK menghasilkan error spesifik — selalu maju ke tahap OTP
 * dengan pesan generik yang sama, persis pola `ReferrerForgotPassword`.
 *
 * Setelah OTP tervalidasi, staff INPUT PASSWORD SENDIRI (form
 * password+konfirmasi) — bukan random baru — dikunci eksplisit di kickoff
 * v0.22.4, konsisten pola Referrer yang sudah jalan.
 */
#[Layout('layouts.staff-guest')]
class StaffForgotPassword extends Component
{
    private const SESSION_ID = 'staff_pwreset_id';

    private const SESSION_VERIFIED_AT = 'staff_pwreset_verified_at';

    /** 'phone' | 'otp' | 'password' | 'done' */
    public string $stage = 'phone';

    public string $phone = '';

    public string $otp = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $notice = null;

    public bool $otpResent = false;

    public function submitPhone(StaffActionOtpService $otpService): void
    {
        $this->validate(['phone' => ['required', 'string', 'max:30']]);

        $staff = $this->resolveStaff($this->phone);

        if ($staff !== null) {
            session([self::SESSION_ID => $staff->id]);
            session()->forget(self::SESSION_VERIFIED_AT);

            try {
                $otpService->issue($staff, $this->scopeFor($staff->id), 'reset password akun staff BOSS App');
            } catch (StaffOtpException) {
                // Rate-limited / template belum di-seed — jangan bocorkan.
                // Pesan generik yang sama dipakai apa pun hasilnya.
            }
        }

        $this->stage = 'otp';
        $this->otp = '';
        $this->notice = __('Kalau nomor ini terdaftar, kode verifikasi 6 digit telah dikirim ke WhatsApp. Kode berlaku 5 menit.');
        $this->resetErrorBag();
    }

    public function resendOtp(StaffActionOtpService $otpService): void
    {
        if ($this->stage !== 'otp') {
            return;
        }

        $id = session(self::SESSION_ID);

        if ($id !== null && ($staff = $this->activeStaff((int) $id)) !== null) {
            try {
                $otpService->issue($staff, $this->scopeFor((int) $id), 'reset password akun staff BOSS App');
                $this->otpResent = true;
            } catch (StaffOtpException $e) {
                $this->addError('otp', $e->getMessage());

                return;
            }
        }

        // Tidak ada id di session (nomor tak terdaftar) → diam-diam,
        // pesan generik tetap konsisten.
        $this->otpResent = true;
        $this->resetErrorBag('otp');
    }

    public function submitOtp(StaffActionOtpService $otpService): void
    {
        if ($this->stage !== 'otp') {
            return;
        }

        $this->validate(['otp' => ['required', 'string']]);

        $id = session(self::SESSION_ID);
        $staff = $id !== null ? $this->activeStaff((int) $id) : null;

        if ($staff === null) {
            $this->addError('otp', __('Kode salah atau sudah kedaluwarsa. Ulangi dari awal.'));

            return;
        }

        try {
            $otpService->verify($staff, $this->scopeFor($staff->id), $this->otp);
        } catch (StaffOtpException $e) {
            $this->addError('otp', $e->getMessage());

            return;
        }

        session([self::SESSION_VERIFIED_AT => now()->timestamp]);

        $this->stage = 'password';
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetErrorBag();
    }

    public function submitPassword(): void
    {
        if ($this->stage !== 'password') {
            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $id = session(self::SESSION_ID);
        $verifiedAt = session(self::SESSION_VERIFIED_AT);

        // OTP harus sudah diverifikasi di sesi ini, dan belum lebih dari
        // 10 menit lalu.
        if ($id === null || $verifiedAt === null || now()->timestamp - (int) $verifiedAt > 600) {
            session()->forget([self::SESSION_ID, self::SESSION_VERIFIED_AT]);
            $this->addError('password', __('Sesi verifikasi kedaluwarsa. Ulangi dari awal.'));
            $this->stage = 'phone';

            return;
        }

        $staff = $this->activeStaff((int) $id);

        if ($staff === null) {
            session()->forget([self::SESSION_ID, self::SESSION_VERIFIED_AT]);
            $this->addError('password', __('Akun tidak ditemukan. Hubungi admin.'));
            $this->stage = 'phone';

            return;
        }

        // v0.22.6 — user sudah PILIH SENDIRI password barunya lewat alur
        // OTP ini, tidak perlu dipaksa ganti lagi begitu login (beda dari
        // password random yang StaffService::create() generate).
        $staff->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
        ])->save();

        session()->forget([self::SESSION_ID, self::SESSION_VERIFIED_AT]);

        $this->reset(['phone', 'otp', 'password', 'password_confirmation']);
        $this->stage = 'done';
        $this->notice = null;
    }

    private function resolveStaff(string $phone): ?User
    {
        $normalized = WhatsappPhone::normalize($phone);

        return $this->activeStaffQuery()->where('phone', $normalized)->first();
    }

    private function activeStaff(int $id): ?User
    {
        return $this->activeStaffQuery()->where('id', $id)->first();
    }

    /**
     * `whereHas('roles')` — hanya akun staff SUNGGUHAN (punya role Spatie)
     * yang bisa lewat jalur ini, bukan akun Referrer portal zero-role
     * (lihat docblock kelas ini). `is_disabled=false` — staff yang
     * di-disable tidak boleh reset password sendiri, sama seperti tidak
     * boleh login sama sekali.
     */
    private function activeStaffQuery()
    {
        return User::whereHas('roles')->where('is_disabled', false);
    }

    private function scopeFor(int $userId): string
    {
        return "staff_password_reset:{$userId}";
    }

    public function render(): View
    {
        return view('livewire.auth.staff-forgot-password');
    }
}
