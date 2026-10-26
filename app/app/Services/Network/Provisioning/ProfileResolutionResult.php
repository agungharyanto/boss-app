<?php

namespace App\Services\Network\Provisioning;

use App\Models\ProvisioningProfile;

/**
 * Hasil resolusi profil ZTP untuk sebuah CPE (v0.23.8.3). `profile` null =
 * tak ter-resolve; `source` menjelaskan JALUR keputusan; `reason` diisi hanya
 * saat null (alasan jelas, bukan gagal senyap).
 */
final class ProfileResolutionResult
{
    public function __construct(
        public readonly ?ProvisioningProfile $profile,
        public readonly string $source,
        public readonly ?string $reason = null,
    ) {}

    public function resolved(): bool
    {
        return $this->profile !== null;
    }
}
