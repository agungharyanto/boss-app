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

---

**Prioritas eksekusi disarankan (bukan keputusan final, Agung yang menentukan)**: poin 1 (password root
G02ID) paling mendesak karena sempat melintas di chat; poin 2 (rotasi SNMP community) berikutnya karena
sudah lama diketahui identik read/write; poin 3 dan 4 bisa menyusul seiring v0.23.2 benar-benar dimulai.
