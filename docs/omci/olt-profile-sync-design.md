# Sinkronisasi VLAN & Bandwidth Profile ke OLT (v0.23.5.1, DRAFT DESAIN)

> **Status: DRAFT untuk review Agung. INVESTIGASI + DESAIN saja. TIDAK ADA kode/migration/eksekusi tulis
> ke OLT di sesi ini.** Fitur PRODUKSI (bukan test-only) — desain harus lebih matang (BOSS-006/009,
> siapa boleh mencentang). Semua `[ASUMSI]`/`[OPEN]`/`[RISIKO]` WAJIB diselesaikan sebelum implementasi.

## Ringkasan eksekutif (baca ini dulu)

- **Bagian A (VLAN → OLT): TERBLOKIR pada satu temuan kritis yang belum bisa diverifikasi.** Apakah
  "izinkan VLAN ke OLT" perlu MENULIS ke uplink trunk (operasi OLT-wide BERISIKO TINGGI) atau CUKUP
  permission per-ONU (aman) **bergantung pada apakah uplink sudah blanket-trunk semua VLAN** — dan ini
  **BELUM BISA SAYA VERIFIKASI** sesi ini (lihat A.2). Desain data model Bagian A menunggu temuan ini.
- **Bagian B (Bandwidth → OLT): mapping arah terkonfirmasi dari config nyata; formula CBS/PBS TIDAK
  tuntas** (tidak ada satu formula yang cocok untuk semua pasangan — butuh klarifikasi Anda).
- **Rekomendasi urutan**: kalau A.2 ternyata "uplink sudah blanket-trunk" → Bagian A aman & kecil,
  kerjakan duluan. Kalau perlu tulis uplink → Bagian A jadi kelas risiko tersendiri, tunda; kerjakan
  Bagian B dulu (per-ONU, tidak OLT-wide) SETELAH formula CBS/PBS diklarifikasi.

---

## BAGIAN A — Grup Profil (NetworkProfileGroup) → OLT

### A.1 UI (usulan)
Checkbox/multi-select "Izinkan ke OLT" di halaman Grup Profil, daftar OLT dari `olt_devices`. **Kondisi
data saat ini**: `olt_devices` TIDAK kosong — ada #1 `zte_c300`, #2 `hsgq_e04id`, #4 `hsgq_g02id` (semua
`nas_id=1`). Kalau suatu saat kosong → tampilkan "Isi dulu di menu OLT" + link, jangan biarkan mencentang
OLT tak terdaftar.

### A.2 INVESTIGASI uplink trunk — **[TIDAK BISA DIVERIFIKASI SESI INI — BLOKER DESAIN]**

Tujuan: tahu apakah semua VLAN pelanggan sudah di-blanket-trunk ke uplink (→ "izinkan ke OLT" cukup
permission per-ONU, AMAN) atau belum (→ perlu tulis ke uplink, OLT-wide, **BERISIKO TINGGI**).

**Yang dicoba (read-only, reuse read-op `onu_running_config` = `show running-config interface {onu}` dengan
nilai interface `ge_`)**: `ge_1/1/1`, `ge_1/2/1`, `ge_1/19/1`, `ge_1/5/1`, `xge_1/1/1` — **SEMUA ditolak
`%Error 20202: Invalid input detected`**. Nama/bentuk interface uplink tidak tertebak.

**Cross-check dump lama v0.23.1**: `docs/omci/zte-c300-cli-reference.md` **tidak memuat** info uplink/ge/
trunk sama sekali (hanya level ONU `pon-onu-mng`). Jadi asumsi "blanket-trunk" dari dump lama **tidak bisa
dikonfirmasi** — dump itu tidak pernah menangkap config uplink.

**Konsekuensi**: belum bisa saya pastikan apakah "izinkan ke OLT" perlu tulis uplink atau tidak. Butuh
salah satu SEBELUM desain data model difinalkan:
- **(a)** Nama port uplink persis dari Anda (Anda tahu hardware-nya), lalu saya baca read-only
  `show running-config interface <port>`; ATAU
- **(b)** Izin menjalankan read lebih luas (`show vlan` / `show card` / `show running-config` penuh) —
  butuh read-op baru (coding, ditunda) atau sesi `show` sekali-pakai read-only.

**[RISIKO]** Kalau ternyata perlu MENULIS ke uplink: itu operasi **OLT-WIDE** (mempengaruhi SEMUA
pelanggan di OLT itu), **beda kelas** dari semua yang sudah kita kerjakan (semua selama ini per-ONU). Wajib
**decision-gate terpisah** + kehati-hatian ekstra. JANGAN diimplementasikan tanpa itu.

### A.3 Skema data (usulan — tunggu A.2)
Pivot `network_profile_group_olt` (`network_profile_group_id`, `olt_device_id`, `sync_status`
enum[pending/synced/failed], `last_sync_error`, `synced_at`), pola mirip sync-status yang sudah dipakai
di `customer_ip_pools`/`network_profile_groups` (`mikrotik_sync_*`), TAPI untuk fitur PRODUKSI:
- **BOSS-006**: logika sync di Service (bukan controller/komponen), lewat sidecar (BOSS-009: tidak ada
  cross-DB; OLT diakses via sidecar HMAC, bukan query langsung).
- **Siapa boleh mencentang**: `[OPEN-A1]` — permission baru (mis. `network_profile_groups.sync_olt`)
  tier-admin saja? Dikonfirmasi di review.
- **Isi tulisan per-ONU (kalau A.2 = cukup per-ONU)**: tambah baris `flow 1 pri 0 vlan <V>` +
  `vlan-filter`/`service-port` sesuai pola existing, saat ONU diprovision — BUKAN tulis uplink.

---

## BAGIAN B — Bandwidth Profile → OLT (tcont + traffic)

### B.1 UI (usulan)
Checkbox/multi-select "Assign ke OLT" di halaman Bandwidth Profile, daftar OLT dari `olt_devices`.

### B.2 Pemetaan arah — **TERKONFIRMASI dari config nyata ONU_1** (bukan asumsi)
Config nyata: `tcont 1 profile HomeFixed-10Mbps` + `gemport 1 traffic-limit downstream PPPoE-Remote`.
- **T-CONT profile = UPLOAD/upstream** (T-CONT = alokasi bandwidth upstream GPON). ✓
- **traffic profile = DOWNLOAD/downstream** — eksplisit kata `downstream` di `traffic-limit downstream
  <profile>`. ✓

Sesuai mapping yang Anda nyatakan. **Terkonfirmasi, bukan [ASUMSI].**

### B.3 Field Bandwidth Profile BOSS — **TERKONFIRMASI ada**
Kolom `bandwidth_profiles`: `upload_min`, `upload_max`, `download_min`, `download_max` (satuan **Kbps** —
dikonfirmasi dari nilai nyata, mis. `15000` = 15 Mbps). Contoh nyata: `10Mbps`(1000/15000/1000/15000),
`30Mbps`(10000/45000/10000/45000), `50Mbps`(10000/75000/10000/75000), `100Mbps`(10000/125000/10000/125000).

**Pemetaan ke profil OLT (usulan, per instruksi Anda)**:
- `upload_min` → T-CONT `fixed` + `assured`; `upload_max` → T-CONT `maximum`
- `download_min` → traffic `sir`; `download_max` → traffic `pir`
- `cbs`/`pbs` → dari formula B.4 **(BELUM TUNTAS)**
- Nama profil tcont+traffic = **sama persis** nama Bandwidth Profile BOSS (pola existing `HomeFixed-10Mbps`).

**[ASUMSI-B1]** Satuan `sir/pir` di OLT = Kbps (sama dengan field BOSS)? Contoh SmartOLT Anda `sir/pir`
seperti `15360`, `46080`, `122880` TIDAK cocok persis dengan Kbps paket BOSS (15000/45000/...) — mendekati
tapi beda (15360 vs 15000). **[OPEN-B1]** 15360 = 15×1024 (Kbps berbasis 1024?) sedangkan field BOSS
15000 = 15×1000. Perlu klarifikasi: apakah OLT pakai basis 1024 dan BOSS basis 1000? Ini mempengaruhi
konversi. **JANGAN diasumsikan — konfirmasi.**

### B.4 Formula CBS/PBS — **TIDAK ADA SATU FORMULA YANG COCOK UNTUK SEMUA** (perhitungan nyata di bawah)

Pasangan yang Anda berikan (rate → burst), rasio dihitung untuk SETIAP pasangan:

| rate (sir/pir) | burst (cbs/pbs) | rate/burst | catatan |
|---|---|---|---|
| 15360 | 192 | **80.00** | ganjil — rasio 80, beda dari kelompok ~400 |
| 46080 | 115 | 400.70 | ~400 |
| 122880 | 307 | 400.26 | ~400 |
| 35840 | 89 | 402.70 | ~400 |
| 61440 | 153 | 401.57 | ~400 |
| 9953280 | 1023 | **9729.50** | **ANOMALI** (HomeFixed-100Mbps) |

**Temuan jujur**:
- 4 dari 6 pasangan konsisten rasio **≈400** (dengan pembulatan) → `burst ≈ rate / 400` kandidat untuk
  "mayoritas" profil.
- `15360 → 192` rasio **80** — TIDAK cocok ~400. **[OPEN-B2]** kemungkinan ini pasangan jenis berbeda
  (mis. sir→cbs vs pir→pbs pakai pembagi beda), ATAU `15360→192` salah-pasang di daftar. Data mentah yang
  Anda beri tidak menyebutkan mana `sir` mana `pir`, mana `cbs` mana `pbs`, dan milik profil yang mana —
  jadi tidak bisa saya simpulkan.
- `9953280 → 1023` rasio **9729** — anomali ekstrem (persis yang Anda minta diperiksa khusus). `1023` =
  2¹⁰−1 (nilai maksimum 10-bit) → kemungkinan besar ini **nilai di-clamp ke batas maksimum field**, bukan
  hasil formula. `9953280` juga ≈ 9.49×1024² → kemungkinan **satuan berbeda** (bps/1024-based) untuk
  profil 100Mbps. **Perlu klarifikasi Anda** apakah ini format satuan berbeda / anomali existing.

**Kesimpulan B.4**: saya **TIDAK menerapkan formula apa pun buta**. Yang dibutuhkan dari Anda sebelum
desain konversi difinalkan: (1) pemetaan eksplisit tiap angka ke (profil, sir/pir, cbs/pbs); (2)
konfirmasi apakah `/400` berlaku untuk sir→cbs DAN pir→pbs atau beda; (3) penjelasan `15360→192` (rasio 80)
dan `9953280→1023` (clamp/satuan beda).

### B.5 Scope vendor — ZTE C300 ONLY
tcont/traffic profile = konsep ZTE. Untuk OLT HSGQ (#2 `hsgq_e04id`, #4 `hsgq_g02id`): tombol "Assign ke
OLT" **tetap tampil** tapi dengan pesan jelas **"Profil bandwidth OLT belum didukung untuk vendor ini"**
kalau OLT terpilih HSGQ. TIDAK menggeneralisasi tanpa investigasi CLI HSGQ terpisah (konsisten keputusan
ZTE-only lama; HSGQ-G02ID punya `tcont dba-profile-id` tapi arsitektur template-global beda total, HSGQ-
E04ID tidak ada tcont sama sekali — lihat docblock `TestCredentialSyncService`).

### B.6 Skema data (usulan)
Pivot `bandwidth_profile_olt` (`bandwidth_profile_id`, `olt_device_id`, `sync_status`, `last_sync_error`,
`synced_at`). Saat di-assign ke OLT ZTE: Service membuat/update profil tcont + traffic bernama sama via
sidecar write-op BARU `[DITUNDA sampai formula B.4 tuntas]`. **BOSS-009**: via sidecar, bukan query DB OLT.

---

## BAGIAN C — Rekomendasi urutan implementasi

1. **Klarifikasi Anda dulu** (bloker): A.2 (uplink trunk — nama port atau izin read luas), B.4 (pemetaan
   & formula CBS/PBS), B.1-satuan (`[OPEN-B1]` 1000 vs 1024).
2. **Kalau A.2 = uplink sudah blanket-trunk semua VLAN**: Bagian A = permission per-ONU saja, AMAN & kecil
   → kerjakan duluan (pivot + Service + tulis `flow` per-ONU, pola existing).
3. **Kalau A.2 = perlu tulis uplink**: Bagian A naik ke kelas **OLT-wide berisiko tinggi** → tunda, butuh
   decision-gate terpisah. Kerjakan **Bagian B dulu** (per-ONU, setelah B.4 tuntas).
4. Bagian B implementasi: hanya setelah formula CBS/PBS & satuan dikonfirmasi — baru buat sidecar write-op
   profil tcont/traffic (ZTE-only) + UI + test mock.

**TIDAK ada kode/migration/eksekusi di sesi ini.** Menunggu review Anda atas kedua dokumen
(`onu-test-ui-design.md` + ini) sebelum implementasi apa pun.
