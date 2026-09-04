<?php

namespace App\Livewire\Auth;

use App\Models\Referrer;
use App\Models\User;
use App\Services\Commission\ReferrerActionOtpService;
use App\Services\Commission\ReferrerOtpException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * v0.9.6 — "Lupa Password" untuk akun Portal Referrer. Reuse
 * `ReferrerActionOtpService` (dibangun v0.9.6 Langkah 2) — TIDAK ada
 * infrastruktur OTP baru.
 *
 * Scope OTP `"password_reset:{referrerId}"` — BEDA dari scope
 * `"titip:{customerId}"` alur Catat Titip, jadi kode reset password tidak
 * bisa dipakai untuk aksi Titip dan sebaliknya (cache key berbeda per
 * scope — lihat ReferrerActionOtpService::cacheKey()).
 *
 * Anti-enumerasi: nomor HP yang tidak ditemukan / Referrer non-aktif
 * TIDAK menghasilkan error spesifik — selalu maju ke tahap OTP dengan
 * pesan generik yang sama. Referrer id yang cocok disimpan di SESSION
 * (server-side, tidak diekspos ke browser lewat properti Livewire).
 */
#[Layout('layouts.referrer-guest')]
class ReferrerForgotPassword extends Component
{
    private const SESSION_ID = 'referrer_pwreset_id';

    private const SESSION_VERIFIED_AT = 'referrer_pwreset_verified_at';

    /** 'phone' | 'otp' | 'password' | 'done' */
    public string $stage = 'phone';

    public string $phone = '';

    public string $otp = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $notice = null;

    public bool $otpResent = false;

    public function submitPhone(ReferrerActionOtpService $otpService): void
    {
        $this->validate(['phone' => ['required', 'string', 'max:30']]);

        $referrer = $this->resolveReferrer($this->phone);

        if ($referrer !== null) {
            session([self::SESSION_ID => $referrer->id]);
            session()->forget(self::SESSION_VERIFIED_AT);

            try {
                $otpService->issue($referrer, $this->scopeFor($referrer->id), 'reset password akun Portal Referrer');
            } catch (ReferrerOtpException) {
                // Rate-limited / template belum di-seed — jangan bocorkan.
                // Pesan generik yang sama dipakai apa pun hasilnya.
            }
        }

        $this->stage = 'otp';
        $this->otp = '';
        $this->notice = __('Kalau nomor ini terdaftar, kode verifikasi 6 digit telah dikirim ke WhatsApp. Kode berlaku 5 menit.');
        $this->resetErrorBag();
    }

    public function resendOtp(ReferrerActionOtpService $otpService): void
    {
        if ($this->stage !== 'otp') {
            return;
        }

        $id = session(self::SESSION_ID);

        if ($id !== null && ($referrer = $this->activeReferrer((int) $id)) !== null) {
            try {
                $otpService->issue($referrer, $this->scopeFor((int) $id), 'reset password akun Portal Referrer');
                $this->otpResent = true;
            } catch (ReferrerOtpException $e) {
                $this->addError('otp', $e->getMessage());

                return;
            }
        }

        // Tidak ada id di session (nomor tak terdaftar) → diam-diam,
        // pesan generik tetap konsisten.
        $this->otpResent = true;
        $this->resetErrorBag('otp');
    }

    public function submitOtp(ReferrerActionOtpService $otpService): void
    {
        if ($this->stage !== 'otp') {
            return;
        }

        $this->validate(['otp' => ['required', 'string']]);

        $id = session(self::SESSION_ID);
        $referrer = $id !== null ? $this->activeReferrer((int) $id) : null;

        if ($referrer === null) {
            $this->addError('otp', __('Kode salah atau sudah kedaluwarsa. Ulangi dari awal.'));

            return;
        }

        try {
            $otpService->verify($referrer, $this->scopeFor($referrer->id), $this->otp);
        } catch (ReferrerOtpException $e) {
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

        $referrer = $this->activeReferrer((int) $id);
        $user = $referrer !== null ? User::find($referrer->user_id) : null;

        if ($user === null) {
            session()->forget([self::SESSION_ID, self::SESSION_VERIFIED_AT]);
            $this->addError('password', __('Akun tidak ditemukan. Hubungi admin.'));
            $this->stage = 'phone';

            return;
        }

        $user->forceFill(['password' => Hash::make($this->password)])->save();

        session()->forget([self::SESSION_ID, self::SESSION_VERIFIED_AT]);

        $this->reset(['phone', 'otp', 'password', 'password_confirmation']);
        $this->stage = 'done';
        $this->notice = null;
    }

    private function resolveReferrer(string $phone): ?Referrer
    {
        // Guest request → BelongsToTenant's TenantScope tidak aktif (hanya
        // filter saat Auth::check()), jadi ini cari lintas tenant — sama
        // pola ReferrerLoginController.
        $referrer = Referrer::query()->where('phone', $phone)->orderBy('id')->first();

        if ($referrer === null || ! $referrer->is_active || $referrer->user_id === null) {
            return null;
        }

        return $referrer;
    }

    private function activeReferrer(int $id): ?Referrer
    {
        return Referrer::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->first();
    }

    private function scopeFor(int $referrerId): string
    {
        return "password_reset:{$referrerId}";
    }

    public function render(): View
    {
        return view('livewire.auth.referrer-forgot-password');
    }
}
