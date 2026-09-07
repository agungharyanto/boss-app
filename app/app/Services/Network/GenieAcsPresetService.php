<?php

namespace App\Services\Network;

use App\Models\RemoteWanConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mengelola preset + provision GenieACS lewat REST API genieacs-nbi
 * (`/presets/<id>`, `/provisions/<id>` — dikonfirmasi 2026-09-07: endpoint
 * ini GENUINELY berfungsi di genieacs-nbi 1.2.16, meski komentar lama di
 * `docker/genieacs/presets/apply.sh` bilang tidak ada — komentar itu keliru
 * / usang). Tidak perlu `mongosh` / docker exec / driver Mongo di boss-app.
 *
 * SCOPE SEKARANG: hanya provision `default-wan` (Auto-WAN configurable) —
 * satu-satunya provision yang nilainya datang dari BOSS App (`args` preset).
 * Provision statik lain (`default`/`default-optical`/`default-pppoe`) tetap
 * dikelola runbook manual `apply.sh` (read-only refresh, jarang berubah).
 *
 * Kontrak args `default-wan` (posisional) — lihat
 * `RemoteWanConfig::toProvisionArgs()` + `resources/genieacs/default-wan.js`.
 *
 * CACHE GenieACS: genieacs-cwmp me-refresh snapshot preset/provision dari
 * mongo tiap ~5,5 menit (dikonfirmasi lewat `db.cache` `cwmp-local-cache-hash`
 * `expire - timestamp` = 330s). Jadi perubahan args (preset yang SUDAH ada)
 * berlaku otomatis dalam ~5,5 menit TANPA restart. Provision `default-wan`
 * yang BENAR-BENAR BARU (deploy pertama) mungkin butuh
 * `docker compose restart genieacs-cwmp` sekali — lihat catatan apply.sh.
 */
class GenieAcsPresetService
{
    private const PROVISION_NAME = 'default-wan';

    private const PRESET_NAME = 'default';

    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.genieacs.nbi_url'), '/');
    }

    /**
     * Terapkan baris singleton `RemoteWanConfig` ke GenieACS:
     *  - selalu PUT script provision `default-wan` (idempoten — genieacs
     *    upsert; kalau isi script berubah di git, deploy berikutnya
     *    menyinkronkan);
     *  - kalau `enabled` → pastikan preset `default` memasukkan
     *    `default-wan` dengan `args` terbaru dari config;
     *  - kalau `enabled = false` → keluarkan `default-wan` dari preset
     *    `default` (GenieACS berhenti menjalankannya sama sekali).
     *
     * Melempar RuntimeException (pesan user-facing) kalau genieacs-nbi
     * menolak / tak terjangkau — caller (Job) menangkap & menandai
     * `markSyncFailed()`.
     */
    public function syncAutoWanConfig(RemoteWanConfig $config): void
    {
        $this->putProvisionScript(self::PROVISION_NAME, $this->autoWanScript());

        $preset = $this->getPreset(self::PRESET_NAME);
        $configurations = $this->rebuildConfigurations(
            $preset['configurations'] ?? [],
            includeAutoWan: (bool) $config->enabled,
            autoWanArgs: $config->toProvisionArgs(),
        );

        $this->putPreset(self::PRESET_NAME, $preset, $configurations);
    }

    /**
     * Isi script provision `default-wan` — dibaca dari file kanonik di repo
     * (`app/resources/genieacs/default-wan.js`, ter-bind-mount ke boss-app).
     */
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
     * @return array<string, mixed>
     */
    private function getPreset(string $name): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->get('/presets/?query='.rawurlencode(json_encode(['_id' => $name])));

        if ($response->failed()) {
            throw new RuntimeException("Gagal membaca preset '{$name}' dari GenieACS (HTTP {$response->status()}).");
        }

        return $response->json()[0] ?? ['_id' => $name, 'channel' => 'default', 'configurations' => []];
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, bool|int|string>  $autoWanArgs
     * @return array<int, array<string, mixed>>
     */
    private function rebuildConfigurations(array $existing, bool $includeAutoWan, array $autoWanArgs): array
    {
        // Buang entri default-wan lama (kalau ada) — apa pun bentuknya.
        $configurations = array_values(array_filter(
            $existing,
            fn ($c) => ! (($c['type'] ?? null) === 'provision' && ($c['name'] ?? null) === self::PROVISION_NAME),
        ));

        if ($includeAutoWan) {
            $configurations[] = [
                'type' => 'provision',
                'name' => self::PROVISION_NAME,
                'args' => $autoWanArgs,
            ];
        }

        return $configurations;
    }

    /**
     * @param  array<string, mixed>  $currentPreset
     * @param  array<int, array<string, mixed>>  $configurations
     */
    private function putPreset(string $name, array $currentPreset, array $configurations): void
    {
        // Pertahankan bentuk preset yang sudah ada; hanya `configurations`
        // yang berubah. `weight`/`precondition` diberi default aman kalau
        // preset lama (dibuat mongosh) tak punya — 0 / "true" = perilaku
        // identik dengan default GenieACS.
        $body = [
            'weight' => $currentPreset['weight'] ?? 0,
            'precondition' => $currentPreset['precondition'] ?? 'true',
            'channel' => $currentPreset['channel'] ?? 'default',
            'configurations' => $configurations,
        ];

        if (isset($currentPreset['events'])) {
            $body['events'] = $currentPreset['events'];
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->send('PUT', '/presets/'.rawurlencode($name), ['body' => json_encode($body)]);

        if ($response->failed()) {
            throw new RuntimeException(
                "GenieACS menolak update preset '{$name}' (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300)
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
     * Baca kembali state efektif dari GenieACS — dipakai halaman "Konfig
     * Remote" untuk menampilkan apakah yang tersimpan benar-benar sudah
     * terpasang di preset live.
     *
     * @return array{provision_exists: bool, in_preset: bool, preset_args: ?array}
     */
    public function inspectAutoWanState(): array
    {
        $provisions = Http::baseUrl($this->baseUrl)->acceptJson()
            ->get('/provisions/?projection=_id')->json() ?? [];
        $provisionExists = collect($provisions)->contains(fn ($p) => ($p['_id'] ?? null) === self::PROVISION_NAME);

        $preset = $this->getPreset(self::PRESET_NAME);
        $entry = collect($preset['configurations'] ?? [])
            ->first(fn ($c) => ($c['type'] ?? null) === 'provision' && ($c['name'] ?? null) === self::PROVISION_NAME);

        return [
            'provision_exists' => $provisionExists,
            'in_preset' => $entry !== null,
            'preset_args' => $entry['args'] ?? null,
        ];
    }
}
