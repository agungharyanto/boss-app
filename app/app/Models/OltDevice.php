<?php

namespace App\Models;

use App\Enums\OltAccessProtocol;
use App\Enums\OltConnectionTestResult;
use App\Models\Concerns\BelongsToResellerScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OltDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class OltDevice extends Model
{
    /** @use HasFactory<OltDeviceFactory> */
    use BelongsToResellerScope, BelongsToTenant, HasFactory;

    /**
     * Mapping (manufacturer name, model name) -> vendor key yang dikenal
     * sidecar OLT (v0.23.3). Dipindahkan dari App\Services\Network\
     * OltSidecarClient (semula private const di sana) ke sini v0.23.4,
     * supaya App\Services\Network\OnuRegistryService bisa memakai
     * resolusi vendor yang SAMA tanpa duplikasi — satu sumber kebenaran,
     * pola sama seperti sidecarConnectionPayload() di bawah. Dijaga
     * eksplisit (bukan derivasi otomatis dari string) agar sebuah OLT
     * yang belum genuinely didukung sidecar GAGAL JELAS, bukan diam-diam
     * mengirim vendor key yang salah.
     */
    private const VENDOR_MAP = [
        'HSGQ|HSGQ-E04ID' => 'hsgq_e04id',
        'HSGQ|HSGQ-G02ID' => 'hsgq_g02id',
        'ZTE|C300' => 'zte_c300',
    ];

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'name',
        'nas_id',
        'ip_address',
        'olt_model_id',
        'access_protocol',
        'telnet_port',
        'telnet_username',
        'telnet_password',
        'ssh_port',
        'ssh_username',
        'ssh_password',
        'snmp_version',
        'snmp_port',
        'snmp_ro_community',
        'snmp_rw_community',
        'pon_port_count',
        'last_connection_test_at',
        'last_connection_test_result',
        'last_connection_test_message',
        'notes',
    ];

    protected $hidden = [
        'telnet_password',
        'ssh_password',
        'snmp_ro_community',
        'snmp_rw_community',
    ];

    protected function casts(): array
    {
        return [
            'access_protocol' => OltAccessProtocol::class,
            'telnet_password' => 'encrypted',
            'ssh_password' => 'encrypted',
            'snmp_ro_community' => 'encrypted',
            'snmp_rw_community' => 'encrypted',
            'last_connection_test_at' => 'datetime',
            'last_connection_test_result' => OltConnectionTestResult::class,
        ];
    }

    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    public function oltModel(): BelongsTo
    {
        return $this->belongsTo(OltModel::class, 'olt_model_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /**
     * SATU-SATUNYA cara resmi mengambil kredensial CLI admin device ini
     * dalam bentuk siap-kirim ke sidecar OLT (v0.23.3) — dipakai oleh
     * App\Services\Network\OltSidecarClient DAN test-nya, supaya TIDAK
     * ADA kode lain (produksi maupun sementara/debug) yang membaca
     * `ssh_password`/`telnet_password` langsung dari model. Insiden nyata
     * (2026-09-22, lihat docs/omci/backlog-security.md poin 5): password
     * SSH sempat tercetak di sesi debugging justru karena field diakses
     * langsung di luar jalur ini.
     *
     * @return array{protocol: string, host: string, port: int, username: string, password: string}
     */
    public function sidecarConnectionPayload(): array
    {
        return match ($this->access_protocol) {
            OltAccessProtocol::Ssh => [
                'protocol' => 'ssh',
                'host' => $this->ip_address,
                'port' => $this->ssh_port ?? 22,
                'username' => (string) $this->ssh_username,
                'password' => (string) $this->ssh_password,
            ],
            OltAccessProtocol::Telnet => [
                'protocol' => 'telnet',
                'host' => $this->ip_address,
                'port' => $this->telnet_port ?? 23,
                'username' => (string) $this->telnet_username,
                'password' => (string) $this->telnet_password,
            ],
        };
    }

    /**
     * Vendor key yang dikenal sidecar OLT (v0.23.3), diturunkan dari
     * nama manufacturer+model OLT ini. Satu-satunya titik resmi resolusi
     * vendor — dipakai App\Services\Network\OltSidecarClient DAN
     * App\Services\Network\OnuRegistryService, supaya keduanya tidak
     * duplikasi logika ini (v0.23.4).
     *
     * @throws RuntimeException Kalau OLT ini belum dikenal/didukung sidecar.
     */
    public function sidecarVendorKey(): string
    {
        $manufacturer = strtoupper(trim($this->oltModel?->manufacturer?->name ?? ''));
        $model = strtoupper(trim($this->oltModel?->name ?? ''));
        $key = "{$manufacturer}|{$model}";

        return self::VENDOR_MAP[$key]
            ?? throw new RuntimeException("OltDevice #{$this->id} ({$manufacturer} {$model}) belum didukung sidecar OLT.");
    }
}
