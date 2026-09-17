<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password',
        'is_disabled',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_disabled' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * v0.22.3 — Referrer yang di-link ke akun staff ini lewat checkbox
     * "Jadikan juga Referrer" (`referrers.user_id`). Nullable — kebanyakan
     * staff bukan Referrer sama sekali. `Referrer` sendiri pakai
     * `BelongsToTenant` (TenantScope) — aman dipakai relasi standar (tanpa
     * `withoutGlobalScopes()`) di konteks yang SUDAH tenant-scoped sendiri
     * (mis. StaffIndex, yang hanya pernah menampilkan staff tenant yang
     * login) karena TenantScope-nya otomatis konsisten dengan konteks itu;
     * `StaffService` (bisa dipanggil di luar request Livewire/Auth, mis.
     * command line) tetap query `Referrer::withoutGlobalScopes()` sendiri,
     * tidak lewat relasi ini.
     */
    public function referrer(): HasOne
    {
        return $this->hasOne(Referrer::class);
    }
}
