<?php

namespace App\Services\Network;

use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\LegacyMacCustomerMap;
use Illuminate\Support\Carbon;

/**
 * Backs the admin-only "Cek Status Device" self-service page — walks the
 * exact same 4-stage pipeline LegacyDeviceMatcherService itself depends on
 * (GenieACS presence -> legacy_mac_customer_map hex-tail match ->
 * BOSS App customer -> cpe_devices binding), but read-only and built to
 * SHOW the reasoning at every step, not just the final yes/no — including
 * the closest legacy_mac_customer_map candidate even when it's OUTSIDE
 * LegacyDeviceMatcherService's own MAX_HEX_DISTANCE tolerance. That
 * "closest candidate, rejected" view is deliberately the whole point: it's
 * exactly what a real investigation (F663NV9 / ZTEGCB399CEB, 2026-08-12)
 * had to be done by hand in tinker to find — the device's own reported
 * _OUI (347839) matched the customer's legacy MAC prefix (34:78:39:...)
 * exactly, but LegacyDeviceMatcherService only ever compares MAC/serial
 * TAIL hex digits, so this vendor's OUI-prefix correlation is invisible to
 * it. This page surfaces that discrepancy directly instead of requiring
 * another manual tinker session next time it happens for a different
 * device.
 */
class CpeDeviceDiagnosticService
{
    private const MAX_HEX_DISTANCE = 2;

    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
    ) {}

    /**
     * @return array{
     *     genieacs: array{checked: bool, passed: ?bool, detail: string, suggestion: ?string, device_id: ?string},
     *     mac_map: array{checked: bool, passed: ?bool, detail: string, suggestion: ?string, matched_row: ?LegacyMacCustomerMap},
     *     customer: array{checked: bool, passed: ?bool, detail: string, suggestion: ?string, customer: ?Customer},
     *     binding: array{checked: bool, passed: ?bool, detail: string, suggestion: ?string},
     * }
     */
    public function diagnose(?string $serialNumber, ?string $phoneNumber): array
    {
        $serialNumber = $this->blankToNull($serialNumber);
        $phoneNumber = $this->blankToNull($phoneNumber !== null ? $this->normalizePhone($phoneNumber) : null);

        $genieAcsStep = $this->checkGenieAcs($serialNumber);

        // Resolved BEFORE mac_map on purpose — when a phone number is
        // given directly, step 2 needs to check THAT customer's own
        // legacy_mac_customer_map row specifically (comparing its OUI
        // against the device's own reported OUI), not just report
        // whichever row happens to be globally closest by tail distance.
        // This is exactly the check that had to be done by hand in tinker
        // to diagnose ZTEGCB399CEB/Sartimin (2026-08-12) — see this class's
        // own docblock.
        $knownCustomer = $phoneNumber !== null ? $this->findCustomer($phoneNumber) : null;

        $macMapStep = $this->checkMacMap($serialNumber, $knownCustomer, $genieAcsStep['oui']);
        $customerStep = $this->checkCustomer($phoneNumber, $knownCustomer, $macMapStep);
        $bindingStep = $this->checkBinding($serialNumber, $genieAcsStep['device_id'], $customerStep['customer']);

        return [
            'genieacs' => $genieAcsStep,
            'mac_map' => $macMapStep,
            'customer' => $customerStep,
            'binding' => $bindingStep,
        ];
    }

    /**
     * @return array{checked: bool, passed: ?bool, detail: string, suggestion: ?string, device_id: ?string, oui: ?string}
     */
    private function checkGenieAcs(?string $serialNumber): array
    {
        if ($serialNumber === null) {
            return [
                'checked' => false, 'passed' => null,
                'detail' => 'Serial number tidak diisi — langkah ini butuh serial number.',
                'suggestion' => null, 'device_id' => null, 'oui' => null,
            ];
        }

        $matches = $this->genieAcsClient->queryDevices(['_deviceId._SerialNumber' => $serialNumber]);

        if ($matches === []) {
            // Some devices' _id doesn't cleanly separate OUI/ProductClass/
            // Serial the way _deviceId._SerialNumber does — a plain
            // substring match on _id is the same fallback used when this
            // exact device was diagnosed by hand.
            $matches = $this->genieAcsClient->queryDevices(['_id' => ['$regex' => preg_quote($serialNumber, '/')]]);
        }

        if ($matches === []) {
            return [
                'checked' => true, 'passed' => false,
                'detail' => "Tidak ditemukan sama sekali di GenieACS (db.devices) untuk serial \"{$serialNumber}\".",
                'suggestion' => 'Device belum pernah berhasil inform ke GenieACS. Cek ACS URL/kredensial TR-069 di ONT, '
                    .'pastikan device terhubung ke jaringan management, tunggu beberapa menit setelah restart, atau '
                    .'coba reboot device secara fisik.',
                'device_id' => null, 'oui' => null,
            ];
        }

        $device = $matches[0];
        $deviceId = $device['_id'] ?? null;
        $lastInform = $device['_lastInform'] ?? null;
        $lastInformText = $lastInform !== null ? Carbon::parse($lastInform)->diffForHumans() : 'tidak diketahui';
        $oui = $device['_deviceId']['_OUI'] ?? null;
        $productClass = $device['_deviceId']['_ProductClass'] ?? '—';

        return [
            'checked' => true, 'passed' => true,
            'detail' => 'Ditemukan di GenieACS — device_id='.$deviceId.', OUI='.($oui ?? '—').", ProductClass={$productClass}, "
                ."inform terakhir {$lastInformText}.",
            'suggestion' => null,
            'device_id' => $deviceId,
            'oui' => $oui,
        ];
    }

    private function findCustomer(string $phoneNumber): ?Customer
    {
        return Customer::where('phone_number', $phoneNumber)
            ->orWhere('legacy_username', $phoneNumber)
            ->first();
    }

    /**
     * @return array{checked: bool, passed: ?bool, detail: string, suggestion: ?string, matched_row: ?LegacyMacCustomerMap}
     */
    private function checkMacMap(?string $serialNumber, ?Customer $knownCustomer, ?string $deviceOui): array
    {
        if ($serialNumber === null) {
            return [
                'checked' => false, 'passed' => null,
                'detail' => 'Serial number tidak diisi — langkah ini butuh serial number untuk dihitung hex tail-nya.',
                'suggestion' => null, 'matched_row' => null,
            ];
        }

        $tail = $this->hexTail($serialNumber);

        if ($tail === null) {
            return [
                'checked' => true, 'passed' => false,
                'detail' => "Serial number \"{$serialNumber}\" tidak punya 6 karakter hex di bagian akhirnya — "
                    .'tidak bisa dihitung untuk pencocokan.',
                'suggestion' => 'Cek ulang format serial number-nya benar atau tidak.',
                'matched_row' => null,
            ];
        }

        // A customer is already known (phone number was given directly) —
        // check THEIR OWN legacy_mac_customer_map row specifically, not
        // just whichever row happens to be globally closest. This is the
        // check that actually solved the ZTEGCB399CEB/Sartimin case: the
        // customer's own row wasn't the closest by tail distance, but its
        // MAC's OUI prefix matched the device's own reported OUI exactly.
        if ($knownCustomer !== null && $knownCustomer->legacy_username !== null) {
            $ownRow = LegacyMacCustomerMap::where('legacy_username', $knownCustomer->legacy_username)->first();

            if ($ownRow !== null) {
                return $this->describeMacMapCandidate($tail, $ownRow, $deviceOui, ownedByKnownCustomer: true);
            }

            // Known customer has no row of their own — fall through to the
            // global closest-by-distance search below as a "maybe" hint,
            // but the detail text below makes clear it's NOT this
            // customer's own data.
        }

        $best = null;
        $bestDistance = null;

        foreach (LegacyMacCustomerMap::all() as $row) {
            $macTail = $this->macTail($row->mac_address);

            if ($macTail === null) {
                continue;
            }

            $distance = $this->hexDistance($tail, $macTail);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $row;
            }
        }

        if ($best === null) {
            return [
                'checked' => true, 'passed' => false,
                'detail' => "Serial tail={$tail}. Tabel legacy_mac_customer_map kosong sama sekali — tidak ada yang bisa dibandingkan.",
                'suggestion' => 'Import mac_reference.csv dulu kalau ada datanya.',
                'matched_row' => null,
            ];
        }

        return $this->describeMacMapCandidate($tail, $best, $deviceOui, ownedByKnownCustomer: false);
    }

    /**
     * @return array{checked: bool, passed: ?bool, detail: string, suggestion: ?string, matched_row: ?LegacyMacCustomerMap}
     */
    private function describeMacMapCandidate(string $serialTail, LegacyMacCustomerMap $row, ?string $deviceOui, bool $ownedByKnownCustomer): array
    {
        $macTail = $this->macTail($row->mac_address);
        $macOui = strtoupper(substr(str_replace(':', '', $row->mac_address), 0, 6));
        $distance = $macTail !== null ? $this->hexDistance($serialTail, $macTail) : 6;
        $ouiMatches = $deviceOui !== null && strcasecmp($macOui, $deviceOui) === 0;
        $owner = $ownedByKnownCustomer
            ? "MAC milik customer yang diketahui ({$row->legacy_username})"
            : "Kandidat TERDEKAT secara global di legacy_mac_customer_map (legacy_username={$row->legacy_username})";

        if ($distance <= self::MAX_HEX_DISTANCE) {
            return [
                'checked' => true, 'passed' => true,
                'detail' => "Serial tail={$serialTail} cocok dengan MAC {$row->mac_address} ({$owner}), "
                    ."jarak hex={$distance} — dalam toleransi auto-matcher (maks ".self::MAX_HEX_DISTANCE.').',
                'suggestion' => null,
                'matched_row' => $row,
            ];
        }

        $suggestion = $ouiMatches
            ? "OUI MAC ini ({$macOui}) SAMA PERSIS dengan OUI device di GenieACS (langkah 1) — ini kemungkinan besar memang device "
                .'yang sama, cuma auto-matcher (yang HANYA membandingkan 6 karakter terakhir serial vs MAC) tidak menangkapnya. '
                .'Bind manual lewat tombol "Ganti Modem" di /cpe-devices, jangan tunggu auto-matcher untuk pasangan ini.'
            : 'Auto-matcher HANYA membandingkan 6 karakter TERAKHIR dari serial vs MAC (jarak hex='.$distance.', di luar toleransi maks '
                .self::MAX_HEX_DISTANCE.'), dan OUI MAC-nya ('.$macOui.') juga TIDAK sama dengan OUI device di GenieACS. '
                .'Kemungkinan besar bukan device yang sama — cek manual sebelum bind kalau ragu.';

        return [
            'checked' => true, 'passed' => false,
            'detail' => "Serial tail={$serialTail}. {$owner}: MAC {$row->mac_address} (tail=".($macTail ?? '—').", OUI={$macOui}), "
                ."tapi jarak hex={$distance} — DI LUAR toleransi auto-matcher (maks ".self::MAX_HEX_DISTANCE.').',
            'suggestion' => $suggestion,
            'matched_row' => null,
        ];
    }

    /**
     * @return array{checked: bool, passed: ?bool, detail: string, suggestion: ?string, customer: ?Customer}
     */
    private function checkCustomer(?string $phoneNumber, ?Customer $knownCustomer, array $macMapStep): array
    {
        // Phone number was given directly — $knownCustomer (resolved once,
        // up front in diagnose()) is authoritative, no need to re-query.
        if ($phoneNumber !== null) {
            if ($knownCustomer === null) {
                return [
                    'checked' => true, 'passed' => false,
                    'detail' => "Tidak ada customer dengan nomor HP/legacy_username \"{$phoneNumber}\" di database BOSS App.",
                    'suggestion' => 'Cek ejaan nomor HP, atau pelanggan ini memang belum pernah di-import ke BOSS App.',
                    'customer' => null,
                ];
            }

            return [
                'checked' => true, 'passed' => true,
                'detail' => "Customer ditemukan: {$knownCustomer->name} (id={$knownCustomer->id}, phone={$knownCustomer->phone_number}).",
                'suggestion' => null,
                'customer' => $knownCustomer,
            ];
        }

        // No phone given — try to auto-derive one from step 2's matched
        // (in-tolerance) row only. A rejected/out-of-tolerance candidate
        // deliberately does NOT auto-derive here, even with a strong OUI
        // hint — that's a manual-bind decision (see describeMacMapCandidate's
        // suggestion text), not something this step should silently assume.
        $derivedPhone = ($macMapStep['matched_row'] ?? null)?->legacy_username;

        if ($derivedPhone === null) {
            return [
                'checked' => false, 'passed' => null,
                'detail' => 'Nomor HP tidak diisi, dan tidak ada kandidat match (dalam toleransi) di legacy_mac_customer_map untuk diturunkan otomatis.',
                'suggestion' => null, 'customer' => null,
            ];
        }

        $customer = $this->findCustomer($derivedPhone);
        $source = " (diturunkan otomatis dari kandidat match di langkah 2, legacy_username={$derivedPhone})";

        if ($customer === null) {
            return [
                'checked' => true, 'passed' => false,
                'detail' => "Tidak ada customer dengan nomor HP/legacy_username \"{$derivedPhone}\" di database BOSS App{$source}.",
                'suggestion' => 'Kandidat di legacy_mac_customer_map ada, tapi belum pernah di-import sebagai customer. '
                    .'Cek apakah baris ini ada di all_customers_import.csv dan jalankan customers:import-legacy lagi kalau perlu.',
                'customer' => null,
            ];
        }

        return [
            'checked' => true, 'passed' => true,
            'detail' => "Customer ditemukan{$source}: {$customer->name} (id={$customer->id}, phone={$customer->phone_number}).",
            'suggestion' => null,
            'customer' => $customer,
        ];
    }

    /**
     * @return array{checked: bool, passed: ?bool, detail: string, suggestion: ?string}
     */
    private function checkBinding(?string $serialNumber, ?string $genieAcsDeviceId, ?Customer $customer): array
    {
        if ($serialNumber === null && $genieAcsDeviceId === null && $customer === null) {
            return [
                'checked' => false, 'passed' => null,
                'detail' => 'Tidak ada serial number, device_id GenieACS, atau customer yang bisa dipakai untuk cek binding.',
                'suggestion' => null,
            ];
        }

        $device = CpeDevice::query()
            ->when($serialNumber !== null, fn ($q) => $q->orWhere('serial_number', $serialNumber))
            ->when($genieAcsDeviceId !== null, fn ($q) => $q->orWhere('genieacs_device_id', $genieAcsDeviceId))
            ->when($customer !== null, fn ($q) => $q->orWhere('customer_id', $customer->id))
            ->with('customer:id,name')
            ->first();

        if ($device === null) {
            return [
                'checked' => true, 'passed' => false,
                'detail' => 'Belum ada baris cpe_devices untuk device/customer ini.',
                'suggestion' => 'Kalau langkah 1-3 di atas semuanya ✅, tunggu siklus berikutnya dari '
                    .'cpe:auto-match-legacy-devices (lihat CPE_AUTO_MATCH_INTERVAL_SECONDS di .env untuk cadence-nya '
                    .'saat ini) — atau bind manual lewat tombol "Ganti Modem" di /cpe-devices kalau auto-matcher '
                    .'memang tidak akan pernah menemukan match-nya (lihat catatan di langkah 2).',
            ];
        }

        $customerName = $device->customer?->name ?? '—';

        return [
            'checked' => true, 'passed' => true,
            'detail' => "Sudah ter-bind: cpe_devices id={$device->id}, pelanggan={$customerName}, status={$device->status->label()}.",
            'suggestion' => null,
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Same normalization as ImportLegacyCustomers::normalizePhone() — kept
     * as its own copy rather than a shared trait, same "one-off, not
     * application-wide" reasoning that file's own docblock already gives.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return $phone;
        }

        if (str_starts_with($digits, '62')) {
            return '0'.substr($digits, 2);
        }

        if (str_starts_with($digits, '8')) {
            return '0'.$digits;
        }

        return $digits;
    }

    /**
     * Same algorithm as LegacyDeviceMatcherService::hexTail() — deliberately
     * duplicated, not extracted into a shared helper, so this diagnostic
     * page can never silently drift out of sync with whichever version the
     * real matcher uses if one of them is ever tuned independently; a
     * shared helper would make that drift invisible instead of a visible,
     * intentional copy-paste to keep in sync by hand.
     */
    private function hexTail(string $serialNumber): ?string
    {
        $hexPart = preg_replace('/^[A-Za-z]+/', '', $serialNumber);

        if (strlen($hexPart) < 6 || preg_match('/^[0-9A-Fa-f]{6}$/', substr($hexPart, -6)) !== 1) {
            return null;
        }

        return strtoupper(substr($hexPart, -6));
    }

    private function macTail(string $macAddress): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $macAddress));

        if (strlen($hex) < 6) {
            return null;
        }

        return substr($hex, -6);
    }

    private function hexDistance(string $a, string $b): int
    {
        $distance = 0;

        for ($i = 0; $i < 6; $i++) {
            if ($a[$i] !== $b[$i]) {
                $distance++;
            }
        }

        return $distance;
    }
}
