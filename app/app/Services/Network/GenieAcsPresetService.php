<?php

namespace App\Services\Network;

use App\Models\RemoteWanConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mengelola provision + preset GenieACS Auto-WAN lewat REST genieacs-nbi
 * (`/presets/<id>`, `/provisions/<id>` — dikonfirmasi 2026-09-07: endpoint
 * ini GENUINELY berfungsi di genieacs-nbi 1.2.16, meski komentar lama di
 * `docker/genieacs/presets/apply.sh` bilang tidak ada). Tidak perlu
 * `mongosh` / docker exec / driver Mongo di boss-app.
 *
 * DESAIN — preset TERPISAH `boss-auto-wan`, BUKAN di-fold ke `default`:
 * preset `default` berlaku fleet-wide (~414 device) dan sudah punya masalah
 * kronis `too_many_commits`/`too_many_rpcs` di ~68 device pohon-besar —
 * menambah provision ke situ berisiko memperburuk. Preset `boss-auto-wan`
 * SELALU di-SCOPE lewat `precondition` ke SN eksplisit:
 *   - `enabled = false` → preset DIHAPUS total (GenieACS tidak menjalankan
 *     `default-wan` sama sekali);
 *   - `enabled = true` + allowlist KOSONG (union wan1+wan2) → preset TIDAK
 *     DIBUAT / DIHAPUS. Safe default = "provision NOBODY" — TIDAK PERNAH
 *     fleet-wide. Precondition `"true"` fleet-wide pernah menyebabkan
 *     insiden nyata (2026-09-07: 4 ONT pelanggan kena WAN rogue karena
 *     `buildPrecondition([])` lama mengembalikan `"true"`).
 *   - `enabled = true` + ada SN di allowlist →
 *     `precondition` = `DeviceID.SerialNumber = "SN1" OR ...` (HANYA SN itu).
 *
 * Provision `default-wan` dibaca dari `app/resources/genieacs/default-wan.js`.
 * Kontrak args posisional — lihat `RemoteWanConfig::toProvisionArgs()`.
 *
 * CACHE GenieACS: genieacs-cwmp me-refresh snapshot preset dari mongo tiap
 * ~5,5 menit (`db.cache` `cwmp-local-cache-hash`, `expire - timestamp` =
 * 330s). Perubahan `precondition`/`args` preset yang SUDAH ada berlaku
 * dalam ~5,5 menit TANPA restart. Provision `default-wan` / preset
 * `boss-auto-wan` yang BENAR-BENAR baru (deploy pertama) mungkin butuh
 * `docker compose restart genieacs-cwmp` sekali — catatan empiris apply.sh.
 */
class GenieAcsPresetService
{
    private const PROVISION_NAME = 'default-wan';

    private const PRESET_NAME = 'boss-auto-wan';

    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.genieacs.nbi_url'), '/');
    }

    public function syncAutoWanConfig(RemoteWanConfig $config): void
    {
        $this->putProvisionScript(self::PROVISION_NAME, $this->autoWanScript());

        $serials = $config->allSerialAllowlist();

        // enabled=false ATAU allowlist kosong → preset dihapus/tak dibuat.
        // Allowlist kosong TIDAK PERNAH berarti fleet-wide — safe default
        // adalah "provision nobody" (lihat docblock kelas + insiden 2026-09-07).
        if (! $config->enabled || $serials === []) {
            $this->deletePreset(self::PRESET_NAME);

            return;
        }

        $this->putPreset(self::PRESET_NAME, [
            'weight' => 0,
            'precondition' => $this->buildPrecondition($serials),
            'channel' => self::PRESET_NAME,
            'configurations' => [[
                'type' => 'provision',
                'name' => self::PROVISION_NAME,
                'args' => $config->toProvisionArgs(),
            ]],
        ]);
    }

    /**
     * Precondition SELALU scoped ke SN eksplisit. Allowlist kosong tidak
     * boleh sampai ke sini — `syncAutoWanConfig()` sudah menghapus preset
     * lebih dulu. Guard di sini defense-in-depth: gagal keras, JANGAN
     * pernah jatuh ke `"true"` fleet-wide.
     *
     * @param  list<string>  $serials
     */
    private function buildPrecondition(array $serials): string
    {
        if ($serials === []) {
            throw new RuntimeException(
                'buildPrecondition() dipanggil dengan allowlist kosong — ini bug: '
                .'preset boss-auto-wan tidak boleh dibuat tanpa SN allowlist eksplisit.'
            );
        }

        return collect($serials)
            ->map(fn (string $sn) => 'DeviceID.SerialNumber = "'.addslashes($sn).'"')
            ->implode(' OR ');
    }

    public function autoWanScript(): string
    {
        $path = resource_path('genieacs/default-wan.js');
        $script = @file_get_contents($path);

        if ($script === false || trim($script) === '') {
            throw new RuntimeException("Script provision default-wan tidak terbaca di {$path}.");
        }

        return $script;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function putPreset(string $name, array $body): void
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->send('PUT', '/presets/'.rawurlencode($name), ['body' => json_encode($body)]);

        if ($response->failed()) {
            throw new RuntimeException(
                "GenieACS menolak update preset '{$name}' (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300)
            );
        }
    }

    private function deletePreset(string $name): void
    {
        $response = Http::baseUrl($this->baseUrl)->delete('/presets/'.rawurlencode($name));

        // 404 = sudah tidak ada, itu hasil yang diinginkan.
        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException(
                "GenieACS menolak hapus preset '{$name}' (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300)
            );
        }
    }

    private function putProvisionScript(string $name, string $script): void
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders(['Content-Type' => 'text/javascript'])
            ->send('PUT', '/provisions/'.rawurlencode($name), ['body' => $script]);

        if ($response->failed()) {
            throw new RuntimeException(
                "GenieACS menolak update provision '{$name}' (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300)
            );
        }
    }

    /**
     * State efektif di GenieACS live — dipakai halaman "Konfig Remote".
     *
     * @return array{provision_exists: bool, preset_exists: bool, precondition: ?string, preset_args: ?array}
     */
    public function inspectAutoWanState(): array
    {
        $provisions = Http::baseUrl($this->baseUrl)->acceptJson()
            ->get('/provisions/?projection=_id')->json() ?? [];
        $provisionExists = collect($provisions)->contains(fn ($p) => ($p['_id'] ?? null) === self::PROVISION_NAME);

        $presetRows = Http::baseUrl($this->baseUrl)->acceptJson()
            ->get('/presets/?query='.rawurlencode(json_encode(['_id' => self::PRESET_NAME])))
            ->json() ?? [];
        $preset = $presetRows[0] ?? null;

        $entry = $preset === null ? null : collect($preset['configurations'] ?? [])
            ->first(fn ($c) => ($c['type'] ?? null) === 'provision' && ($c['name'] ?? null) === self::PROVISION_NAME);

        return [
            'provision_exists' => $provisionExists,
            'preset_exists' => $preset !== null,
            'precondition' => $preset['precondition'] ?? null,
            'preset_args' => $entry['args'] ?? null,
        ];
    }
}
