<?php

namespace App\Models;

use App\Enums\VpnProtocol;
use App\Enums\VpnServerStatus;
use App\Support\CidrRange;
use Database\Factories\VpnServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnServer extends Model
{
    /** @use HasFactory<VpnServerFactory> */
    use HasFactory;

    protected $fillable = [
        'hostname',
        'public_ip',
        'port',
        'subnet_cidr',
        'protocol',
        'max_clients',
        'current_clients',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'protocol' => VpnProtocol::class,
            'status' => VpnServerStatus::class,
            'is_active' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(VpnAccount::class);
    }

    /**
     * v0.6.4 multi-node pool: sibling nodes of the same protocol
     * (openvpn-node1/2/3, wireguard-node1/2/3) share ONE credential/IP
     * pool "owner" — the ORIGINAL, lowest-id row for that protocol (the
     * only node whose PKI/ccd or wg peers directory is actually mounted
     * into boss-app via docker-compose's shared volumes — see
     * VpnProvisioningService's config('services.vpn.*_dir') paths, which
     * are still single global paths, not per-node). Every sibling node
     * DOES trust/recognize credentials issued against the pool owner
     * (shared PKI volume for OpenVPN, shared peers dir + server keypair
     * for WireGuard — see CLAUDE.md "Multi-Node VPN Pool (v0.6.4)"), so
     * an account provisioned with its "preferred" node set to node2/node3
     * (see VpnProvisioningService::provision()'s load-balancing) still
     * needs its actual internal_ip/vpn_ip_pool row allocated from the pool
     * owner's pool specifically, not a locally-independent one, to avoid
     * OpenVPN's ccd (node1-only, never shared) ever pushing an IP outside
     * node1's own configured subnet.
     */
    public static function poolOwnerFor(VpnProtocol $protocol): self
    {
        return static::query()
            ->where('protocol', $protocol)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function ipPool(): HasMany
    {
        return $this->hasMany(VpnIpPool::class);
    }

    /**
     * Expands subnet_cidr into individual vpn_ip_pool rows (status
     * available) — same shape as Odp::provisionPorts(): a model method
     * called explicitly by the controller right after creation, NOT a
     * `created` event (avoids colliding with VpnIpPoolFactory-created rows
     * in tests, same reasoning as v0.5.0).
     */
    public function provisionIpPool(): void
    {
        collect(CidrRange::usableHostAddresses($this->subnet_cidr))->each(
            fn (string $ip) => $this->ipPool()->create(['ip_address' => $ip])
        );
    }
}
