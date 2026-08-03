<?php

namespace App\Support;

use App\Models\Reseller;

/**
 * Container-bound (singleton, per-request) holder for "which reseller is the
 * acting user currently operating as", resolved by
 * App\Http\Middleware\ResolveResellerContext. Deliberately not session-based
 * so it stays trivial to set directly in tests without a real HTTP request.
 */
class ResellerContext
{
    public function __construct(protected ?Reseller $reseller = null) {}

    public function reseller(): ?Reseller
    {
        return $this->reseller;
    }

    public function set(?Reseller $reseller): void
    {
        $this->reseller = $reseller;
    }

    public function hasReseller(): bool
    {
        return $this->reseller !== null;
    }
}
