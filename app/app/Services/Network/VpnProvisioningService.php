<?php

namespace App\Services\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnIpPoolStatus;
use App\Enums\VpnProtocol;
use App\Enums\VpnServerStatus;
use App\Exceptions\VpnIpPoolExhaustedException;
use App\Exceptions\VpnProvisioningException;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Models\VpnIpPool;
use App\Models\VpnServer;
use App\Models\VpnWireguardNasBlock;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class VpnProvisioningService
{
    /**
     * (1) Allocates an internal_ip under a row lock (race-condition-safe —
     * same lockForUpdate() pattern as OdpPort in WorkOrderService) against
     * the vpn_servers row for THIS protocol (v0.6.3: one row per
     * (host, protocol) pair, not one row covering several protocols), (2)
     * commits that allocation, THEN (3) issues protocol-specific
     * credentials against the relevant daemon/PKI. Steps 2 and 3 are
     * deliberately NOT in the same DB transaction as step 1 — issuing
     * credentials is a slow external operation, and holding a row lock for
     * its duration would serialize unrelated concurrent provisioning
     * attempts for no reason. If (3) fails, the DB allocation from (1) is
     * explicitly rolled back (IP released, row deleted) rather than left
     * behind as a phantom "active" account with nothing behind it.
     */
    public function provision(Nas $nas, VpnProtocol $protocol = VpnProtocol::OpenVpn): VpnAccount
    {
        $existing = VpnAccount::query()
            ->where('nas_id', $nas->id)
            ->where('protocol', $protocol)
            ->where('status', VpnAccountStatus::Active)
            ->first();

        if ($existing !== null) {
            throw new VpnProvisioningException(
                "NAS '{$nas->name}' sudah punya akun VPN {$protocol->label()} aktif (username: {$existing->username})."
            );
        }

        $this->ensureCapacityFor($protocol);

        $username = 'nas-'.$nas->id;

        $account = DB::transaction(function () use ($nas, $protocol, $username) {
            // v0.6.4 load distribution: among ONLINE nodes for this
            // protocol with spare capacity, prefer the one with the most
            // room (lowest current_clients) — locked to prevent two
            // concurrent provisions both picking the same near-full node
            // and overcommitting past max_clients (same lockForUpdate()
            // pattern already used below for the IP pool row itself).
            // "online" specifically (not just "not offline") — a node
            // reported Full by the health-check job is deliberately
            // excluded here even though it isn't Offline.
            $server = VpnServer::query()
                ->where('protocol', $protocol)
                ->where('is_active', true)
                ->where('status', VpnServerStatus::Online)
                ->whereColumn('current_clients', '<', 'max_clients')
                ->orderBy('current_clients')
                ->lockForUpdate()
                ->first();

            if ($server === null) {
                throw new VpnProvisioningException("Tidak ada VPN server online dengan kapasitas tersedia untuk protokol {$protocol->label()}.");
            }

            // The account's internal_ip always comes from the pool OWNER
            // (not necessarily $server above) — see
            // VpnServer::poolOwnerFor()'s docblock for why sibling nodes
            // can't each have an independent IP pool without breaking
            // OpenVPN's node1-only ccd or WireGuard's shared peers dir.
            $poolOwner = VpnServer::poolOwnerFor($protocol);

            // v0.8.1 — WireGuard gets a dedicated, STICKY /30 block per NAS
            // (VpnWireguardNasBlock) instead of consuming a single /32 row
            // from the shared vpn_ip_pool — replaces the old single shared
            // tunnel gateway (172.23.195.1 for every NAS) with a real
            // per-NAS point-to-point pair. OpenVPN/L2TP are UNCHANGED,
            // still the plain vpn_ip_pool flow below — this redesign is
            // WireGuard-specific (see CLAUDE.md's "WireGuard /30 Per-NAS
            // Tunnel Blocks" section for the full reasoning).
            if ($protocol === VpnProtocol::WireGuard) {
                $block = VpnWireguardNasBlock::allocateFor($nas, $poolOwner);

                $account = VpnAccount::create([
                    'nas_id' => $nas->id,
                    'vpn_server_id' => $server->id,
                    'protocol' => $protocol,
                    'username' => $username,
                    'internal_ip' => $block->router_ip,
                    'status' => VpnAccountStatus::Active,
                    'issued_at' => now(),
                ]);

                $server->increment('current_clients');

                return $account;
            }

            $poolEntry = VpnIpPool::query()
                ->where('vpn_server_id', $poolOwner->id)
                ->where('status', VpnIpPoolStatus::Available)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($poolEntry === null) {
                throw new VpnIpPoolExhaustedException(
                    "Pool IP VPN ({$protocol->label()}, pool owner '{$poolOwner->hostname}') sudah habis."
                );
            }

            $account = VpnAccount::create([
                'nas_id' => $nas->id,
                'vpn_server_id' => $server->id,
                'protocol' => $protocol,
                'username' => $username,
                'internal_ip' => $poolEntry->ip_address,
                'status' => VpnAccountStatus::Active,
                'issued_at' => now(),
            ]);

            $poolEntry->update(['status' => VpnIpPoolStatus::Assigned, 'vpn_account_id' => $account->id]);
            $server->increment('current_clients');

            return $account;
        });

        try {
            match ($protocol) {
                VpnProtocol::OpenVpn => $this->issueOpenVpnCredentials($account),
                VpnProtocol::WireGuard => $this->issueWireGuardCredentials($account),
                VpnProtocol::L2tpIpsec => $this->issueL2tpCredentials($account),
            };
        } catch (RuntimeException $e) {
            $this->rollbackFailedProvisioning($account);

            throw $e;
        }

        // NOT ->fresh() — a fresh() re-query would silently drop
        // $account->wireguardPrivateKey (a transient PHP property, not a DB
        // column; a brand-new model instance from the DB can't possibly
        // carry it). Every issue*Credentials() method above already mutates
        // $account in place via ->update(), so the in-memory instance
        // already reflects every persisted change — nothing is stale here.
        return $account;
    }

    /**
     * v0.16 hotfix (real incident 2026-08-31, ro-hotspot) — a
     * SIDE-EFFECT-FREE version of the "is there an Online node with spare
     * capacity for this protocol" gate inside provision()'s transaction
     * (kept byte-identical to that query on purpose). provision() calls it
     * as an early bail; VpnScriptGenerator::revokeAndRegenerate() calls it
     * BEFORE revoking the NAS's current account, so an obviously-doomed
     * regenerate (e.g. all WireGuard nodes offline after a host reboot)
     * fails while the NAS still has its working account rather than
     * stranding it with none.
     */
    public function ensureCapacityFor(VpnProtocol $protocol): void
    {
        $available = VpnServer::query()
            ->where('protocol', $protocol)
            ->where('is_active', true)
            ->where('status', VpnServerStatus::Online)
            ->whereColumn('current_clients', '<', 'max_clients')
            ->exists();

        if (! $available) {
            throw new VpnProvisioningException(
                "Tidak ada VPN server online dengan kapasitas tersedia untuk protokol {$protocol->label()}."
            );
        }
    }

    /**
     * Deprovisions protocol-specific credentials, then frees the
     * internal_ip back to the pool. See each protocol's revoke method for
     * how "taking effect" differs — none of the three restart their daemon.
     */
    public function revoke(VpnAccount $vpnAccount): VpnAccount
    {
        if ($vpnAccount->status === VpnAccountStatus::Revoked) {
            return $vpnAccount;
        }

        match ($vpnAccount->protocol) {
            VpnProtocol::OpenVpn => $this->revokeOpenVpn($vpnAccount),
            VpnProtocol::WireGuard => $this->revokeWireGuard($vpnAccount),
            VpnProtocol::L2tpIpsec => $this->revokeL2tp($vpnAccount),
        };

        DB::transaction(function () use ($vpnAccount) {
            $vpnAccount->update(['status' => VpnAccountStatus::Revoked, 'revoked_at' => now()]);

            VpnIpPool::query()
                ->where('vpn_account_id', $vpnAccount->id)
                ->update(['status' => VpnIpPoolStatus::Available, 'vpn_account_id' => null]);

            $vpnAccount->vpnServer()->decrement('current_clients');
        });

        return $vpnAccount->fresh();
    }

    // ------------------------------------------------------------------
    // OpenVPN (v0.6.2) — easyrsa against the shared PKI volume.
    // ------------------------------------------------------------------

    private function issueOpenVpnCredentials(VpnAccount $account): void
    {
        $pkiDir = config('services.vpn.pki_dir');

        if (! File::exists("{$pkiDir}/ca.crt")) {
            throw new VpnProvisioningException(
                'PKI belum di-bootstrap oleh container openvpn — pastikan container openvpn sudah pernah start sebelum provisioning pertama.'
            );
        }

        $build = Process::timeout(30)->run([
            'easyrsa', "--pki-dir={$pkiDir}", '--batch', 'build-client-full', $account->username, 'nopass',
        ]);
        $this->logProcessResult('easyrsa build-client-full', $account->username, $build);
        $this->restorePermissivePkiPermissions($pkiDir);

        if ($build->failed()) {
            throw new VpnProvisioningException(
                "Gagal generate client cert untuk {$account->username}: ".$this->summarizeProcessError($build)
            );
        }

        $serialCheck = Process::timeout(10)->run([
            'openssl', 'x509', '-in', "{$pkiDir}/issued/{$account->username}.crt", '-noout', '-serial',
        ]);

        if ($serialCheck->failed()) {
            throw new VpnProvisioningException(
                "Cert untuk {$account->username} dibuat tapi gagal membaca serial-nya: ".$this->summarizeProcessError($serialCheck)
            );
        }

        // Output looks like "serial=A1B2C3...\n"
        $certSerial = trim(Str::after($serialCheck->output(), 'serial='));

        // client-config-dir file — static internal_ip assignment via
        // ifconfig-push (topology subnet). No per-client route needed
        // here: server.conf already pushes the SAME single FreeRADIUS
        // route to every client globally (v0.6.2 locked hub-and-spoke
        // decision) — a NAS never needs a different destination than any
        // other NAS.
        $ccdDir = config('services.vpn.ccd_dir');

        if (! File::isDirectory($ccdDir)) {
            File::makeDirectory($ccdDir, 0777, true);
        }

        File::put(
            rtrim($ccdDir, '/')."/{$account->username}",
            "ifconfig-push {$account->internal_ip} ".config('services.vpn.netmask', '255.255.255.0').PHP_EOL
        );

        $account->update(['cert_serial' => $certSerial]);
    }

    /**
     * Deliberately does NOT restart or signal the openvpn daemon — OpenVPN
     * re-reads crl-verify's file fresh on every new connection/TLS
     * renegotiation, so a regenerated CRL takes effect for future
     * connection attempts immediately with zero daemon interaction. Known
     * limitation: an ALREADY-connected session isn't forcibly dropped by
     * this (that needs the OpenVPN management interface, not set up this
     * sprint) — it only blocks reconnection.
     */
    private function revokeOpenVpn(VpnAccount $account): void
    {
        $pkiDir = config('services.vpn.pki_dir');

        $revoke = Process::timeout(30)->run([
            'easyrsa', "--pki-dir={$pkiDir}", '--batch', 'revoke', $account->username,
        ]);
        $this->logProcessResult('easyrsa revoke', $account->username, $revoke);

        if ($revoke->failed()) {
            $this->restorePermissivePkiPermissions($pkiDir);

            throw new VpnProvisioningException(
                "Gagal revoke sertifikat {$account->username}: ".$this->summarizeProcessError($revoke)
            );
        }

        $crl = Process::timeout(30)->run([
            'easyrsa', "--pki-dir={$pkiDir}", '--batch', 'gen-crl',
        ]);
        $this->logProcessResult('easyrsa gen-crl', $account->username, $crl);
        $this->restorePermissivePkiPermissions($pkiDir);

        if ($crl->failed()) {
            throw new VpnProvisioningException(
                "Gagal regenerate CRL setelah revoke {$account->username}: ".$this->summarizeProcessError($crl)
            );
        }

        $ccdFile = rtrim(config('services.vpn.ccd_dir'), '/').'/'.$account->username;

        if (File::exists($ccdFile)) {
            File::delete($ccdFile);
        }
    }

    // ------------------------------------------------------------------
    // WireGuard (v0.6.3) — keypair generated here (pure crypto, no live
    // interface needed), but the peer only actually becomes reachable once
    // the wireguard container's reconcile loop picks up the peer file —
    // `wg set`/netlink can't be run from boss-app directly (different
    // network namespace). See docker/wireguard/entrypoint.sh.
    // ------------------------------------------------------------------

    private function issueWireGuardCredentials(VpnAccount $account): void
    {
        $genkey = Process::timeout(10)->run(['wg', 'genkey']);

        if ($genkey->failed()) {
            throw new VpnProvisioningException('Gagal generate WireGuard private key: '.$this->summarizeProcessError($genkey));
        }

        $privateKey = trim($genkey->output());

        $pubkey = Process::input($privateKey.PHP_EOL)->timeout(10)->run(['wg', 'pubkey']);

        if ($pubkey->failed()) {
            throw new VpnProvisioningException('Gagal derive WireGuard public key: '.$this->summarizeProcessError($pubkey));
        }

        $publicKey = trim($pubkey->output());

        $peersDir = config('services.vpn.wg_peers_dir');

        if (! File::isDirectory($peersDir)) {
            File::makeDirectory($peersDir, 0777, true);
        }

        // v0.7.3 — AllowedIPs is WireGuard's own cryptokey-routing filter
        // (enforced before iptables ever runs), not just an access-list
        // convenience: a destination outside this list is silently
        // undeliverable through this peer, no matter what the firewall
        // says. Widening it to the NAS's own tr069_management_subnet (when
        // set — see CLAUDE.md "GenieACS Vendor Parameter Mapping"'s
        // Connection Request investigation for how this was confirmed to
        // be the actual blocker, not a dead tunnel) is what lets
        // GenieACS's Connection Request reach CPE behind this NAS at all.
        // WireGuard automatically installs the matching kernel route for
        // any range added here — no manual `ip route add` needed.
        $allowedIps = "{$account->internal_ip}/32";

        if ($account->nas->tr069_management_subnet !== null) {
            $allowedIps .= ', '.$account->nas->tr069_management_subnet;
        }

        // v0.8.1 — same widening for LibreNMS OLT SNMP polling. Real bug
        // found and fixed the same day this was added: docker/wireguard/
        // entrypoint.sh's OLT block added an explicit `ip route` +
        // iptables rules, but NEVER touched AllowedIPs itself — and
        // AllowedIPs is WireGuard's OWN cryptokey-routing filter, checked
        // BEFORE the kernel even attempts to send (confirmed for real: a
        // direct `ping -I wg0` to an OLT's IP failed outright with "sendto:
        // Required key not available", never even reaching iptables/the
        // explicit route, while the exact same test against the
        // TR069-widened subnet at least transmitted).
        //
        // v0.14.x incident fix — the widening below used to be
        // UNCONDITIONAL for every WireGuard NAS, on the reasoning that no
        // `nas.olt_management_subnet` column exists (same single-global-
        // subnet limitation as OLT_MANAGEMENT_SUBNET/OLT_MANAGEMENT_GATEWAY
        // everywhere else this env var is read). That "accepted
        // limitation" was wrong, not just imprecise: WireGuard only lets
        // ONE peer on an interface claim a given AllowedIPs CIDR at a time
        // — the moment a SECOND NAS's WireGuard account also unconditionally
        // claimed this same subnet, it silently stole the crypto-routing
        // claim away from whichever NAS actually has OLTs behind it. This
        // is exactly what happened for real: ro-hotspot (no OLTs at all)
        // was revoke-and-regenerated on 2026-08-24, its fresh peer fragment
        // re-asserted the claim ahead of test-x86-bajastu's own turn in the
        // reconcile loop's alphabetical peer ordering, and all 3 real OLTs
        // behind test-x86-bajastu silently dropped off LibreNMS monitoring
        // for ~2 days before the conflict was diagnosed. Fixed by only
        // widening for a NAS that actually has at least one OltDevice
        // registered (Nas::oltDevices(), v0.8.1 OLT Credential Registry) —
        // see CLAUDE.md's "OLT AllowedIPs Conflict" section for the full
        // incident writeup.
        $oltManagementSubnet = config('services.vpn.olt_management_subnet');

        // withoutGlobalScopes() — this must resolve correctly regardless of
        // whether the calling context has an authenticated user (Auth::check()
        // gates both BelongsToTenant's TenantScope and BelongsToResellerScope);
        // $account->nas is already the correct, trusted NAS, so scoping the
        // existence check by anything else would be redundant at best and
        // wrong at worst (e.g. a queued/system context with no logged-in user).
        if (! empty($oltManagementSubnet) && $account->nas->oltDevices()->withoutGlobalScopes()->exists()) {
            $allowedIps .= ', '.$oltManagementSubnet;
        }

        File::put(
            rtrim($peersDir, '/')."/{$account->username}.conf",
            '[Peer]'.PHP_EOL."PublicKey = {$publicKey}".PHP_EOL."AllowedIPs = {$allowedIps}".PHP_EOL
        );

        // v0.8.1 — this NAS's own /30 gateway address, written as its own
        // fragment (same "one file per NAS, reconciled by every wireguard
        // node's own loop" pattern as the peer fragment above) so all 3
        // nodes present the SAME gateway identity for THIS NAS regardless
        // of which one the router's auto-switch is currently dialing —
        // see VpnWireguardNasBlock's own docblock and docker/wireguard/
        // entrypoint.sh's reconcile loop for the other half of this.
        $block = VpnWireguardNasBlock::where('nas_id', $account->nas_id)->firstOrFail();

        $addressesDir = config('services.vpn.wg_addresses_dir');

        if (! File::isDirectory($addressesDir)) {
            File::makeDirectory($addressesDir, 0777, true);
        }

        File::put(
            rtrim($addressesDir, '/')."/{$account->username}.conf",
            "{$block->gateway_ip}/30".PHP_EOL
        );

        $account->update(['public_key' => $publicKey]);

        // Transient, NEVER persisted (not an Eloquent attribute/DB column —
        // a genuine declared PHP property on the model, see VpnAccount) —
        // this is the only moment the private key exists outside the
        // admin's own copy of the generated Mikrotik script. Same "shown
        // once" posture as an OpenVPN .ovpn file embedding its private key,
        // just made explicit here because WireGuard has no PKI/CA to defer
        // key custody to.
        $account->wireguardPrivateKey = $privateKey;
    }

    /**
     * Deleting the peer file doesn't take effect until the wireguard
     * container's reconcile loop next runs (up to its poll interval, ~10s)
     * — unlike OpenVPN's CRL (checked live per connection attempt), this is
     * a real, documented latency window, not instant revocation.
     *
     * v0.8.1 — deliberately does NOT touch this NAS's address fragment
     * ($wg_addresses_dir/{$account->username}.conf) or its
     * VpnWireguardNasBlock row. The gateway address is sticky/permanent
     * per NAS (see VpnWireguardNasBlock's own docblock) — a revoke is
     * almost always followed shortly by a fresh provision for the SAME
     * NAS (as this whole sprint's own history shows), and leaving the
     * address configured on all 3 nodes avoids pointless interface churn.
     * Only the PEER (the actual handshake capability) is revoked here.
     */
    private function revokeWireGuard(VpnAccount $account): void
    {
        $peerFile = rtrim(config('services.vpn.wg_peers_dir'), '/')."/{$account->username}.conf";

        if (File::exists($peerFile)) {
            File::delete($peerFile);
        }
    }

    // ------------------------------------------------------------------
    // L2TP/IPsec (v0.6.3) — chap-secrets is REWRITTEN WHOLESALE from the DB
    // on every provision()/revoke() (not appended/removed line-by-line).
    // pppd is spawned fresh by xl2tpd per incoming call, so this takes
    // effect on the very next connection attempt with no reload needed —
    // verified for real during this sprint's end-to-end pass, not assumed.
    // ------------------------------------------------------------------

    private function issueL2tpCredentials(VpnAccount $account): void
    {
        $password = Str::random(24);

        $account->update(['password' => $password]);

        $this->rewriteChapSecrets();
    }

    private function revokeL2tp(VpnAccount $account): void
    {
        // Status flips to Revoked (by the caller, revoke()) BEFORE
        // rewriteChapSecrets() would normally run — call it explicitly here
        // first so this account is excluded from the file it writes.
        $account->status = VpnAccountStatus::Revoked;
        $this->rewriteChapSecrets(excludeAccountId: $account->id);
    }

    private function rewriteChapSecrets(?int $excludeAccountId = null): void
    {
        $accounts = VpnAccount::query()
            ->where('protocol', VpnProtocol::L2tpIpsec)
            ->where('status', VpnAccountStatus::Active)
            ->when($excludeAccountId, fn ($q) => $q->where('id', '!=', $excludeAccountId))
            ->get();

        $lines = $accounts->map(
            fn (VpnAccount $a) => "{$a->username} l2tpd {$a->password} *"
        )->implode(PHP_EOL);

        $secretsDir = config('services.vpn.l2tp_secrets_dir');

        if (! File::isDirectory($secretsDir)) {
            File::makeDirectory($secretsDir, 0777, true);
        }

        File::put(rtrim($secretsDir, '/').'/chap-secrets', $lines.PHP_EOL);
    }

    private function rollbackFailedProvisioning(VpnAccount $account): void
    {
        DB::transaction(function () use ($account) {
            VpnIpPool::query()
                ->where('vpn_account_id', $account->id)
                ->update(['status' => VpnIpPoolStatus::Available, 'vpn_account_id' => null]);

            $account->vpnServer()->decrement('current_clients');
            $account->delete();
        });

        Log::warning("VpnProvisioningService: rolled back failed provisioning for NAS #{$account->nas_id} (username={$account->username}, protocol={$account->protocol->value}).");
    }

    /**
     * Root cause found via manual testing (nas-11 provisioning failure):
     * easyrsa's own file-creation defaults leave pki/index.txt, serial, and
     * index.txt.attr owned by whichever process ran the command with
     * restrictive (usually 600) permissions — the openvpn container's
     * entrypoint only chmod's the PKI tree ONCE, at first boot, before any
     * of these files are ever touched again. Every subsequent easyrsa
     * invocation from here (running as www-data in a real HTTP request,
     * NOT root — manual `tinker` verification during earlier sprints missed
     * this because `docker compose exec` always runs as root, which can
     * write to root-owned 600 files without issue) can silently re-tighten
     * these shared files, blocking the NEXT invocation with a permission
     * error. Called after every easyrsa operation (success or failure) to
     * keep the shared-volume permission invariant this architecture
     * depends on (see CLAUDE.md "VPN Server Node #1 (v0.6.2)") actually
     * true, not just true at container boot.
     */
    private function restorePermissivePkiPermissions(string $pkiDir): void
    {
        $chmod = Process::timeout(10)->run(['chmod', '-R', '0777', $pkiDir]);

        if ($chmod->failed()) {
            Log::warning("VpnProvisioningService: failed to restore permissive PKI permissions on {$pkiDir}: ".$chmod->errorOutput());
        }
    }

    /**
     * Strips OpenSSL's RSA/EC key-generation progress noise (runs of `.`,
     * `+`, `*` characters with no other content — cosmetic, never part of a
     * real error) and surfaces the last few meaningful lines, where the
     * actual failure reason almost always is. Found necessary via manual
     * testing: the raw, unfiltered errorOutput() for a failed
     * build-client-full was an unreadable mix of progress dots with the
     * real error message buried/truncated inside it.
     */
    private function summarizeProcessError(ProcessResult $result): string
    {
        $raw = $result->errorOutput() !== '' ? $result->errorOutput() : $result->output();
        $cleaned = preg_replace('/^[.+*\s]*$/m', '', $raw);
        $cleaned = trim((string) $cleaned);

        if ($cleaned === '') {
            return "(exit code {$result->exitCode()}, tidak ada pesan detail dari proses).";
        }

        $lines = array_values(array_filter(
            explode(PHP_EOL, $cleaned),
            fn (string $line) => trim($line) !== ''
        ));

        $tail = implode(' | ', array_slice($lines, -5));

        return Str::limit("(exit code {$result->exitCode()}) {$tail}", 500);
    }

    /**
     * Always logs the FULL, untouched stdout/stderr/exit code for
     * debugging — only the (cleaned, truncated) summary from
     * summarizeProcessError() ever reaches an exception message shown to
     * an API caller or the Script Generator UI.
     */
    private function logProcessResult(string $command, string $username, ProcessResult $result): void
    {
        if ($result->successful()) {
            return;
        }

        Log::error("VpnProvisioningService: {$command} failed for {$username}", [
            'exit_code' => $result->exitCode(),
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
        ]);
    }
}
