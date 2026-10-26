<?php

namespace App\Services\Network\Provisioning;

use App\Models\Customer;

/**
 * Substitusi placeholder nama SSID template ZTP (v0.23.8.4):
 *   {cid}  → Customer::cid
 *   {name} → Customer::name
 * Placeholder tak dikenal DIBIARKAN apa adanya (bukan dihapus senyap) — jadi
 * typo `{cdi}` tetap tampak sebagai `{cdi}` di hasil (mudah ditemukan admin),
 * bukan hilang diam-diam.
 */
class SsidNameResolver
{
    public function resolve(string $template, Customer $customer): string
    {
        return strtr($template, [
            '{cid}' => (string) $customer->cid,
            '{name}' => (string) $customer->name,
        ]);
    }
}
