<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * v0.22.6 — halaman WAJIB diisi begitu `App\Http\Middleware\
 * EnsurePasswordChanged` mengarahkan ke sini (staff dengan
 * `must_change_password === true`, password-nya di-generate random lewat
 * StaffService::create()). Reuse layout `layouts.staff-guest` — form
 * center-card sederhana, TANPA sidebar/navigasi (user tetap tidak bisa
 * "kabur" ke halaman lain bahkan seandainya sidebar ada — GET baru mana
 * pun akan kena middleware ini lagi — tapi layout minimal ini lebih jelas
 * secara UX daripada menampilkan sidebar yang percuma diklik).
 *
 * TIDAK meminta "Password Saat Ini" — dikonfirmasi eksplisit Agung: ini
 * alur WAJIB di LOGIN PERTAMA, bukan ganti password sukarela dari halaman
 * profil. User sudah lolos autentikasi normal (login dengan password
 * lama berhasil dulu, baru di-redirect ke sini oleh middleware
 * `EnsurePasswordChanged`) — minta re-input password lama di titik ini
 * cuma nambah friksi tanpa nilai keamanan tambahan (beda dari
 * StaffForgotPassword, yang jalur GUEST dan verifikasi identitasnya lewat
 * OTP WhatsApp karena user belum login sama sekali).
 */
#[Layout('layouts.staff-guest', ['title' => 'Ganti Password'])]
class ChangePassword extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    public function submit(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        Auth::user()->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
        ])->save();

        $this->redirectRoute('web.dashboard', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.change-password');
    }
}
