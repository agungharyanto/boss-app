# Desain Registry ONU (v0.23.4) — DRAFT, untuk review Agung sebelum migration/coding

**Status: DOCS-ONLY.** Tidak ada migration/kode yang ditulis untuk registry ini sendiri di sub-versi ini.
Implementasi baru dimulai **setelah** Agung menyetujui dokumen ini secara eksplisit — **termasuk
keputusan-gate di §2 yang WAJIB dijawab dulu**, karena itu menentukan scope nyata yang bisa dikerjakan.

## 1. Prinsip terkunci (keputusan Agung)

- **Sinkron ON-DEMAND SAJA — tidak ada job berkala/scheduled.** Registry BOSS adalah **cache hasil lookup
  terakhir**, bukan salinan lengkap OLT. Baris hanya terisi/terupdate saat ada yang memicu lookup (manual,
  saat aktivasi v0.23.5+, atau saat mengecek status satu ONU/pelanggan).
- **Cache tidak pernah jadi sumber kebenaran untuk operasi tulis** — sama aturan keras yang sudah
  dikunci di desain sidecar v0.23.3 §7 ("cache TIDAK PERNAH jadi sumber kebenaran tunggal untuk keputusan
  TULIS... WAJIB membaca ulang ke device dulu SEBELUM mengeksekusi tulis"). Registry ONU ini adalah
  IMPLEMENTASI NYATA dari struktur yang baru disiapkan di sana — bukan mekanisme baru.
- **TIDAK ADA command tulis ke OLT** di sub-versi ini — batas keras, sama seperti v0.23.3. Sync HANYA
  membaca dari OLT via sidecar dan menulis ke database BOSS sendiri (itu BUKAN "menulis ke OLT").
- Tidak menyentuh GenieACS. Tidak mengubah routing/firewall/WireGuard.

## 2. KEPUTUSAN-GATE — gap operasi TERUJI per vendor (WAJIB dibaca & diputuskan sebelum lanjut)

**Temuan dari membaca ulang ketiga dokumen referensi (`zte-c300-cli-reference.md`,
`hsgq-e04id-cli-reference.md`, `hsgq-g02id-cli-reference.md`) secara menyeluruh, bukan asumsi**: status
"operasi TERUJI" di ketiga dokumen itu punya **dua tingkat yang berbeda** dan sejauh ini tercampur —
(a) **sintaks TERUJI via bantuan `?`** (device mengonfirmasi command/argumen itu ADA dan bentuknya, tapi
belum pernah benar-benar dijalankan dengan hasil data), vs (b) **eksekusi TERUJI dengan hasil nyata**
(command benar-benar dijalankan, output ditangkap dan diverifikasi). Instruksi v0.23.3/v0.23.4 soal
"operasi TERUJI" jelas merujuk ke tingkat (b) — sidecar hanya boleh menjalankan command yang SUDAH
dieksekusi nyata sebelumnya di sesi riset manual, bukan yang baru diketahui sintaksnya lewat `?`.

**Hasil pengecekan per vendor, untuk KHUSUS operasi level-per-ONU (yang dibutuhkan registry)**:

| Vendor | Operasi per-ONU TERUJI (eksekusi nyata) | Operasi kandidat yang BELUM (baru sintaks via `?`) |
|---|---|---|
| **ZTE C300** | `show gpon onu detail-info <onu>` (detail penuh 1 ONU: Name/Type/State/Phase state/Config state/Auth mode/Description/dll — dieksekusi nyata 2× thd ONU_UJI_1/2, Sesi 6-7), `show gpon onu baseinfo <if>` (semua ONU di 1 PON, incl tipe+auth+state ringkas, dieksekusi Sesi 5), `show gpon onu state <if>` (status semua ONU di 1 PON, dieksekusi Sesi 5), `show gpon onu uncfg [if]` (sudah diimplementasi v0.23.3) | — (semua kandidat utama sudah TERUJI eksekusi) |
| **HSGQ E04ID** | **TIDAK ADA** yang genuinely per-ONU | `show onu-info all` / `show onu-info onu-id <N>` / `show onu-info mac <MAC>` (node `interface epon N`) — statusnya "level bantuan TERUJI Sesi 4", **belum pernah dieksekusi dengan hasil data** |
| **HSGQ G02ID** | **TIDAK ADA** yang genuinely per-ONU dengan hasil DATA (`show ont-autofind`/`show black-ont` DIEKSEKUSI nyata Sesi 3, TAPI hasilnya KOSONG di device ini saat ini — nol data ONT untuk dipelajari strukturnya) | `show ont-info <id>` / `all` / `name` / `sn <SN>` (node `interface gpon N`) — statusnya "level bantuan TERUJI Sesi 3", **belum pernah dieksekusi dengan hasil data** |

**Kenapa instruksi "kalau operasi per-ONU belum TERUJI, gunakan operasi level-PON yang sudah TERUJI dan
proses hasilnya" TIDAK BISA dipraktikkan untuk kedua HSGQ**: sudah dicek satu per satu — **tidak ada
satu pun command level-PON HSGQ yang BAIK berstatus TERUJI-eksekusi MAUPUN menghasilkan data ONU
individual sekaligus**:
- E04ID: satu-satunya command TERUJI-eksekusi terkait ONU adalah `onu-authorize` tanpa argumen (node
  `interface epon N`) — hasilnya cuma `AUTH-MODE`/`AUTH-TYPE` **untuk PON itu sendiri** (mis. `mac`/
  `manual`), **tidak ada satu baris pun data ONU individual** (SN/MAC/nama/status per unit).
- G02ID: `show ont-autofind`/`show black-ont` TERUJI-eksekusi, tapi hasilnya **genuinely kosong** (device
  ini tidak sedang punya ONT autofind/blacklist) — tidak ada struktur data ONT nyata untuk dipelajari atau
  disimpan.

**Ini BUKAN kegagalan riset — ini batas jujur dari apa yang sudah divalidasi.** Memaksakan salah satu dari
dua command "belum TERUJI" (`show onu-info all` / `show ont-info sn <SN>`) untuk diimplementasikan
sekarang akan melanggar aturan keras v0.23.3 §5 ("hanya operasi yang statusnya TERUJI... yang aman
diimplementasikan") dan instruksi eksplisit v0.23.4 ini sendiri.

### Opsi yang diusulkan (menunggu pilihan Agung)

**Opsi A (direkomendasikan) — implementasikan penuh untuk ZTE C300 sekarang, HSGQ menyusul.** Skema tabel
dan Service dirancang generik/vendor-agnostic sejak awal (§4-5 di bawah) sehingga siap dipakai ketiga
vendor begitu operasinya TERUJI — tapi kode `syncOnu()` untuk `hsgq_e04id`/`hsgq_g02id` akan **menolak
eksplisit** dengan pesan jelas ("belum didukung — operasi per-ONU belum TERUJI eksekusi, lihat
docs/omci/onu-registry-design.md §2") sampai sesi verifikasi tambahan (lihat Opsi B) dilakukan dan operasi
baru ditambahkan secara terpisah (revisi kecil ke v0.23.4, atau sub-versi lanjutan — Agung yang putuskan).
Verifikasi v0.23.4 ini sendiri (§9) hanya mencakup ZTE.

**Opsi B (sesi riset tambahan, sebelum ATAU sejalan dengan implementasi Opsi A)** — sesi CLI read-only
kecil, izin khusus, POLA SAMA PERSIS seperti `show gpon onu uncfg`/`show ont-autofind`/`show black-ont`
sebelumnya (single execution, hasil dilaporkan terstruktur, SN/MAC di-mask total di laporan): jalankan
`show onu-info all` (E04ID) dan `show ont-info all` + `show ont-info sn <SN salah satu ONU nyata>` (G02ID)
SATU KALI untuk menangkap bentuk output nyata (field apa saja yang muncul, format SN/status). Begitu
statusnya naik jadi TERUJI-eksekusi, HSGQ bisa ditambahkan ke sidecar dan registry dengan cara yang identik
dengan ZTE. **Tidak dilakukan di sub-versi ini tanpa izin eksplisit terpisah** — ini command BARU yang
belum pernah dijalankan sebelumnya, beda kelas dengan operasi v0.23.3 yang tinggal dipanggil ulang.

**Opsi C (tidak direkomendasikan)** — tunda seluruh v0.23.4 (termasuk ZTE) sampai Opsi B selesai untuk
ketiga vendor sekaligus. Tidak diusulkan karena ZTE sudah genuinely siap dan menunggu tanpa alasan teknis.

Dokumen ini SELANJUTNYA ditulis dengan asumsi **Opsi A** (implementasi ZTE penuh, HSGQ terstruktur-tapi-
ditolak) — kalau Agung memilih Opsi B duluan atau kombinasi lain, bagian §6/§9 perlu disesuaikan sebelum
implementasi jalan.

## 3. Perubahan kecil pada kontrak sidecar v0.23.3 — flag `mask_sensitive`

**Masalah yang ditemukan**: desain sidecar v0.23.3 §4 mewajibkan masking SN/MAC/nama **di sidecar**
sebelum respons dikirim ke Laravel — dirancang untuk konteks **verifikasi manual** (supaya siapa pun yang
membaca output, termasuk Claude Code, tidak pernah melihat SN/MAC asli). Tapi konsumen registry ONU adalah
**kode PHP** (`OnuRegistryService`), bukan manusia membaca log — dan tujuan intinya justru **menyimpan
SN/MAC LENGKAP** ke database BOSS App untuk dipakai mencocokkan `work_order_modem_units` dan pencarian ONU
nanti. Kalau masking sidecar tetap dipaksa aktif, registry tidak akan pernah bisa menyimpan SN yang bisa
dipakai matching — fitur ini rusak sejak desain.

**Solusi minimal, backward-compatible**: field opsional baru `mask_sensitive` (boolean) di body
`POST /olt/{id}/read` — **default `true`** kalau tidak dikirim (perilaku v0.23.3 sekarang, TIDAK berubah
untuk pemanggil mana pun yang sudah ada). `OnuRegistryService` adalah **satu-satunya pemanggil** yang
mengirim `mask_sensitive: false` secara eksplisit — server-to-server, tidak pernah lewat endpoint
user-facing/tinker manual mana pun. Sidecar (`main.py`, teruskan ke tiap modul vendor) melewati langkah
`mask_sensitive_tokens()` kalau flag ini `false`.

**Implikasi keamanan, dipikirkan eksplisit**: kredensial OLT tetap tidak pernah tersimpan di sidecar
(prinsip v0.23.3 §8 tidak berubah). SN/MAC yang sekarang boleh mengalir tanpa masking tetap melintas lewat
jalur yang sudah dilindungi HMAC + TLS internal (boss-network) — sama seperti kredensial. Yang berubah
murni: SN/MAC device boleh disimpan **di database BOSS App sendiri** (tempat resminya, sama seperti
`work_order_modem_units.serial_number`/`mac_address` yang SUDAH disimpan plain di tabel itu tanpa
enkripsi) — bukan dibiarkan bocor ke tempat lain.

## 4. Skema tabel `onu_registries`

Migration baru `create_onu_registries_table` (BOSS-009: FK ke `olt_devices` — **tabel yang sama, database
yang sama** `boss_db` — bukan cross-database join; `work_order_modem_units` juga di `boss_db` yang sama,
lookup-nya lewat query Eloquent biasa di Service layer, §6).

```php
Schema::create('onu_registries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('olt_device_id')->constrained('olt_devices')->cascadeOnDelete();

    // Representasi identifier ONU dalam bentuk yang dipakai CLI vendor
    // (ZTE: format asli device, mis. "gpon-onu_1/3/12:2" — TERUJI. HSGQ:
    // konvensi internal BOSS App "<pon-context>/<onu-id>", BUKAN string
    // command tunggal asli device — HSGQ CLI memakai node context
    // terpisah (interface epon/gpon N) + ID polos, tidak punya bentuk
    // gabungan satu-string seperti ZTE. Lihat §5 untuk detail per vendor.
    $table->string('vendor_identifier');

    // Kunci pencarian — SN untuk G02ID & ZTE, MAC untuk E04ID (temuan
    // v0.23.1/v0.23.2). Keduanya nullable karena tidak semua operasi
    // baca mengembalikan keduanya sekaligus, dan HSGQ belum punya data
    // nyata sama sekali (lihat §2). TIDAK di-encrypt — level sensitivitas
    // sama dengan work_order_modem_units.serial_number/mac_address yang
    // sudah plain di tabel itu, dan perlu WHERE exact-match langsung
    // untuk lookup cepat (encrypted cast tidak mendukung itu).
    $table->string('serial_number')->nullable();
    $table->string('mac_address')->nullable();

    // String bebas, BUKAN backed enum kaku PHP dengan case tertutup —
    // lihat App\Enums\OnuRegistryStatus (§5) untuk nilai kanonik yang
    // SERVICE terapkan; kolom sendiri tetap varchar supaya nilai vendor
    // yang belum dipetakan tidak pernah memaksa migration baru, cukup
    // tersimpan sebagai string dan di-treat "unknown" di UI/logic.
    $table->string('status', 32)->default('unknown');

    // Mentah dari OLT (field Name/Description CLI), SEBELUM format
    // "Nama - CID" v0.23.5+ diterapkan — registry ini murni cache
    // baca, tidak pernah menulis format baru ke sini.
    $table->string('name')->nullable();
    $table->string('description')->nullable();

    // Link opsional — lihat findWorkOrderMatch() §6. Nullable, TIDAK
    // WAJIB match (kebanyakan ONU lama tidak akan pernah match WO yang
    // baru mulai dicatat sejak v0.13.4.1).
    $table->foreignId('work_order_modem_unit_id')->nullable()
        ->constrained('work_order_modem_units')->nullOnDelete();

    $table->timestamp('last_synced_at')->nullable();

    // Hasil mentah operasi baca terakhir (field:value lengkap dari
    // sidecar, TANPA re-mask — lihat §3) untuk audit/debug. TIDAK
    // pernah dipakai sebagai sumber kebenaran untuk tulis (§1).
    $table->json('raw_payload')->nullable();

    $table->timestamps();

    $table->unique(['olt_device_id', 'vendor_identifier']);
    $table->index('serial_number');
    $table->index('mac_address');
});
```

**`tenant_id` diisi manual dari `$oltDevice->tenant_id` di Service, BUKAN mengandalkan
`BelongsToTenant`'s auto-fill dari `Auth::user()`** — `syncOnu()` dipanggil dari artisan command (§7,
tidak ada request/Auth context), sama pola yang sudah dipakai `CommissionAttributionService`/
`ReferrerTitipService` untuk alasan yang identik.

## 5. Model & mapping status

`App\Models\OnuRegistry` — `BelongsToTenant` (scoping standar), relasi `oltDevice()`, `workOrderModemUnit()`.

`App\Enums\OnuRegistryStatus` (backed string enum) — **5 case, masing-masing dengan bukti nyata dari
dokumen referensi, TIDAK ADA yang dikarang**:

| Case | Value | Bukti/mapping per vendor |
|---|---|---|
| `Active` | `active` | ZTE: `detail-info`/`baseinfo` dengan Phase state=`working` atau State=`ready` (ONU_UJI_1/2, Sesi 5-7) |
| `Offline` | `offline` | ZTE: Admin State=`enable`, OMCC State=`disable`, Phase State=`OffLine` (ONU `:1` lain, Sesi 5) — ONU terdaftar tapi tidak sedang connect |
| `Unconfigured` | `unconfigured` | ZTE: muncul di `show gpon onu uncfg` (State field device sendiri: `unknown`) |
| `Rejected` | `rejected` | G02ID: muncul di `show black-ont` (device sendiri: "Ont deny list") — **belum ada bukti state setara di ZTE/E04ID**, case ini tetap disiapkan untuk G02ID begitu operasinya TERUJI |
| `Unknown` | `unknown` | Default/fallback — **satu-satunya nilai yang mungkin untuk HSGQ** sampai Opsi B (§2) selesai, dan fallback aman untuk kombinasi state ZTE yang belum pernah teramati |

**Penyesuaian dari draft awal Agung, dilaporkan transparan**: draft instruksi menyebut
`aktif/pending/unconfigured/ditolak/unknown` — `pending` diganti `offline` di atas karena **tidak ada
satu pun bukti state "pending" (terdaftar tapi belum genuinely aktif) di ketiga dokumen referensi**,
sedangkan `offline` (ONU terdaftar dengan Phase State device sendiri = `OffLine`) **punya bukti nyata**
langsung dari data ONU_UJI (`:1`) di Sesi 5 ZTE. Kalau Agung tetap menghendaki nilai `pending` untuk
makna lain (mis. state yang belum pernah teramati tapi diperkirakan ada), tolong dikonfirmasi eksplisit
sebelum implementasi — enum ini mudah diperluas, tapi tidak ingin menambah case tanpa bukti tanpa
sepengetahuan Agung.

**`App\Models\OltDevice::sidecarVendorKey(): string`** (method baru, dipindahkan dari
`OltSidecarClient::VENDOR_MAP`+`resolveVendorKey()` yang sekarang private) — dipakai BAIK oleh
`OltSidecarClient` MAUPUN `OnuRegistryService`, supaya kedua kelas tidak duplikasi logika resolusi
vendor (pola sama seperti `sidecarConnectionPayload()`, satu sumber kebenaran). `OltSidecarClient::
resolveVendorKey()` diganti jadi pemanggil tipis ke method model ini — perubahan REFACTOR kecil,
dilaporkan di sini karena mengubah file v0.23.3 yang sudah di-tag, bukan diam-diam.

## 6. Service — `App\Services\Network\OnuRegistryService`

```php
public function syncOnu(OltDevice $oltDevice, string $vendorIdentifier): OnuRegistry
```

Alur:
1. `$vendorKey = $oltDevice->sidecarVendorKey();` (throw jelas kalau OLT tidak dikenal sidecar — sudah
   ada perilakunya di `OltSidecarClient`, dipakai ulang).
2. Map `$vendorKey` → operation:
   - `zte_c300` → `onu_detail_info` (BARU, lihat §7 — args `{"onu": $vendorIdentifier}`, command
     `show gpon onu detail-info <onu>`, statusnya SUDAH TERUJI eksekusi jadi aman diimplementasikan).
   - `hsgq_e04id` / `hsgq_g02id` → **`throw new \RuntimeException(...)`** dengan pesan jelas merujuk §2 —
     TIDAK mencoba operasi apa pun, TIDAK menebak. Ini BUKAN error tersembunyi — pemanggil (command §7)
     akan menampilkannya apa adanya.
3. `OltSidecarClient::read($oltDevice, $operation, $args, requestedBy: null, maskSensitive: false)` —
   parameter baru `maskSensitive` (default `true` untuk pemanggil lain, `OnuRegistryService` SATU-SATUNYA
   yang mengirim `false`, lihat §3).
4. Kalau `success !== true` → simpan **TIDAK ADA perubahan** ke baris registry (kalau sudah ada, biarkan
   apa adanya — kegagalan baca tidak boleh menghapus cache lama yang mungkin masih relevan), lempar
   exception dengan `device_message`/`error` dari sidecar apa adanya.
5. Parse `data` (dict field:value, sudah terstruktur dari sidecar — lihat §7 untuk bentuk parser baru)
   jadi: `serial_number` (dari field SN kalau ada di respons `detail-info` — **catatan jujur**: field "Sn"
   eksplisit TIDAK terlihat di ringkasan tabel `detail-info` yang sudah didokumentasikan Sesi 6 — perlu
   dikonfirmasi ulang saat implementasi apakah field ini genuinely ada di output mentah atau harus diambil
   dari operasi terpisah seperti `baseinfo`/`uncfg` yang SUDAH terbukti punya kolom `Sn` eksplisit; kalau
   `detail-info` tidak punya SN, `syncOnu()` untuk ZTE perlu memanggil 2 operasi — `baseinfo` (dapat SN) +
   `detail-info` (dapat detail) — **keputusan implementasi, ditandai di sini supaya tidak terlewat**),
   `status` → `OnuRegistryStatus` (mapping §5, `Unknown` kalau kombinasi state tidak dikenal — TIDAK
   PERNAH exception karena status tak dikenal, itu skenario yang WAJAR untuk state vendor yang belum
   terpetakan), `name`/`description` (apa adanya, tanpa format "Nama - CID").
6. Upsert `OnuRegistry` (`updateOrCreate(['olt_device_id' => ..., 'vendor_identifier' => ...], [...])`).
7. `findWorkOrderMatch()` dipanggil kalau `serial_number`/`mac_address` terisi — hasilnya (nullable) diisi
   ke `work_order_modem_unit_id`.
8. Return baris `OnuRegistry` yang tersimpan.

```php
public function findWorkOrderMatch(?string $serialNumber, ?string $macAddress): ?WorkOrderModemUnit
```

- `WorkOrderModemUnit::query()` — **tidak ada global scope tenant di model ini** (dikonfirmasi dari
  membaca `App\Models\WorkOrderModemUnit` langsung — scoped implisit lewat `work_order_id`, bukan lewat
  `BelongsToTenant`), jadi query Eloquent biasa, tanpa `withoutGlobalScopes()` (tidak ada yang perlu
  di-bypass).
- `where('serial_number', $serialNumber)->orWhere('mac_address', $macAddress)` (skip klausa yang null) —
  **`work_order_modem_units` SENGAJA tidak punya unique constraint di kolom ini** (migration-nya sendiri
  mendokumentasikan ini — "salah catat/dicatat ulang di WO lain bukan pelanggaran skema"), jadi bisa ada
  LEBIH DARI SATU match. `->latest()->first()` — ambil yang PALING BARU dicatat sebagai kandidat PALING
  mungkin relevan, **bukan jaminan match yang benar** — dicatat eksplisit sebagai keterbatasan, bukan
  diam-diam dianggap pasti.

## 7. Perubahan/tambahan di sidecar (v0.23.3 revisi kecil + operasi baru)

**HANYA untuk ZTE C300** (sesuai Opsi A, §2):

- `olt-sidecar/app/vendors/zte_c300.py`'s `OPERATIONS` dict bertambah satu entri:
  `'onu_detail_info': 'show gpon onu detail-info {onu}'` (args wajib `onu`, format vendor_identifier ZTE
  apa adanya, mis. `gpon-onu_1/3/12:2`).
- **Parser baru** — bukan `lines_from_capture()` generik (list of raw lines) yang dipakai `show_version`/
  `onu_uncfg_list` sekarang, tapi parser `field:value` → dict, KARENA konsumen operasi ini adalah kode
  (`OnuRegistryService`), bukan manusia membaca laporan. Bentuk umum output `detail-info` (dari Sesi 6-7,
  field per baris `Label            : Value`) — parser split per `:` pertama, trim, kumpulkan jadi
  `{"Name": "...", "Type": "...", ...}`. Baris yang tidak match pola ini (header/separator) diabaikan.
- `main.py` membaca `mask_sensitive` dari body (default `True`), teruskan sebagai parameter ke
  `module.execute(connection, operation, args, mask_sensitive)` — setiap modul vendor melewati langkah
  `mask_sensitive_tokens()` HANYA kalau `mask_sensitive` True (default, perilaku v0.23.3 tidak berubah).
- `App\Services\Network\OltSidecarClient::read()` dapat parameter baru `bool $maskSensitive = true`,
  diteruskan sebagai field `mask_sensitive` di body JSON.

**HSGQ E04ID/G02ID**: **tidak ada perubahan sidecar sama sekali** di sub-versi ini (§2, Opsi A) — modul
Python `hsgq_e04id.py`/`hsgq_g02id.py` tetap hanya punya `show_version`.

## 8. Trigger sync — artisan command

`php artisan onu:sync {olt_device_id} {vendor_identifier}` (`App\Console\Commands\SyncOnuRegistry`) —
satu command sederhana, panggil `OnuRegistryService::syncOnu()`, tampilkan hasil (id registry, status,
apakah match WO ditemukan) ke stdout. **Tidak ada UI/route HTTP** di sub-versi ini (sesuai instruksi —
"belum perlu UI/route HTTP kecuali Agung minta").

## 9. Rencana verifikasi (SETELAH persetujuan Agung, sesuai Opsi A §2)

- **ZTE C300**: `php artisan onu:sync 1 "gpon-onu_1/3/12:2"` (ONU_UJI_1 Agung) — tunjukkan baris
  `onu_registries` yang GENUINELY tersimpan: **struktur/kolom saja** (id, olt_device_id, vendor_identifier,
  status, apakah serial_number/mac_address/name/description terisi — TANPA mengutip nilainya), sesuai
  kebijakan masking laporan yang sudah konsisten dipakai sejak v0.23.1.
- **HSGQ E04ID/G02ID**: **TIDAK dijalankan** — `syncOnu()` akan menolak eksplisit (§6 langkah 2), sesuai
  Opsi A. Kalau Agung memilih Opsi B duluan, langkah ini disusulkan setelah sesi riset tambahan itu.
- Pindai sensitivitas dokumen/kode sebelum commit (kebijakan yang sudah berjalan sejak v0.23.1).

## 10. Test scoped

- `OnuRegistryServiceTest` — mock `OltSidecarClient` (fake binding, sama pola `RouterOsGateway` fake di
  seluruh test Network module): `syncOnu()` untuk ZTE upsert baris dengan field yang benar, status
  ter-mapping sesuai kombinasi state (termasuk fallback `Unknown` untuk kombinasi tak dikenal),
  `syncOnu()` untuk HSGQ melempar exception jelas TANPA memanggil sidecar sama sekali, `findWorkOrderMatch()`
  mengembalikan baris `WorkOrderModemUnit` paling baru saat SN/MAC cocok lebih dari satu, `null` kalau
  tidak ada yang cocok, kegagalan baca dari sidecar TIDAK menghapus baris registry lama yang sudah ada.
- `OnuRegistryTest` (model/migration) — unique constraint `(olt_device_id, vendor_identifier)` ditegakkan,
  `tenant_id` terisi dari `$oltDevice->tenant_id` bukan dari `Auth`.
- **BUKAN full regression suite** — v0.23.4 bukan penutup cluster v0.23.0.

## 11. Batasan keras (sama seperti v0.23.3)

TIDAK ADA command tulis ke OLT mana pun. TIDAK mengubah routing/firewall/WireGuard NAS. TIDAK menyentuh
GenieACS. Sync HANYA membaca dari OLT via sidecar dan menulis ke database BOSS sendiri.

**STOP setelah dokumen ini — tunggu review & persetujuan Agung, TERMASUK keputusan eksplisit di §2
(Opsi A/B/C), sebelum migration/coding dimulai.**
