<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Profil provisioning ZTP (v0.23.8.3) — bundle: 1 WAN Internet (wajib) +
 * 0..1 WAN Bridge + (v0.23.8.4) 0..N SSID. Di-resolve on-demand per aktivasi
 * (ProvisioningProfileResolver), TIDAK disimpan permanen di device.
 */
class ProvisioningProfile extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'enabled',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function wanInternet(): HasOne
    {
        return $this->hasOne(ProvisioningWanInternet::class);
    }

    public function wanBridge(): HasOne
    {
        return $this->hasOne(ProvisioningWanBridge::class);
    }

    public function pppPackages(): BelongsToMany
    {
        return $this->belongsToMany(PppPackage::class, 'package_provisioning_profiles')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function cpeDevices(): HasMany
    {
        return $this->hasMany(CpeDevice::class);
    }
}
