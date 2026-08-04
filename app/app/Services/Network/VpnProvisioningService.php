<?php

namespace App\Services\Network;

use App\Enums\VpnAccountStatus;
use App\Enums\VpnIpPoolStatus;
use App\Enums\VpnServerStatus;
use App\Exceptions\VpnIpPoolExhaustedException;
use App\Exceptions\VpnProvisioningException;
use App\Models\Nas;
use App\Models\VpnAccount;
use App\Models\VpnIpPool;
use App\Models\VpnServer;
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
     * same lockForUpdate() pattern as OdpPort in WorkOrderService), (2)
     * commits that allocation, THEN (3) runs `easyrsa build-client-full`
     * against the shared PKI volume and writes the client-config-dir file.
     * Steps 2 and 3 are deliberately NOT in the same DB transaction as step
     * 1 — easyrsa is a slow external process, and holding a row lock for
     * its duration would serialize unrelated concurrent provisioning
     * attempts for no reason. If (3) fails, the DB allocation from (1) is
     * explicitly rolled back (IP released, row deleted) rather than left
     * behind as a phantom "active" account with no real certificate.
     */
    public function provision(Nas $nas, string $protocol = 'openvpn'): VpnAccount
    {
        $existing = VpnAccount::query()
            ->where('nas_id', $nas->id)
            ->where('protocol', $protocol)
            ->where('status', VpnAccountStatus::Active)
            ->first();

        if ($existing !== null) {
            throw new VpnProvisioningException(
                "NAS '{$nas->name}' sudah punya akun VPN {$protocol} aktif (username: {$existing->username})."
            );
        }

        $username = 'nas-'.$nas->id;

        $account = DB::transaction(function () use ($nas, $protocol, $username) {
            $server = VpnServer::query()
                ->where('is_active', true)
                ->where('status', '!=', VpnServerStatus::Offline)
                ->orderBy('id')
                ->first();

            if ($server === null) {
                throw new VpnProvisioningException('Tidak ada VPN server aktif untuk provisioning.');
            }

            $poolEntry = VpnIpPool::query()
                ->where('vpn_server_id', $server->id)
                ->where('status', VpnIpPoolStatus::Available)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($poolEntry === null) {
                throw new VpnIpPoolExhaustedException(
                    "Pool IP VPN server '{$server->hostname}' sudah habis."
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
            $certSerial = $this->issueClientCertificate($username);
            $this->writeClientConfigFile($username, $account->internal_ip);
            $account->update(['cert_serial' => $certSerial]);
        } catch (RuntimeException $e) {
            $this->rollbackFailedProvisioning($account);

            throw $e;
        }

        return $account->fresh();
    }

    /**
     * Revokes the client certificate (easyrsa revoke + gen-crl) and frees
     * its internal_ip back to the pool. Deliberately does NOT restart or
     * signal the openvpn daemon — OpenVPN re-reads crl-verify's file fresh
     * on every new connection/TLS renegotiation, so a regenerated CRL takes
     * effect for future connection attempts immediately with zero daemon
     * interaction. Known limitation: an ALREADY-connected session isn't
     * forcibly dropped by this (that needs the OpenVPN management
     * interface, not set up this sprint) — it only blocks reconnection.
     */
    public function revoke(VpnAccount $vpnAccount): VpnAccount
    {
        if ($vpnAccount->status === VpnAccountStatus::Revoked) {
            return $vpnAccount;
        }

        $pkiDir = config('services.vpn.pki_dir');

        $revoke = Process::timeout(30)->run([
            'easyrsa', "--pki-dir={$pkiDir}", '--batch', 'revoke', $vpnAccount->username,
        ]);

        if ($revoke->failed()) {
            throw new VpnProvisioningException("Gagal revoke sertifikat {$vpnAccount->username}: ".$revoke->errorOutput());
        }

        $crl = Process::timeout(30)->run([
            'easyrsa', "--pki-dir={$pkiDir}", '--batch', 'gen-crl',
        ]);

        if ($crl->failed()) {
            throw new VpnProvisioningException("Gagal regenerate CRL setelah revoke {$vpnAccount->username}: ".$crl->errorOutput());
        }

        DB::transaction(function () use ($vpnAccount) {
            $vpnAccount->update(['status' => VpnAccountStatus::Revoked, 'revoked_at' => now()]);

            VpnIpPool::query()
                ->where('vpn_account_id', $vpnAccount->id)
                ->update(['status' => VpnIpPoolStatus::Available, 'vpn_account_id' => null]);

            $vpnAccount->vpnServer()->decrement('current_clients');
        });

        $ccdFile = rtrim(config('services.vpn.ccd_dir'), '/').'/'.$vpnAccount->username;

        if (File::exists($ccdFile)) {
            File::delete($ccdFile);
        }

        return $vpnAccount->fresh();
    }

    /**
     * @return string the issued cert's serial (from `openssl x509 -serial`)
     */
    private function issueClientCertificate(string $username): string
    {
        $pkiDir = config('services.vpn.pki_dir');

        if (! File::exists("{$pkiDir}/ca.crt")) {
            throw new VpnProvisioningException(
                'PKI belum di-bootstrap oleh container openvpn — pastikan container openvpn sudah pernah start sebelum provisioning pertama.'
            );
        }

        $build = Process::timeout(30)->run([
            'easyrsa', "--pki-dir={$pkiDir}", '--batch', 'build-client-full', $username, 'nopass',
        ]);

        if ($build->failed()) {
            throw new VpnProvisioningException("Gagal generate client cert untuk {$username}: ".$build->errorOutput());
        }

        $serialCheck = Process::timeout(10)->run([
            'openssl', 'x509', '-in', "{$pkiDir}/issued/{$username}.crt", '-noout', '-serial',
        ]);

        if ($serialCheck->failed()) {
            throw new VpnProvisioningException("Cert untuk {$username} dibuat tapi gagal membaca serial-nya: ".$serialCheck->errorOutput());
        }

        // Output looks like "serial=A1B2C3...\n"
        return trim(Str::after($serialCheck->output(), 'serial='));
    }

    /**
     * client-config-dir file — static internal_ip assignment via
     * ifconfig-push (topology subnet, matches server.conf.template). No
     * per-client route needed here: server.conf already pushes the SAME
     * single FreeRADIUS route to every client globally (v0.6.2 locked
     * hub-and-spoke decision) — a NAS never needs a different destination
     * than any other NAS.
     */
    private function writeClientConfigFile(string $username, string $internalIp): void
    {
        $ccdDir = config('services.vpn.ccd_dir');

        if (! File::isDirectory($ccdDir)) {
            File::makeDirectory($ccdDir, 0777, true);
        }

        File::put(
            rtrim($ccdDir, '/')."/{$username}",
            "ifconfig-push {$internalIp} ".config('services.vpn.netmask', '255.255.255.0').PHP_EOL
        );
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

        Log::warning("VpnProvisioningService: rolled back failed provisioning for NAS #{$account->nas_id} (username={$account->username}).");
    }
}
