<?php

namespace App\Models;

use App\Enums\PonMode;
use App\Enums\ProvisioningAdapterLayer;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris MIRROR status/visibilitas adapter ZTP (v0.23.8.1). Diisi dari registry
 * KODE lewat ProvisioningAdapterSeeder (kode = sumber kebenaran, keputusan #1).
 * Dipakai UI matriks dukungan — BUKAN penentu class adapter.
 */
class ProvisioningAdapter extends Model
{
    protected $fillable = [
        'layer',
        'vendor_key',
        'pon_mode',
        'transport',
        'is_supported',
        'capabilities',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'layer' => ProvisioningAdapterLayer::class,
            'pon_mode' => PonMode::class,
            'is_supported' => 'boolean',
            'capabilities' => 'array',
        ];
    }
}
