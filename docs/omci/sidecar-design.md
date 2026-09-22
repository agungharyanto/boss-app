# Desain Sidecar OLT (v0.23.3) — DRAFT, untuk review Agung sebelum implementasi

**Status: DOCS-ONLY.** Tidak ada kode yang ditulis untuk sidecar ini sendiri di sub-versi ini. Dokumen
ini murni desain — implementasi (skeleton container, endpoint baca, test) baru dimulai **setelah** Agung
menyetujui dokumen ini secara eksplisit.

## 1. Ringkasan & batasan keras

Sidecar OLT adalah service **baru**, terpisah dari `boss-app`, yang menjadi satu-satunya titik yang
benar-benar "berbicara" ke CLI native OLT (HSGQ E04ID/G02ID via SSH, ZTE C300 via Telnet) untuk keperluan
otomasi produksi ke depan (v0.23.5+). v0.23.3 sendiri **HANYA membangun infrastruktur + jalur baca**:

- **TIDAK ADA command tulis ke OLT mana pun** di sub-versi ini — batas keras, bukan saran, berlaku
  terlepas dari kredensial yang dipakai (root G02ID, `smartolt` ZTE, `boss` E04ID) punya hak tulis atau
  tidak.
- **TIDAK mengubah routing/firewall NAS** di luar penambahan jalur baru sidecar sendiri (lihat §3).
- **TIDAK menyentuh GenieACS** sama sekali.
- **TIDAK memindahkan kredensial** — `olt_devices` tetap memakai kredensial yang sudah ada (root/
  smartolt/boss); migrasi ke akun BOSS permanen (non-root/non-smartolt) adalah kerja v0.23.4+ SETELAH
  sidecar terbukti jalan baca-saja lewat sub-versi ini.

## 2. Bahasa & pola container

**Python** (bukan Go/Node — beda dari whatsapp-gateway yang sudah Go). Container **baru**, pola yang
sama dengan `librenms-dispatcher`: service Docker Compose terpisah, tanpa port host yang dipublikasikan
(BOSS-010), hanya reachable dari `boss-app` lewat `boss-network`.

**Kenapa Python, bukan reuse pola Go whatsapp-gateway**: CLI OLT (E04ID/G02ID/ZTE) semuanya berbasis
`expect`-style automation (kirim command, tunggu prompt/pager, tangani konfirmasi) — persis pola yang
sudah terbukti bekerja sepanjang v0.23.1/v0.23.2 lewat `expect` (Tcl). Python punya `pexpect` (library
matang, setara `expect` tapi scriptable) — port paling langsung dari logika yang SUDAH TERUJI di kedua
sub-versi sebelumnya (`olt_session.exp`/`olt_telnet_session.exp`), dibanding menulis ulang state-machine
serial/pager-handling dari nol di Go. Ini bukan preferensi bahasa semata — mengurangi risiko menulis ulang
logika yang 3 kali sudah menemukan bug nyata (false-positive deteksi password, dll — lihat
`docs/omci/zte-c300-cli-reference.md` "Insiden Sesi 1-3") dari nol di bahasa lain.

## 3. Jalur jaringan ke `10.168.100.0/24` — DUA OPSI, rekomendasi Opsi A

**Konteks yang WAJIB dipahami dulu (dari pembacaan langsung `docker/wireguard/entrypoint.sh` +
`App\Console\Commands\VpnSyncRouteFragments` + `App\Services\Network\VpnProvisioningService`, bukan
asumsi)**: `10.168.100.0/24` (subnet manajemen OLT) adalah LAN LOKAL milik NAS (`test-x86-bajastu`)
sendiri — **satu-satunya jalur** boss-network bisa menjangkaunya adalah LEWAT tunnel WireGuard yang
SUDAH ADA antara NAS (sebagai WireGuard CLIENT) dan salah satu dari 3 container `wireguard`/
`wireguard-node2`/`wireguard-node3` (sebagai SERVER). Tidak ada "tunnel WireGuard terpisah langsung ke
10.168.100.0/24" yang mungkin dibuat independen dari tunnel NAS yang sudah ada — subnet itu hanya bisa
dicapai lewat NAS itu sendiri meneruskan traffic dari tunnel ke LAN lokalnya (mekanisme
`AllowedIPs`/`OLT_MANAGEMENT_SUBNET` reverse-route yang sudah dibangun sejak v0.8.1).

### Opsi A (DIREKOMENDASIKAN) — sidecar jadi consumer ke-6 di pola fragment+reconcile yang sudah ada

Persis pola `librenms`/`librenms-dispatcher`/`freeradius`/`genieacs-cwmp`/`genieacs-nbi` saat ini:
- Sidecar dapat **static IP** di dalam `INFRA_TUNNEL_BLOCK_CIDR` (`172.28.0.224/27`, slot kosong
  berikutnya — lihat CLAUDE.md "Infra Tunnel IP Block", masih ada slot `.230`-`.254` tersisa).
- Container mount `vpn_wg_data:ro` (volume yang sama), entrypoint sidecar sendiri (bukan bind-mount
  script eksternal seperti `librenms`, karena sidecar PUNYA Dockerfile sendiri — bisa langsung `COPY`
  loop reconcile-nya ke image, pola `docker/genieacs/entrypoint.sh`) menjalankan loop yang SAMA persis
  dengan `docker/librenms/route-init.sh`: baca `$ROUTES_DIR/nas-*.conf`, `ip route replace <subnet> via
  <gateway>` tiap ~5 detik.
- **Tidak perlu perubahan APAPUN** di `VpnSyncRouteFragments` (command itu sudah menulis baris
  `{oltSubnet} via {nodeIp}` untuk SETIAP NAS yang punya `OltDevice` terdaftar — sidecar cukup MEMBACA
  fragment yang SAMA, sudah otomatis dapat rute yang benar untuk ketiga NAS yang relevan: E04ID/G02ID di
  `test-x86-bajastu`, ZTE juga di `test-x86-bajastu`).
- **Nol perubahan sisi NAS** — tidak ada keypair baru, tidak ada entry peer baru di `wg0` node manapun,
  tidak ada `AllowedIPs` baru yang perlu diminta ke NAS. `AllowedIPs`/firewall NAS SUDAH mempercayai
  seluruh `INFRA_TUNNEL_BLOCK_CIDR` sebagai satu blok (bukan per-IP) sejak redesign v0.8.1 — sidecar,
  begitu dapat IP di blok itu, otomatis tercakup TANPA sentuhan tambahan.
- **Batas keamanan**: sidecar berbagi TEPAT trust boundary yang sama dengan 5 consumer lain (siapa pun
  di blok itu dipercaya NAS). Ini SAMA PERSIS dengan level isolasi yang sudah diterima untuk LibreNMS/
  GenieACS/FreeRADIUS — bukan pelonggaran baru.

### Opsi B (dipertimbangkan, TIDAK direkomendasikan untuk v0.23.3) — sidecar jadi WireGuard peer baru yang genuinely terpisah

Sidecar generate keypair sendiri, terdaftar sebagai peer TAMBAHAN di salah satu `wireguard-node*`
(terpisah dari peer milik NAS), dengan `AllowedIPs` sendiri.

**Masalah teknis nyata, bukan sekadar lebih rumit**: karena `10.168.100.0/24` tetap hanya bisa dicapai
lewat NAS meneruskan dari tunnelnya sendiri, sebuah peer KEDUA (sidecar) di node yang sama TIDAK bisa
langsung "melompat" ke subnet itu tanpa node tersebut me-relay antar dua peer WireGuard berbeda (NAS ↔
sidecar) — mekanisme relay-antar-peer ini TIDAK ada built-in di WireGuard (WireGuard bukan router
multi-peer transparan by design tanpa konfigurasi routing eksplisit di kedua sisi peer), dan
membangunnya butuh persis rule FORWARD+MASQUERADE yang SUDAH ADA untuk `INFRA_TUNNEL_BLOCK_CIDR` — yang
berarti Opsi B pada akhirnya **tetap butuh** mekanisme yang sama dengan Opsi A sebagai fondasi, HANYA
menambah satu lapisan tunnel lagi (sidecar→node, ekstra selain node→NAS yang sudah ada) tanpa manfaat
isolasi tambahan yang nyata — trust boundary yang benar-benar menentukan (apa yang NAS percaya lewat
`AllowedIPs`-nya sendiri) tetap sama: blok infra bersama, bukan identitas sidecar per-keypair.

**Kapan Opsi B baru masuk akal**: kalau suatu saat sidecar perlu dijalankan di LOKASI FISIK terpisah
(bukan di server yang sama dengan `boss-app`/node WireGuard) — baru di situ identitas tunnel independen
benar-benar diperlukan. Untuk v0.23.3 (server sama, pola `librenms-dispatcher`, sesuai keputusan terkunci
Agung), Opsi B menambah kompleksitas (keypair baru untuk dikelola, entry peer baru untuk direvoke
terpisah, perlu sentuh config `wireguard-node*`) tanpa manfaat isolasi tambahan yang proporsional.

**Rekomendasi**: **Opsi A**. Kalau Agung tetap ingin Opsi B karena alasan lain (mis. rencana ke depan
memisahkan sidecar ke server fisik terpisah, atau ingin sidecar punya jalur revoke independen dari 5
consumer lain untuk alasan kebijakan, bukan teknis) — beri tahu di review, desain bagian ini akan ditulis
ulang sebelum implementasi dimulai.

## 4. Interface HTTP internal — HMAC, pola SAMA PERSIS dengan whatsapp-gateway

Dibaca langsung dari `App\Support\WhatsappHmac` (PHP) dan `whatsapp-gateway/internal/hmacsig/hmacsig.go`
(Go) — pola ini direplikasi APA ADANYA untuk `boss-app` ↔ sidecar, bukan diciptakan ulang:

- **Skema signing**: `HMAC-SHA256(secret, "{unix_timestamp}.{raw_body}")`, hex-encoded, dikirim di dua
  header (`X-Olt-Timestamp`, `X-Olt-Signature` — nama header baru, skema identik).
- **Toleransi replay**: 300 detik (sama persis `WhatsappHmac::TOLERANCE_SECONDS`).
- **Perbandingan timing-safe**: `hmac.compare_digest` (Python) — padanan `hash_equals()`/`hmac.Equal()`.
- **Secret**: `OLT_SIDECAR_HMAC_SECRET`, infra-level (kelas `APP_KEY`/`WHATSAPP_GATEWAY_HMAC_SECRET`),
  harus identik di `.env` root (dibaca `boss-app`) dan env sidecar sendiri.
- **Body ditandatangani APA ADANYA** — sama peringatan seperti `SendWhatsappMessageJob::sendToGateway()`:
  signing dilakukan atas string mentah YANG SAMA yang benar-benar dikirim, bukan hasil re-encode di sisi
  penerima (mismatch whitespace/urutan key akan merusak verifikasi).
- **`boss-app` sebagai CLIENT** (memanggil sidecar) — pola identik `SendWhatsappMessageJob::sendToGateway()`:
  `Http::withBody($body, 'application/json')->withHeaders([...])->timeout(N)->post(...)`. Kelas baru
  `App\Support\OltSidecarHmac` (struktur sama `WhatsappHmac`, secret berbeda) + service baru
  `App\Services\Network\OltSidecarClient` (nanti, saat implementasi).
- **Sidecar sebagai SERVER** — middleware HTTP Python (Flask/FastAPI, dipilih saat implementasi) yang
  meniru `whatsapp-gateway/internal/httpapi/httpapi.go`'s verifikasi: baca `X-Olt-Timestamp`/
  `X-Olt-Signature`, hitung ulang HMAC atas raw body, `hmac.compare_digest`, tolak 401 kalau gagal ATAU
  timestamp di luar toleransi.

### Bentuk endpoint (baca-saja, v0.23.3)

```
GET  /health                          -> {"status": "ok"}  (tanpa HMAC, murni liveness, tidak menyentuh OLT)
POST /olt/{olt_device_id}/read        -> body terstruktur (lihat di bawah)
```

**Kenapa satu endpoint generik `/read` + field `operation`, bukan satu endpoint per command**: daftar
command baca per vendor SUDAH besar dan BEDA struktur per vendor (lihat §5) — endpoint generik dengan
`operation` sebagai enum bernama (mis. `"show_version"`, `"onu_uncfg_list"`, `"onu_state"`,
`"onu_baseinfo"`, `"onu_detail_info"`) lebih mudah diperluas tanpa terus menambah route baru, dan tetap
membuat Laravel Service layer (bukan sidecar) yang memutuskan operasi APA yang boleh diminta kapan
(lihat §5 soal tanggung jawab logika bisnis).

### Skema body request — REVISI (kredensial ikut di body ini, lihat §8)

Sidecar tidak punya akses database — `vendor` (menentukan modul CLI mana yang dipakai) dan blok
`connection` (kredensial hasil decrypt `boss-app` dari `olt_devices`) WAJIB dikirim eksplisit oleh
`boss-app` tiap request, sidecar tidak pernah "mencari tahu sendiri":

```json
{
  "olt_device_id": 2,
  "vendor": "hsgq_e04id",
  "operation": "show_version",
  "connection": {
    "protocol": "ssh",
    "host": "10.168.100.5",
    "port": 22,
    "username": "...",
    "password": "..."
  },
  "args": {}
}
```

`vendor` ∈ `{"hsgq_e04id", "hsgq_g02id", "zte_c300"}` (satu modul Python per nilai, lihat §5).
`connection.protocol` ∈ `{"ssh", "telnet"}` — menentukan mekanisme `pexpect.spawn(...)` yang dipakai
(opsi legacy SSH untuk HSGQ vs `telnet` polos untuk ZTE, persis parameter yang sudah terbukti di skrip
riset v0.23.1/v0.23.2). `args` kosong untuk 3 operasi verifikasi awal — diisi kalau operasi butuh
parameter tambahan (mis. nomor interface PON) di sub-versi berikutnya.

**Respons terstruktur** (bukan teks mentah CLI):
```json
{
  "success": true,
  "operation": "onu_state",
  "olt_device_id": 4,
  "data": { "...": "terstruktur per operasi, lihat §5" },
  "raw_excerpt": null,
  "device_message": null
}
```
`raw_excerpt`/`device_message` diisi (bukan `data`) kalau sidecar gagal mem-parse output device ke
struktur yang diharapkan — LEBIH BAIK mengembalikan potongan mentah yang jelas gagal-parse daripada
diam-diam mengembalikan struktur kosong/salah. **Field yang wajib di-mask sebelum kembali ke Laravel**
(SN penuh/nama pelanggan/community/password) — masking terjadi DI SIDECAR sebelum respons dikirim, bukan
diasumsikan Laravel yang membersihkan (defense-in-depth, sidecar adalah titik terdekat ke data mentah).

## 5. Sidecar TIDAK punya logika bisnis — pemisahan tanggung jawab

**Sidecar TIDAK tahu**: pemilihan paket/profil, penomoran VLAN, skema nama "Nama - CID", ID ONU mana yang
"aman" dipakai berikutnya secara bisnis. Semua itu tetap di Laravel Service layer (BOSS-006).

**Sidecar TAHU**: cara menerjemahkan SATU `operation` terstruktur menjadi urutan command CLI vendor yang
benar (dari 3 dokumen referensi v0.23.1/v0.23.2), dan cara mem-parse output device itu kembali jadi
struktur. Contoh peta (bukan daftar lengkap, ilustrasi):

| `operation` | E04ID (SSH) | G02ID (SSH) | ZTE C300 (Telnet) |
|---|---|---|---|
| `onu_uncfg_list` | *(node `interface epon N`, kandidat `show onu-info all` — lihat "TIDAK BISA DIVERIFIKASI" di dok E04ID)* | *(node `interface gpon N`, `show ont-autofind`)* | `show gpon onu uncfg [gpon-olt_R/S/P]` — TERUJI |
| `onu_state` | *(belum ada command persis ditemukan — lihat dok E04ID)* | *(belum ada command persis ditemukan — lihat dok G02ID)* | `show gpon onu state <if>` — TERUJI |
| `onu_detail` | `onu-info <id>` *(kandidat, belum diuji)* | `ont setting <id>`/`ont-info` *(kandidat)* | `show gpon onu detail-info <onu>` — TERUJI |

**Implikasi penting untuk v0.23.3**: hanya operasi yang statusnya **TERUJI** di ketiga dokumen referensi
yang aman diimplementasikan sebagai endpoint baca nyata di sub-versi ini (lihat §9 "Rencana Implementasi"
— satu operasi baca per vendor, dipilih dari yang sudah TERUJI). Operasi yang statusnya masih
DARI REFERENSI PUBLIK/BELUM DIUJI **TIDAK** dibangun sekarang — sidecar hanya mengimplementasikan
translasi untuk operasi yang SUDAH terbukti aman lewat sesi riset manual (v0.23.1/v0.23.2), bukan
menebak sintaks baru.

## 6. Satu antrean per OLT

Sidecar menyimpan satu **queue in-process per `olt_device_id`** (dict Python, kunci `olt_device_id`,
nilai `asyncio.Lock` atau setara) — sebelum membuka sesi CLI baru ke sebuah OLT, request WAJIB
mendapatkan lock OLT tersebut dulu (blocking, dengan timeout wajar, mis. 30 detik — kalau timeout,
kembalikan error "OLT sedang dipakai request lain" daripada mengizinkan 2 sesi CLI bersamaan). Ini
mencegah PERSIS insiden kelas "Max Logins=1" yang sudah ditemukan nyata untuk G02ID (root) di v0.23.1 —
sidecar sendiri jadi penjamin single-session-per-OLT, bukan mengandalkan device menolak sesi kedua.

**Cakupan lock**: SATU sesi CLI penuh (login → command → logout) = SATU unit kerja yang memegang lock
OLT itu sepanjang durasinya — bukan per-command. Kalau ke depan (v0.23.5+) sebuah operasi butuh beberapa
command berurutan dalam SATU sesi (mis. baca ID terpakai dulu, baru tulis), lock yang sama menjamin tidak
ada request LAIN yang menyelip di antara baca-dan-tulis itu.

## 7. Cache peta ID ONU — desain struktur (v0.23.5+, distrukturkan sekarang)

**Bukan diimplementasikan di v0.23.3** — murni struktur data disiapkan sekarang supaya v0.23.5 tinggal
mengisi.

```python
# in-memory per proses sidecar, TIDAK persisten ke disk (hilang saat restart, sengaja —
# cache yang "berumur panjang lintas restart" lebih berisiko basi tanpa disadari)
onu_id_cache = {
    # (olt_device_id, pon_interface): {"ids_used": [1, 2, 3], "fetched_at": <unix_ts>}
    (4, "1/3/epon-2"): {"ids_used": [...], "fetched_at": 1790020000},
}
```

**Aturan keras yang WAJIB ditegakkan sejak desain awal (bukan optimisasi nanti)**: cache **TIDAK PERNAH**
jadi sumber kebenaran tunggal untuk keputusan TULIS. Setiap kali sebuah operasi TULIS (v0.23.5+) butuh
tahu "ID mana yang kosong", sidecar **WAJIB** membaca ulang ke device dulu (`onu_state`/`onu_baseinfo`
real-time) SEBELUM mengeksekusi tulis — cache HANYA boleh dipakai untuk keperluan TAMPILAN/estimasi cepat
di UI Laravel (mis. "kemungkinan ID kosong: 4" ditampilkan SEBELUM teknisi konfirmasi), bukan langsung
dieksekusi tanpa verifikasi ulang. Ini langsung menerapkan temuan nyata v0.23.2: `show gpon onu uncfg`
sendiri terbukti bisa menampilkan indeks USULAN yang ternyata sudah terpakai (lihat
`docs/omci/zte-c300-cli-reference.md` "Temuan untuk desain v0.23.5") — cache yang tidak diverifikasi
ulang akan mewarisi kelemahan yang SAMA.

## 8. Kredensial — sidecar tidak pernah menyimpan permanen (DIREVISI, keputusan Agung 2026-09-22)

`olt_devices.{telnet,ssh}_password`/`snmp_*_community` di-enkripsi dengan `APP_KEY` (Laravel `encrypted`
cast) — **hanya `boss-app` yang bisa decrypt**, sidecar TIDAK punya `APP_KEY` sama sekali.

**Alur per-request (REVISI — MENGGANTIKAN desain awal "panggilan balik sidecar→boss-app")**: `boss-app`
men-decrypt kredensial OLT SENDIRI (dari `olt_devices`, seperti biasa) lalu menyertakannya **LANGSUNG**
di body `POST /olt/{id}/read` yang sama — TIDAK ADA endpoint/arah panggilan baru. Kredensial melintas
satu kali, di jalur yang SUDAH ADA dan SUDAH dilindungi HMAC (§4) + TLS internal (boss-network, di luar
jangkauan publik, BOSS-010). Lihat §4 untuk skema body lengkap.

**Kenapa direvisi (alasan Agung, dicatat di sini untuk arsip)**: desain awal (panggilan balik terpisah
sidecar→boss-app untuk MEMINTA kredensial) menambah SATU endpoint baru yang fungsinya justru
"mengeluarkan kredensial terdekripsi atas permintaan" — permukaan risiko itu LEBIH BESAR daripada
menambahkan field kredensial di body request yang SUDAH ADA dan sudah dilindungi mekanisme yang sama.
Endpoint pengeluar-kredensial adalah target yang secara struktural lebih menarik untuk disalahgunakan
(satu titik yang "kalau berhasil dipanggil, langsung dapat kredensial apa pun") dibanding field di body
yang HANYA pernah membawa kredensial UNTUK operasi yang sedang diminta itu sendiri.

**Sidecar tetap TIDAK PERNAH menyimpan kredensial secara permanen**: dipakai **in-memory murni untuk satu
request itu saja**, dibuang begitu sesi CLI (berhasil atau gagal) selesai — tidak ada cache, tidak ada TTL,
tidak pernah ditulis ke disk/log (lihat §9, kredensial eksplisit masuk daftar "TIDAK PERNAH dicatat").
Variabel Python yang menampung password di-`del` begitu koneksi CLI ditutup (defense-in-depth — Python
tidak menjamin zeroing memori seperti bahasa level-rendah, tapi menghapus referensi secepat mungkin tetap
mengurangi jendela waktu password "hidup" di memori proses).

**Implikasi buat §4 (interface HTTP)**: body `POST /olt/{id}/read` sekarang WAJIB menyertakan blok
`connection` (protokol/host/port/username/password) — lihat skema diperbarui di §4.

## 9. Logging & audit

Setiap operasi dicatat ke log FILE di dalam container sidecar (rotated, di luar repo — sama prinsip
`olt_audit_log.txt` yang sudah dipakai sepanjang v0.23.1/v0.23.2, TAPI ini permanen/production, bukan
file kerja sesi riset): `{timestamp, olt_device_id, operation, requested_by (user id dari boss-app kalau
diteruskan), result (success/fail), duration_ms}`. **TIDAK PERNAH mencatat**: kredensial, SN/nama
pelanggan/community, isi command CLI mentah beserta parameternya kalau mengandung data pelanggan, output
device mentah. Log ini untuk troubleshooting operasional ("OLT mana yang sering gagal", "operasi apa yang
lambat"), bukan audit forensik command-level (yang sudah punya jejaknya sendiri di dokumentasi riset
manual v0.23.1/v0.23.2 — sidecar produksi TIDAK mereplikasi model itu, karena volumenya akan jauh lebih
besar dan bukan lagi sesi eksplorasi manual bertahap).

## 10. Rencana Implementasi (SETELAH persetujuan Agung — TIDAK dikerjakan sekarang)

1. **Skeleton container**: `Dockerfile` (Python + `pexpect` + client HTTP framework pilihan), service
   baru di `docker-compose.yml` (pola `librenms-dispatcher`: static IP di `INFRA_TUNNEL_BLOCK_CIDR`, tanpa
   host port), WireGuard route-reconcile loop (§3 Opsi A, adaptasi `docker/librenms/route-init.sh`).
2. **Endpoint baca-saja per OLT** — SATU operasi TERUJI per vendor (dipilih dari tabel §5 yang sudah
   berstatus TERUJI, mis. `onu_uncfg_list` untuk ZTE karena sudah 100% terverifikasi bentuknya), dipanggil
   dari `boss-app` (lewat `tinker`/route sementara, belum perlu UI Laravel penuh), hasil ditampilkan.
   Diuji ke KETIGA OLT lewat sidecar — bukan container sekali-pakai lagi (menggantikan mekanisme
   `docker run --rm --network container:librenms-dispatcher` yang dipakai sepanjang v0.23.1/v0.23.2 dengan
   jalur produksi yang sebenarnya).
3. **Test scoped** untuk sidecar (unit test Python untuk HMAC verify + translasi command per vendor,
   test scoped Laravel untuk `OltSidecarClient`/`OltSidecarHmac`) — BUKAN full regression suite (sesuai
   aturan proyek, sidecar adalah modul baru, bukan penutup cluster).

**DILARANG dieksekusi tanpa izin terpisah** (berlaku di implementasi maupun sesudahnya sampai ada
keputusan eksplisit baru): command tulis apa pun ke OLT (`onu`/`bind-onu`/`configure`/`ont add`/`save`/
`wr` di ketiga vendor), perubahan routing/firewall NAS di luar §3, penyentuhan GenieACS.
