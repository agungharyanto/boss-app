# Backlog Keamanan — hasil riset CLI OLT v0.23.1

**Tidak ada nilai kredensial/nilai sensitif apa pun yang dicantumkan di file ini** — murni daftar
tindakan yang perlu dilakukan Agung, ditemukan/dicatat selama sesi riset CLI read-only v0.23.1
(HSGQ-E04ID & HSGQ-G02ID, 2026-09-21). Lihat `docs/omci/hsgq-e04id-cli-reference.md` dan
`docs/omci/hsgq-g02id-cli-reference.md` untuk detail teknis non-sensitif hasil riset itu sendiri.

## 1. Ganti password akun `root` HSGQ-G02ID

Selama fase riset v0.23.1, Agung mengganti kredensial `olt_devices` id=4 (HSGQ-G02ID-BUMIREJA) memakai
akun `root`, khusus sementara untuk investigasi ini (lihat "Catatan decision-gate" di
`hsgq-g02id-cli-reference.md`). Password akun ini **sempat tertulis di riwayat chat** sesi ini (dikirim
Agung ke Claude Code untuk keperluan investigasi). **Wajib diganti** sebelum kredensial ini dianggap
aman dipakai lagi — kapan pun, tidak harus menunggu v0.23.2.

## 2. Rotasi SNMP community di 3 OLT + LibreNMS

Community `read` dan `write` SNMP saat ini **identik** di ketiga OLT terdaftar (ZTE C300, HSGQ-E04ID,
HSGQ-G02ID) — sudah dicatat sejak v0.8.1 (lihat CLAUDE.md, "LibreNMS OLT Onboarding"). Community ini
**sempat terlihat dalam bentuk plaintext** di output `show startup-config` HSGQ-G02ID selama sesi riset
v0.23.1 ini. **Wajib**:
- Rotasi community di ketiga OLT DALAM SATU JENDELA WAKTU yang sama (bukan satu per satu terpisah jauh
  — supaya tidak ada window di mana LibreNMS gagal poll sebagian OLT karena community sudah tidak
  sinkron).
- Update `olt_devices.snmp_ro_community`/`snmp_rw_community` di BOSS App SEGERA setelah rotasi di
  device, dan update device LibreNMS yang sudah di-`addDevice()` untuk ketiga OLT (lihat CLAUDE.md
  "LibreNMS OLT Onboarding" untuk daftar `device_id` masing-masing).
- Pertimbangkan memisahkan community `read` dan `write` (saat ini sama persis — praktik yang kurang
  aman) sebagai bagian dari rotasi ini, bukan sekadar mengganti nilai dengan pola yang sama.

## 3. Akun non-root khusus otomasi di kedua HSGQ (E04ID & G02ID)

`root` **TIDAK BOLEH** menjadi kredensial permanen `olt_devices` untuk otomasi v0.23.2 dan seterusnya
(lihat catatan decision-gate di kedua dokumen referensi CLI). Yang perlu dikerjakan Agung sebelum
v0.23.2 mulai menyentuh OLT secara live:
- **HSGQ-G02ID**: akun `boss` (khusus otomasi) sempat disebutkan sedang disiapkan Agung, TAPI **belum
  bisa SSH** per pengecekan terakhir sesi ini — perlu ditelusuri kenapa (kemungkinan level akses
  operator vs admin di firmware HSGQ, bukan sekadar salah password — perlu dicek dari sisi device
  langsung, di luar kemampuan investigasi read-only murni via SSH).
- **HSGQ-E04ID**: belum ada akun otomasi khusus non-root sama sekali sejauh riset ini — kredensial yang
  dipakai selama v0.23.1 tetap akun teknisi lama (bukan root, tapi juga bukan akun khusus otomasi
  ber-privilese terbatas).
- Untuk KEDUA perangkat: perlu dikonfirmasi level/privilese akun otomasi yang benar (setara "operator"
  read-write terbatas, bukan "admin"/"root" penuh) sebelum dipakai untuk provisioning nyata di
  v0.23.2 — pola yang sama seperti akun `boss-app-api-*` dedicated yang sudah dipakai untuk Mikrotik NAS
  (lihat CLAUDE.md, v0.6.5 "Root confusion identified and fixed").

## 4. Kredensial otomasi ZTE C300

**Update (v0.23.2, 2026-09-21)**: riset CLI read-only untuk ZTE C300 (`olt_devices` id=1, Telnet) sudah
dimulai — lihat `docs/omci/zte-c300-cli-reference.md`. Masih berlaku, belum dikerjakan:
- **Device ini SENDIRI menampilkan peringatan saat login**: *"The password is not strong, please change
  the password."* — konfirmasi langsung dari firmware (bukan dugaan kami) bahwa kredensial telnet
  `olt_devices` id=1 perlu diganti. **Sama urgensinya dengan poin 1 (password root G02ID) di atas.**
- Perlu keputusan kredensial otomasi khusus untuk ZTE C300 juga (sama semangat poin 3 di atas — bukan
  akun admin penuh yang sudah ada). Login saat ini langsung mendarat di mode privileged (`#`) tanpa
  `enable` terpisah — akun otomasi terbatas (kalau nanti dibuat) perlu dipastikan TIDAK otomatis
  privileged penuh seperti akun riset ini.
- Telnet (bukan SSH) — perlu pertimbangan keamanan tambahan (Telnet tidak terenkripsi) untuk desain
  sidecar WireGuard v0.23.3, khususnya soal SEJAUH MANA traffic Telnet ini aman melintasi tunnel
  internal vs risiko kalau ada titik intersepsi di jalur itu.
- Riset CLI read-only MASIH BERLANJUT (belum tuntas) — sintaks lengkap alur aktivasi (profil TR-069/
  WAN/VLAN per-ONU) belum sepenuhnya terverifikasi, jadi kredensial otomasi produksi belum bisa
  diputuskan/dibuat sebelum riset ini selesai.

## 5. Ganti password akun `boss` (SSH) HSGQ-E04ID (`olt_devices` id=2)

**Update (v0.23.3, 2026-09-22).**

**Akar penyebab (bukan sekadar "password bocor")**: saat menguji mekanisme antrean satu-sesi-per-OLT
sidecar (§6 desain — verifikasi TAMBAHAN di luar 3 operasi baca resmi yang direncanakan), sebuah perintah
shell sekali-pakai dibuat untuk membaca field `ssh_password` **langsung dari model `OltDevice`**
(`$device->ssh_password`) dan meng-*echo*-kannya ke output terminal, sebagai cara cepat menyiapkan
kredensial untuk uji manual — **di luar jalur resmi `OltSidecarClient`/`OltDevice::
sidecarConnectionPayload()`**, yang seharusnya menjadi satu-satunya titik kredensial pernah dibaca. Output
terminal itu otomatis masuk ke rekaman/transkrip sesi kerja ini. File kerja sementara yang memuatnya
dihapus segera setelah ditemukan, tapi **teksnya sudah terlanjur tercatat di transkrip sesi** — lokasi ini
di luar kendali/akses tools sesi ini untuk dibersihkan (bukan file kerja biasa, tapi rekaman percakapan
sistem).

**Hasil pemindaian menyeluruh (2026-09-22, sebelum commit)** — dicari di semua lokasi log/file sementara
yang masih ada di lingkungan ini, TANPA pernah mencetak nilai kredensial itu sendiri (hanya jumlah
kemunculan): direktori kerja sementara sesi (puluhan file) — 0 kemunculan untuk ketiga kredensial (E04ID,
G02ID, ZTE); log 5 container (`boss-app`, `boss-worker`, `boss-whatsapp-worker`, `boss-scheduler`,
`olt-sidecar`) — 0 kemunculan; `storage/logs/*.log` Laravel (15 file) — 0 kemunculan; file transkrip sesi
kerja ini sendiri — **46 baris cocok untuk kredensial E04ID, 2 baris untuk kredensial G02ID** (2 baris ini
kemungkinan besar sisa insiden yang SUDAH tercatat di poin 1 di atas, bukan insiden baru — root G02ID juga
"sempat tertulis di riwayat chat" pada v0.23.1), 0 baris untuk kredensial ZTE. **Catatan kejujuran**:
kredensial E04ID hanya 9 karakter — pada teks percakapan sepanjang ini, sebagian dari 46 kemunculan itu
kemungkinan collision/false-positive (substring pendek yang kebetulan cocok dengan teks lain, mis. ID/hash
di tempat tak terkait), bukan semuanya genuinely password itu sendiri — tapi ini TIDAK BISA dipastikan
tanpa melihat konteks tiap match, yang justru berisiko mencetak ulang nilainya. **Karena file transkrip ini
tidak bisa dibersihkan dari sesi kerja ini, rotasi password adalah satu-satunya mitigasi yang benar-benar
menutup risiko** — bukan penghapusan file.

**Wajib diganti**, sama urgensinya dengan poin 1 (password root G02ID) — kapan pun, tidak harus menunggu
sub-versi tertentu.

**Safeguard kode yang sudah diterapkan (v0.23.3, sebelum commit sub-versi ini)**: `App\Models\OltDevice::
sidecarConnectionPayload()` ditambahkan sebagai **satu-satunya** titik resmi untuk mengambil kredensial CLI
admin OLT dalam bentuk siap-kirim ke sidecar — `App\Services\Network\OltSidecarClient` dan test-nya
(`OltSidecarClientTest`, `OltDeviceTest`) sama-sama memanggil method ini, tidak ada kode lain (produksi
maupun sementara/debug) yang membaca `ssh_password`/`telnet_password` secara langsung. Digrep ulang
menyeluruh untuk memastikan tidak ada akses langsung tersisa di luar model itu sendiri, form UI admin
`OltDeviceIndex` (jalur WRITE yang sudah ada sejak v0.8.1, tidak terkait sidecar), migration, dan factory.
Pelajaran untuk sesi berikutnya: jangan pernah mengambil field kredensial mentah dari model `OltDevice`
untuk keperluan apa pun — termasuk debugging/verifikasi manual sekalipun — selain lewat method terpusat
ini.

---

**Prioritas eksekusi disarankan (bukan keputusan final, Agung yang menentukan)**: poin 1 (password root
G02ID) dan poin 5 (password `boss` E04ID) paling mendesak karena sama-sama sempat melintas di chat; poin 2
(rotasi SNMP community) berikutnya karena sudah lama diketahui identik read/write; poin 3 dan 4 bisa
menyusul seiring v0.23.2/v0.23.4+ benar-benar dimulai.
