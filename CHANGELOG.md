# Changelog

Format bebas mengikuti sprint di `docs/ROADMAP.md`. Setiap versi dicatat saat
tag dibuat (RULE BOSS-013).

## v0.6.5 — Dynamic Virtual Server & CoA (penutup v0.6.0)

Sub-sprint terakhir cluster v0.6.0 (FreeRADIUS Integration).

- **Dynamic per-NAS virtual server FreeRADIUS**: `FreeradiusVirtualServerService`
  menulis 2 file per NAS (`listen/nas-{id}.conf`, `clients/nas-{id}.conf`) ke
  volume baru `freeradius_nas_config`, dibaca lewat `$INCLUDE` direktori dari
  `sites-enabled/default`/`clients.conf` — dipatch idempoten oleh
  `docker/freeradius/entrypoint.sh`. Isolasi antar-NAS lewat PORT, bukan IP
  sumber (semua trafik NAS ter-MASQUERADE oleh container VPN node yang
  dilewati, jadi IP sumber tidak bisa membedakan NAS mana pun).
- **5 gap infrastruktur nyata ditemukan & diperbaiki saat deploy** (bukan
  cuma baca dokumentasi FreeRADIUS): `SIGHUP` TIDAK membuka listen socket
  baru (radiusd perlu restart sungguhan — dibuktikan lewat log server +
  `netstat` sebelum/sesudah); FreeRADIUS menolak start kalau direktori
  `$INCLUDE` world-writable (fix: `chgrp www-data + chmod 0770`, bukan
  `0777` seperti volume VPN lainnya); tabrakan port dengan listener
  `inner-tunnel` bawaan FreeRADIUS di 18120 (range dipindah mulai 20000);
  file root-owned dari sesi `tinker` verifikasi bikin `www-data` gagal
  menulis ulang (self-healing chmod tiap siklus poll ~3 detik, kelas bug
  sama seperti insiden PKI OpenVPN "nas-11" v0.6.3); config NAS yang
  tabrakan bikin `radiusd` mati total tanpa auto-restart (supervisor loop
  sekarang cek proses hidup tiap siklus, bukan cuma saat config berubah).
- **Port allocator** (`NasPortAllocatorService`, singleton counter row
  `lockForUpdate()`): `auth_port`/`acct_port` teralokasi otomatis & unik
  per NAS. **`coa_port` SENGAJA TIDAK ikut dialokasikan** — ditemukan
  lewat cek nyata `/radius/incoming/print` di router: port CoA RouterOS
  itu satu pengaturan per-router, bukan per-server-RADIUS, jadi tidak ada
  alasan harus unik seperti auth/acct. Tetap kolom biasa default 3799.
- **Script Generator RADIUS tab**: sekarang pakai `auth_port`/`acct_port`
  asli NAS (dynamic virtual server), bukan lagi port default 1812/1813
  bersama. **Bug nyata ditemukan push pertama ke test-x86-bajastu**: baris
  `/user group add ... policy=...` memuat `!dude`, keyword RouterOS 6.x
  yang sudah dihapus di 7.x — RouterOS menolak SELURUH string policy
  begitu satu token tidak dikenal, sempat salah didiagnosis sebagai
  masalah izin (ada masalah izin nyata terpisah yang kebetulan ditemukan
  lebih dulu). Diperbaiki (buang `!dude`, tambah `!rest-api`).
  **Diverifikasi nyata penuh**: script diterapkan bersih ke router asli,
  `radclient` dapat `Access-Accept` sungguhan lewat port dinamisnya.
- **CoA/Disconnect** (`CoaService`, `POST /nas/{nas}/disconnect`): kirim
  Disconnect-Request/CoA-Request ke NAS (arah kebalikan dari auth/acct —
  BOSS App jadi client, NAS jadi server). Eksekusi radclient WAJIB terjadi
  di dalam container `freeradius` sendiri (dibuktikan lewat
  `/radius/incoming/print` router: validasi CoA berdasarkan `address=`
  entri `/radius`, yang selalu IP statis freeradius) — dikoordinasikan
  lewat antrian file di volume bersama (pola sama seperti config NAS),
  bukan panggilan langsung dari `boss-app`. Firewall exception sempit
  ditambahkan (dikonfirmasi dulu bersama Agung, karena mengubah jaminan
  keamanan satu-arah yang dikunci sejak v0.6.2/v0.6.3):
  `iptables ... -s $FREERADIUS_INTERNAL_IP -j ACCEPT` di container
  openvpn/wireguard, plus route balik dari `freeradius` (resolve nama
  container, bukan IP hardcode, self-healing tiap siklus).
  **Diverifikasi nyata sampai batas yang jujur**: `tcpdump` di tun0
  konfirmasi paket Disconnect-Request benar-benar transit end-to-end
  (routing + firewall exception + eksekusi radclient dari container yang
  benar, semua terbukti). **Belum terverifikasi**: apakah router benar-
  benar mengeksekusi disconnect-nya — `test-x86-bajastu` ternyata router
  produksi nyata dengan 427 sesi pelanggan aktif (bukan lab), dan Agung
  memilih tidak memutus sesi pelanggan asli hanya untuk uji coba. Dicatat
  sebagai item verifikasi tertunda, bukan bug — sama seperti gap QR-scan
  WhatsApp dan retest hardware L2TP sebelumnya.
- **Bug nyata ditemukan & diperbaiki di kode v0.6.1-v0.6.4**:
  `NasIndex::testConnection()` — tes yang sukses dengan password BEDA
  dari yang tersimpan (misal masukin ulang password lama yang benar)
  cuma menyimpan `status`/`last_ping_at`, TIDAK PERNAH menyimpan ulang
  password yang benar itu — akibatnya NAS terlihat "online" sesaat lalu
  "offline lagi" di pemakaian berikutnya, pola yang kemungkinan besar
  adalah akar dari insiden "password 154415" yang pernah diselidiki
  sebelumnya. Sekarang tes sukses dengan password baru otomatis
  menyimpannya.
- **Di luar sprint tapi terjadi di tengah sprint atas permintaan
  eksplisit Agung**: `config('app.timezone')` ternyata hardcode `'UTC'`
  walau root `.env` sudah lama mendeklarasikan `APP_TIMEZONE=Asia/
  Jakarta` (pola bug sama seperti `APP_ENV`) — diperbaiki, dipasangkan
  dengan `migrate:fresh` + reseed penuh (disetujui karena BOSS App
  sendiri belum live production). Ketemu 1 bug urutan nyata saat reseed:
  `VpnServersSeeder` (v0.6.4) mengasumsikan baris node1 sudah ada dengan
  id terendah per protokol (`VpnServer::poolOwnerFor()` bergantung pada
  ini) — kalau dijalankan sebelum node1 dibuat ulang, node2/3 malah jadi
  pool owner secara diam-diam. Ditemukan lewat cek `poolOwnerFor()`
  langsung, bukan diasumsikan; diperbaiki dengan urutan buat ulang yang
  benar.

### Amendment — perbaikan bug rotasi password API NAS (masih v0.6.5, sebelum tag)

- **Bug dikonfirmasi ulang oleh Agung**: `generateRadiusScript()` merotasi
  `nas.api_username`/`api_password` di SETIAP pemanggilan tanpa syarat,
  termasuk saat cuma preview — bukan cuma efek samping ringan, tapi
  penyebab langsung insiden "NAS offline sendiri" yang berulang kali
  dilaporkan sepanjang sprint ini. Diperbaiki tuntas:
  `MikrotikScriptGenerator::radiusScript()` sekarang cuma berisi
  `/radius add`, tidak menyentuh `/user`/`/user group` sama sekali;
  `VpnScriptService::generateRadiusScript()` murni read-only (test baru
  memanggil 5x berturut-turut, assert nol perubahan DB — diverifikasi
  nyata juga terhadap `test-x86-bajastu`: 5x panggilan, `api_password`
  tidak berubah, `testConnection()` tetap berhasil tanpa input ulang).
- **User API khusus BOSS App, terpisah dari kredensial admin asli**
  (root confusion yang bikin bug di atas berdampak besar — kredensial
  admin ASLI ikut rusak, bukan cuma kredensial internal): kredensial
  admin sekarang cuma dipakai sekali pakai lewat modal terpisah ("Buat/
  Perbarui User API" di `/nas`), tidak pernah disimpan. BOSS App
  otomatis membuat user API khusus (`boss-app-api-{nas_id}`, grup
  `boss-app-api`, policy `read,api,password` + deny selebihnya, TANPA
  `!dude`) lewat `NasApiUserProvisioningService`, dan `nas.api_username`/
  `api_password` ter-update ke kredensial baru itu. Grup dapat hak
  `password` (bisa ubah password sendiri) supaya rotasi berikutnya bisa
  self-service tanpa minta kredensial admin lagi.
  **Diverifikasi nyata terhadap test-x86-bajastu**: user `boss-app-api-1`
  benar-benar dibuat di router dengan policy persis seperti dirancang,
  `nas.api_username`/`api_password` di database ter-update, dan
  `testConnection()` berhasil pakai kredensial barunya.

## v0.6.4 — Multi-Node VPN Pool & Auto-Switch Failover

- **Keputusan arsitektur dikonfirmasi bersama Agung sebelum implementasi**:
  pool **3 node VPN nyata** (bukan cuma skema siap N>1 tapi 1 baris
  terisi seperti rencana awal v0.6.2) untuk OpenVPN dan WireGuard —
  masing-masing protokol 3 container terpisah (`openvpn-node1/2/3`,
  `wireguard-node1/2/3`), sesuai pola single-responsibility container
  yang sudah dikunci sejak v0.6.3. L2TP TIDAK ikut pool (known
  limitation belum selesai, tetap 1 node). **Ketiga node per protokol
  berbagi trust domain yang sama** — OpenVPN: volume PKI (`vpn_pki`)
  di-mount ke ketiga node, satu CA, cert yang diterbitkan sekali valid
  di node manapun. WireGuard: server keypair + direktori peer
  (`vpn_wg_data`) di-mount ke ketiga node, pubkey server identik di
  semua node — diverifikasi nyata: `wireguard-node2` langsung memakai
  pubkey persis sama dengan node1 begitu container pertama kali boot.
  Ini yang membuat auto-switch script cukup ganti `connect-to`/
  `endpoint-port` tanpa re-import credential apa pun.
- Migration baru `vpn_servers.port` (per-node, sebelumnya port
  OpenVPN/WireGuard adalah satu config global) + `VpnServersSeeder`
  idempoten mengisi 4 baris node baru (bukan seed dummy — baris nyata
  untuk container yang benar-benar hidup).
- `VpnProvisioningService`: node BARU dipilih berdasarkan load
  distribution (`status=online` DAN `current_clients < max_clients`,
  diurutkan dari yang paling longgar, `lockForUpdate()` race-condition-
  safe) — TAPI `internal_ip` tetap selalu dialokasikan dari "pool
  owner" (node1, baris ber-id terendah per protokol) supaya konsisten
  dengan CCD OpenVPN yang cuma pernah ditulis ke node1 dan direktori
  peer WireGuard yang shared. `VpnServer::poolOwnerFor()` — konvensi
  baru, didokumentasikan lengkap di CLAUDE.md.
- **Health-check terjadwal nyata** (`VpnCheckNodeHealth`,
  `->everyMinute()`): tiap node openvpn/wireguard menulis heartbeat
  timestamp ke volume shared setiap ~10 detik (OpenVPN: loop baru;
  WireGuard: numpang di reconcile loop yang sudah ada); command
  membaca heartbeat, update `status` (online/full/offline). **Bug nyata
  ditemukan & diperbaiki lewat uji jalan otomatis, bukan cuma baca
  kode**: `boss-scheduler` (container yang benar-benar mengeksekusi
  `schedule:run`) TIDAK mount volume `vpn_pki`/`vpn_wg_data` sama
  sekali — health-check yang berjalan lewat scheduler akan SELALU
  menandai semua node offline meski sehat, sampai volume itu
  ditambahkan ke service `boss-scheduler` di docker-compose.yml.
  Diverifikasi nyata: `boss-scheduler` dibiarkan jalan otonom 2 siklus
  penuh (2 menit), status tetap `online` konsisten, tidak flapping.
- **Auto-switch scheduler Mikrotik** (`MikrotikScriptGenerator`) —
  ditambahkan ke tab VPN Script setelah konfigurasi utama: cek
  FreeRADIUS tiap 30 detik lewat tunnel, kalau gagal pindah port ke
  node lain (siklus sekuensial, port dialokasikan berurutan oleh
  `VpnServersSeeder`). **3 bug nyata ditemukan berturut-turut lewat
  deploy sungguhan ke `test-x86-bajastu`** (bukan diasumsikan benar dari
  membaca dokumentasi RouterOS saja), semua bermuara pada bagaimana
  `/import` memproses file `.rsc`, berbeda dari mengirim command yang
  sama langsung lewat API:
  1. `/system scheduler add ... on-event={...multi-baris...}` — blok
     curly-brace TIDAK PERNAH benar-benar tersimpan sebagai isi
     `on-event` (dikonfirmasi kosong lewat query `.proplist=on-event`
     langsung, bukan cuma tampilan terpotong). Diperbaiki dengan pola
     yang SUDAH TERBUKTI dipakai scheduler bawaan router ini sendiri
     (`schedule-script-speed` dkk): `on-event` menunjuk ke NAMA script
     terpisah, bukan kode inline.
  2. Script terpisah itu sendiri (`/system script add ... source={...}`)
     mengalami masalah PERSIS SAMA — `source` juga kosong. Diperbaiki
     dengan mengubah `source=` jadi SATU BARIS string (dipisah `;`,
     pola sama one-liner fetch+import yang sudah dipakai di seluruh
     modul ini), bukan blok multi-baris.
  3. Setelah `source` akhirnya terisi, SETIAP referensi `$variable` di
     dalamnya ternyata di-expand kosong oleh `/import` (RouterOS
     meng-evaluasi `"$var"` di dalam string terkutip terhadap scope
     SAAT IMPORT dijalankan, bukan scope runtime script itu sendiri) —
     dibuktikan dengan mengirim string identik langsung lewat API
     (bukan file `.rsc`), yang tersimpan sempurna tanpa masalah apa
     pun. Diperbaiki dengan menulis backslash literal di depan setiap
     `$` (`\$var`, bukan `$var`) — dikonfirmasi langsung ke router
     bahwa `/import` lalu menyimpannya sebagai `$var` literal.
- **Verifikasi failover nyata, end-to-end, bukan simulasi**: setelah
  ketiga bug di atas teratasi, `docker compose stop wireguard-node2`
  (node yang sedang dipakai `test-x86-bajastu`) — dalam ~60 detik
  (2 siklus scheduler 30 detik), `endpoint-port` peer WireGuard di
  router **berpindah otomatis** dari port node yang mati ke port node
  berikutnya, handshake baru terbentuk, dan `/ping` ke FreeRADIUS lewat
  tunnel baru berhasil 4/4 paket (0% packet loss) — tanpa satu pun
  intervensi manual. Node yang dimatikan dihidupkan kembali setelahnya.
- Bonus fix kecil tapi nyata: `File::get()` untuk WireGuard server
  public key membawa trailing newline dari `wg pubkey` yang bisa
  merusak parsing quoted-string di script yang digenerate — sekarang
  di-`trim()`.
- Dropdown protokol Script Generator: L2TP/IPsec tetap tampil (tidak
  disembunyikan) dengan label eksplisit "(known limitation)" + catatan
  detail di UI, bukannya dibiarkan seolah setara OpenVPN/WireGuard.

**Catatan verifikasi tertunda, bukan bug atau sprint belum selesai**:
implementasi dan fungsinya sudah terbukti nyata sepenuhnya lewat API
langsung ke `test-x86-bajastu` (load-distribution, health-check,
auto-switch failover end-to-end semua dikonfirmasi bekerja) — tapi
**jalur UI Livewire (Script Generator, tombol-tombol terkait) untuk
skenario multi-node/failover ini belum pernah dicoba manual oleh Agung
lewat browser**. Agung akan menyiapkan router Mikrotik terpisah khusus
untuk testing UI menyeluruh nanti. Detail arsitektur lengkap ada di
`docs/ROADMAP.md` dan CLAUDE.md bagian "Multi-Node VPN Pool & Auto-Switch
Failover (v0.6.4)".

## v0.6.3 — Multi-Protokol VPN (WireGuard, L2TP/IPsec) & Script Generator Mikrotik

- **2 keputusan arsitektur diselesaikan bersama Agung sebelum implementasi**
  (lihat `docs/ROADMAP.md`): (A) WireGuard & L2TP/IPsec di container Docker
  terpisah dari `openvpn`, konsekuensinya `vpn_servers.protocol_support`
  (json) diganti kolom `protocol` polos — sekarang satu baris per (host,
  protocol); (B) tab RADIUS Script Generator sementara pakai port default
  FreeRADIUS (1812/1813), bukan `nas.auth_port`/`acct_port`, sampai v0.6.5.
- Container Docker baru `wireguard` (alpine + wireguard-tools 1.0.20210914,
  host port UDP 51820) dan `l2tp` (alpine + strongSwan 5.9.13 + xl2tpd
  1.3.18, host port UDP 500/4500/1701) — strongSwan dipilih atas libreswan
  karena rilis Alpine-nya lebih rutin dan lebih umum jadi referensi setup
  L2TP/IPsec PSK.
- Migration alter `vpn_servers` (protocol_support json -> protocol string,
  backfill data existing row v0.6.2 otomatis) + kolom baru
  `vpn_accounts.public_key` (WireGuard, bukan secret — tidak perlu
  encrypted).
- `App\Services\Network\VpnProvisioningService::provision()`/`revoke()`
  di-refactor bercabang per protokol:
  - **WireGuard**: keypair digenerate di `boss-app` (`wg genkey`/`wg
    pubkey` via Process facade — murni kripto, tidak butuh live interface).
    Peer changes TIDAK bisa dijalankan langsung dari `boss-app` (beda
    network namespace) — dipakai pola **reconcile loop** (idiom sama
    `boss-scheduler`/`boss-whatsapp-worker`): `boss-app` menulis file
    `[Peer]` per-NAS ke volume bersama, container `wireguard` menggabung +
    `wg syncconf` tiap 10 detik. Private key HANYA dikembalikan sekali
    lewat properti PHP transient (`VpnAccount::$wireguardPrivateKey`,
    BUKAN kolom Eloquent) — tidak pernah disimpan di DB.
  - **L2TP/IPsec**: PSK IPsec satu untuk seluruh node (infra-level, env
    `L2TP_IPSEC_PSK`), auth per-NAS di layer PPP (`chap-secrets`, akhirnya
    memakai kolom `vpn_accounts.password` yang sejak v0.6.2 sudah
    disiapkan tapi belum terpakai). `chap-secrets` di-**regenerate
    seluruhnya** dari DB tiap provision()/revoke() (bukan append/remove
    baris) — `pppd` di-spawn `xl2tpd` fresh per percobaan koneksi, jadi
    perubahan langsung efektif tanpa reload.
- `App\Services\Network\MikrotikScriptGenerator` (pure templating, tanpa
  I/O) + `App\Services\Network\VpnScriptService` (orkestrasi — provisioning
  kalau belum ada akun, baca material yang tersedia per protokol, panggil
  generator). Script idempotent (hapus interface lama dulu) dan isolasi
  routing (routing-mark + rule untuk OpenVPN/L2TP; `allowed-address`
  WireGuard sendiri sudah cukup) — NAS tidak pernah dapat default route
  lewat tunnel.
- UI baru **Script Generator** (`App\Livewire\Network\VpnScriptGenerator`,
  `/vpn-script-generator`, cluster sidebar "Network" baru) — 2 tab (VPN
  Script, RADIUS Script), reseller-scoped via `NasPolicy`. Generate RADIUS
  Script juga meng-**(re)generate `nas.api_username`/`api_password`** —
  menutup celah dengan `NasService::testConnection()` (v0.6.1) yang
  butuh kredensial API Mikrotik yang benar-benar valid.

Bug nyata ditemukan & diperbaiki lewat verifikasi end-to-end sungguhan
(provisioning WireGuard/L2TP asli, peer terbukti muncul/hilang di `wg show`
setelah reconcile loop, `chap-secrets`/`ipsec.conf` terbukti ter-render
benar di dalam container):
- `wg`/`wg genkey`/`wg pubkey` dipanggil dari `boss-app` tapi
  `wireguard-tools` cuma ter-install di image `openvpn`— lupa ditambah ke
  `docker/php/Dockerfile`. Kali ini paket baru ditaruh di layer `apk add`
  **terpisah setelah** layer `docker-php-ext-install sockets` yang lambat,
  supaya tidak mengulang kesalahan cache-invalidation v0.6.1/v0.6.2 (±7
  menit kompilasi ulang percuma).
- `Process::fake()` di test butuh pattern `wg genkey`/`wg pubkey` juga
  diawali `*` (gotcha yang sama dengan `easyrsa` v0.6.2).
- Bug kecil di test sendiri (bukan implementasi): asumsi awal `/29` CIDR
  menghasilkan 4 alamat usable, ternyata 5 (`CidrRange` v0.6.2 memang benar
  — perhitungan manual di test yang salah).
- `provision()`'s final `->fresh()` re-query diam-diam membuang properti
  PHP transient `$account->wireguardPrivateKey` (instance model baru dari
  DB tidak mungkin membawa properti non-kolom) — ditemukan lewat test
  sendiri, diperbaiki dengan tidak memanggil `->fresh()` di akhir
  `provision()` (semua `issue*Credentials()` sudah memutasi instance yang
  sama via `->update()`, jadi tidak ada data basi).

Out-of-scope v0.6.3, di-declare eksplisit sebagai backlog: multi-node pool +
health-check + failover + `VpnServerController` (v0.6.4 — baris
`vpn_servers` WireGuard/L2TP sprint ini dibuat manual lewat `tinker`, sama
seperti OpenVPN di v0.6.2), dynamic virtual server + CoA + port unik
per-NAS sungguhan (v0.6.5 — RADIUS Script masih port default sampai saat
itu), force-disconnect sesi WireGuard/L2TP yang sudah aktif saat revoke
(WireGuard: peer hilang max ~10 detik setelah revoke, bukan instan; L2TP:
sesi PPP yang sudah jalan tidak diputus paksa), verifikasi koneksi sungguhan
dari NAS Mikrotik asli (tidak ada perangkat fisik/virtual di environment
ini — sama seperti gap WhatsApp QR-scan v0.4.0).

Detail lengkap ada di CLAUDE.md bagian "Multi-Protocol VPN & Script
Generator (v0.6.3)".

**Amendment (gap ditemukan setelah verifikasi awal, ditutup di sesi yang
sama sebelum v0.6.3 di-tag)**: dropdown "Pilih NAS" di Script Generator
ternyata kosong pada percobaan pertama — didiagnosis dulu sebelum
diasumsikan bug: bukan bug query, tapi memang belum ada satu pun NAS asli
untuk tenant demo (`super_admin@boss.local`) — 4 baris `nas` yang sempat
ada semuanya sisa data pollution dari sesi verifikasi tinker v0.6.1-v0.6.3
di tenant palsu yang tidak terhubung user manapun (sudah dibersihkan). Akar
masalahnya: **UI manajemen NAS memang belum pernah dibangun** — v0.6.1
sengaja API-only. Ditambahkan sebagai prasyarat Script Generator, bukan
scope baru:
- `App\Livewire\Network\NasIndex` (`/nas`, cluster sidebar "Network" di
  atas Script Generator) — list + create/edit, pola admin-vs-reseller sama
  `WhatsappGatewayIndex` (v0.4.0). `mikrotik_ip` sekarang bisa diisi manual
  lewat UI ini (koreksi dari pembatasan `StoreNasRequest`/v0.6.1 yang
  sebelumnya melarangnya sama sekali) — otomatis terkunci (read-only)
  begitu NAS punya `vpn_accounts` aktif, TANPA membangun mekanisme
  auto-sync `internal_ip` -> `mikrotik_ip` yang sungguhan (itu tetap gap
  terpisah yang sudah tercatat, UI ini cuma mencegah admin bentrok dengan
  auto-management yang belum ada).
- Tombol "Tes Koneksi" di form create/edit — memakai `RouterOsGateway`
  yang sama dengan `NasService::testConnection()` (v0.6.1, tidak ditulis
  ulang), tapi dipanggil dengan nilai form YANG SEDANG DIKETIK (belum
  tentu tersimpan) lewat instance `Nas` transient — `NasService`'s method
  sendiri tidak bisa dipakai langsung untuk NAS baru karena butuh row yang
  sudah persisted. Untuk NAS yang sudah tersimpan, `status`/`last_ping_at`
  tetap ter-update ke row asli setelah tes.
- Diverifikasi nyata (bukan asumsi): NAS yang dibuat lewat UI baru ini
  langsung muncul di query dropdown Script Generator tanpa cache apa pun
  (query yang sama, tidak ada layer cache di antaranya).
- 12 test baru (`NasIndexLivewireTest`): render, create, validasi
  (radius_secret wajib), blank-submit tidak menghapus secret tersimpan,
  mikrotik_ip terkunci, reseller-scoping (create + isolasi lihat/edit),
  tes koneksi sukses/gagal + tidak persist untuk NAS baru.

**Amendment kedua (3 perbaikan dari hasil testing manual via UI, ditutup
sebelum tag)**:
- **Bug nyata**: `MikrotikScriptGenerator` selalu memakai mekanisme
  `/routing table` + `routing-table=` (RouterOS 7-only) untuk isolasi
  routing OpenVPN/L2TP, walau diklaim "RouterOS-generic v6.x dan v7.x" —
  `/routing table` tidak ada sama sekali di RouterOS 6.x (didesain ulang
  total di v7). Diperbaiki: bercabang per versi — v6 pakai `routing-mark=`
  langsung di `/ip route add` + `/ip firewall mangle` (gaya lama, sesuai
  referensi MixRadius), v7 tetap `/routing table`+`routing-table=`+
  `/routing rule`. `dst-address` sendiri sudah benar ada di kedua jalur
  sejak awal (diverifikasi, bukan bug). Semua script (protokol apa pun)
  sekarang juga menghapus keempat interface client (ovpn/sstp/l2tp/pptp)
  + WireGuard di awal, bukan cuma interface protokol yang sedang
  digenerate.
- **Bug nyata (akar masalah nas-11), ditemukan lewat investigasi langsung
  ke volume PKI**: `chmod -R 0777` di entrypoint `openvpn` cuma jalan
  SEKALI saat boot. Setiap invokasi `easyrsa` berikutnya (dari `boss-app`,
  berjalan sebagai `www-data` di request HTTP asli) menulis ulang
  `pki/index.txt`/`serial`/`index.txt.attr` dengan permission ketat
  bawaan OpenSSL — request berikutnya oleh user/proses lain gagal
  *Permission denied* setelah sempat mencetak progress dots OpenSSL
  (".....+++...."), yang sebelumnya ikut ter-dump mentah ke pesan error
  API. Diperbaiki: `VpnProvisioningService` sekarang menangkap stdout/
  stderr terpisah, membersihkan pesan error (buang noise progress dots,
  ambil baris terakhir yang relevan, log FULL output ke Laravel log), dan
  me-restore permission 0777 setelah SETIAP invokasi easyrsa (sukses
  maupun gagal) — bukan cuma sekali di boot. **Diverifikasi nyata**:
  provisioning OpenVPN sebagai `www-data` sungguhan (`docker compose exec
  --user www-data`, bukan root seperti sesi tinker sebelumnya) berhasil
  dua kali berturut-turut tanpa chmod manual di antaranya; NAS asli milik
  user (`nas-11`) yang tadinya gagal sekarang berhasil generate cert
  (`cert_serial` terisi, file `.crt` tersimpan dengan pemilik `www-data`).
- **Bug nyata tambahan, ditemukan saat menguji tombol revoke-lalu-generate-
  ulang**: `vpn_accounts.internal_ip` punya unique constraint GLOBAL
  (termasuk baris yang sudah revoked) sejak v0.6.2 — begitu satu IP pernah
  dipakai, IP itu tidak bisa dipakai ulang selamanya walau sudah
  dibebaskan di `vpn_ip_pool`, karena baris lama (revoked) masih menempati
  nilai itu di index unique. Migration baru menggantinya dengan partial
  unique index (`WHERE status = 'active'`) — pola sama dengan
  `whatsapp_sessions` (v0.4.0) — supaya IP yang sudah revoked benar-benar
  bisa dipakai ulang.
- Tombol **"Cabut & Generate Ulang"** ditambahkan di Script Generator —
  muncul otomatis saat generate gagal karena NAS sudah punya akun aktif
  untuk protokol yang dipilih (kondisi ini reachable untuk WireGuard,
  karena private key lama sudah hilang). Satu klik: revoke akun lama →
  provision baru → generate script, dengan `wire:confirm` sebagai
  peringatan aksi destruktif (koneksi lama terputus).

**Amendment ketiga (4 perbaikan dari hasil test langsung di router Mikrotik
asli, ditutup sebelum tag)**:
- **Bug nyata, dilaporkan dari perangkat asli**: `navigator.clipboard.writeText()`
  gagal senyap di tombol "Salin" Script Generator karena server ini masih HTTP
  polos (bukan `localhost`, jadi bukan secure context browser) — Clipboard API
  memang tidak tersedia sama sekali di kondisi ini, bukan bug logic. Diperbaiki
  dengan fallback `document.execCommand('copy')` lewat textarea sementara,
  dipilih otomatis berdasar `window.isSecureContext`. **Diverifikasi nyata**
  lewat Playwright headless browser sungguhan yang login & klik tombol di
  server dev ini sendiri (bukan localhost HTTPS) — dikonfirmasi
  `isSecureContext=false`, `navigator.clipboard` memang undefined, dan
  `document.execCommand('copy')` benar-benar terpanggil (di-hook langsung,
  bukan diasumsikan) dengan UI feedback "Tersalin!" tampil.
- **Bug nyata, dari log strongSwan router asli (`NO-PROPOSAL-CHOSEN`)**:
  `ipsec.conf.template` cuma menawarkan `aes256/aes128/3des-sha1-modp1024`
  dengan strict mode (`!`) — RouterOS 7 klien asli menawarkan proposal ESP
  yang tidak persis cocok (termasuk NULL-encryption/HMAC-SHA1, ditemukan dari
  referensi traffic MixRadius yang berhasil konek). Diperbaiki: proposal
  ESP/IKE diperluas (tambah `modp2048`, tambah `null-sha1` di ESP) dan strict
  mode (`!`) dilepas supaya strongSwan juga mencocokkan default bawaannya
  sendiri kalau penawaran RouterOS tidak persis sama. **Batasan verifikasi
  jujur**: dikonfirmasi config valid & strongSwan start bersih tanpa error
  fatal (`ipsec statusall` menunjukkan connection ter-load benar), TAPI
  `NO-PROPOSAL-CHOSEN` benar-benar hilang + IPsec SA established + l2tp-client
  "connected" di RouterOS 7 asli **belum bisa dikonfirmasi dari environment
  ini** (tidak ada akses perangkat Mikrotik fisik/virtual) — perlu tes ulang
  langsung oleh Agung di router asli.
- **Perubahan mekanisme, dari laporan reboot-prompt yang menggantung**: paste
  script panjang (terutama OpenVPN yang berisi private key PEM) langsung ke
  terminal interaktif RouterOS ditemukan bisa memicu prompt konfirmasi tak
  terkait ("Save changes? [y/N]") di tengah paste, memutus sisa perintah.
  Diganti dengan mekanisme **fetch+import**: UI sekarang menampilkan satu
  baris pendek (`/tool fetch ... ;/import file-name=...;/file remove ...`)
  alih-alih script penuh untuk di-paste. `App\Services\Network\
  ScriptDownloadTokenService` menyimpan script lengkap di cache (Redis)
  dengan token acak 48-karakter, TTL 10 menit, **sekali pakai**
  (`Cache::pull` — atomic get-and-delete). Endpoint baru
  `GET /vpn-script-generator/download/{token}.rsc`
  (`VpnScriptDownloadController`) sengaja TANPA middleware auth — `/tool
  fetch` RouterOS tidak mengirim cookie/token, keamanan bertumpu pada token
  itu sendiri (entropi tinggi + TTL pendek + sekali pakai), bukan permanent
  public URL. Skema URL (`http`/`https`) diambil dari request yang sedang
  berjalan (`request()->getSchemeAndHttpHost()`), BUKAN hardcode — server
  ini masih HTTP polos, otomatis berubah ke `https` sendiri begitu TLS
  terpasang, tanpa ubah kode. Pola yang sama dipakai untuk SEMUA jenis
  script (VPN maupun RADIUS), bukan cuma OpenVPN, demi konsistensi UX. Script
  lengkap tetap bisa dilihat lewat `<details>` collapsible di bawah
  one-liner (transparansi/audit), hanya bukan target copy-paste utama lagi.
  **Diverifikasi nyata** end-to-end: Playwright browser sungguhan
  men-generate one-liner asli (`mode=http` terkonfirmasi, bukan hardcode
  https), lalu `curl` langsung ke endpoint download membuktikan isi script
  yang diunduh benar dan fetch KEDUA ke token yang sama mengembalikan 404
  (sekali-pakai benar-benar berfungsi). **Batasan verifikasi jujur**: yang
  BISA dikonfirmasi dari sini adalah server-side (isi script benar, token
  sekali pakai, tidak hardcode https). Bahwa prompt `[y/N]` benar-benar tidak
  lagi menggantung proses saat `/tool fetch` + `/import` dijalankan di
  RouterOS asli **belum bisa dikonfirmasi dari environment ini** — perlu tes
  langsung oleh Agung di router asli.
- **Bug nyata, kontras tombol**: tombol "Cabut & Generate Ulang" pakai
  `bg-red-600 text-white` — ternyata SATU-SATUNYA tempat di seluruh app yang
  memakai pola filled-button untuk aksi destruktif (pola dominan app-wide
  adalah `text-red-600 hover:underline`, dipakai di 6+ tempat lain — lihat
  `nas-index.blade.php`, `customer-show.blade.php`, dll). Akar masalah
  ganda: (1) Tailwind CSS bundle belum di-rebuild sejak view ini dibuat, jadi
  `bg-red-600` memang tidak ada sama sekali di CSS terkompilasi (bukan typo
  class), (2) bahkan setelah rebuild, class itu tetap tidak konsisten dengan
  pola app. Diperbaiki dengan pindah ke pola `text-red-600 hover:underline`
  yang sudah established, sekaligus rebuild Tailwind bundle
  (`app-BrUlYJCB.css`) untuk semua view v0.6.3 yang belum pernah di-rebuild
  sejak dibuat (termasuk `nas-index.blade.php`). **Diverifikasi nyata**:
  Playwright mengambil `getComputedStyle` warna tombol "Hapus" (pola yang
  sama) di halaman NAS sungguhan — hasilnya `oklch(0.637 0.237 25.331)`,
  merah Tailwind `red-600` asli, bukan putih/transparan.
- 4 test baru (`VpnScriptDownloadTest`): download token valid, token sekali
  pakai (fetch kedua 404), token tidak dikenal 404, `mode=http` bukan
  hardcode https. `VpnScriptGeneratorLivewireTest` diperbarui untuk
  memverifikasi `fetchCommand` (one-liner) terisi, bukan cuma
  `generatedScript`.

**Amendment keempat (regresi 500 di tombol "Cabut & Generate Ulang",
ditemukan lewat pemakaian nyata setelah Amendment ketiga, ditutup sebelum
tag)**: klik tombol untuk NAS dengan protokol WireGuard menghasilkan 500
generik. **Akar masalah ditemukan dari `storage/logs/laravel.log` asli**
(bukan tebakan): `mkdir(): Permission denied` di
`VpnProvisioningService::issueWireGuardCredentials()` saat menulis fragment
peer ke `/vpn-wg-data/peers`. Investigasi lanjutan (`stat` langsung dari
dalam container) menemukan `/etc/wireguard` (mount point volume
`vpn_wg_data`) sendiri berpermission `700 root:root` — bawaan default paket
`wireguard-tools` Alpine (dirancang untuk mengamankan private key di setup
single-user), ikut terbawa ke volume Docker saat pertama kali dibuat.
Entrypoint `wireguard` cuma pernah `chmod` anak-anaknya (`peers/` dan kedua
file key), tidak pernah direktori volume itu sendiri — `www-data` sama
sekali tidak bisa traverse masuk ke `/etc/wireguard`, jadi
`File::isDirectory()`/`makeDirectory()` gagal EACCES sebelum sempat
menyentuh `peers/` yang sebenarnya sudah permisif. Diperbaiki dengan
`chmod 0755 "$WG_DIR"` tambahan di `docker/wireguard/entrypoint.sh`. Volume
`vpn_l2tp_secrets` diperiksa juga (kekhawatiran kelas bug yang sama) —
ternyata aman, `755` sejak awal karena dibuat murni oleh `mkdir -p` tanpa
paket yang menyuntik permission ketat. **Diverifikasi nyata, ulang persis
skenario yang dilaporkan**: rebuild container `wireguard`, lalu Playwright
browser sungguhan login → pilih NAS `test-x86-bajastu` (nas-11, yang
memang sudah punya akun WireGuard aktif) → protokol WireGuard → Generate →
tombol "Cabut & Generate Ulang" muncul → klik → confirm popup asli → hasil
one-liner fetch+import baru tampil, TANPA 500. Dikonfirmasi juga di DB
(akun lama berubah `revoked`, akun baru `active`) dan di log (tidak ada
entri error/warning baru selama window itu).

**Amendment kelima (verifikasi mendalam terhadap NAS Mikrotik produksi asli
`test-x86-bajastu`, RouterOS 7.11, via `RouterOsApiGateway` — bukan simulasi,
ditutup sebelum tag)**: 3 laporan baru dari test langsung ke router asli
setelah Amendment ketiga/keempat (fetch+import). Untuk pertama kalinya di
sprint ini, seluruh siklus test→diagnosis→perbaikan→verifikasi ulang
dilakukan otonom lewat API langsung ke router produksi (bukan minta admin
tes manual berulang), dengan `/system/script` sebagai pengganti paste
terminal manual dan `tcpdump`/`tshark` di level container maupun host untuk
bukti paket nyata.

1. **OpenVPN — bug nyata, syntax error saat import certificate+private key**:
   `MikrotikScriptGenerator::openVpnScript()` sejak awal meng-embed konten
   PEM certificate/key sebagai teks mentah di badan script `.rsc` (awalnya
   dimaksudkan cuma sebagai komentar instruksional untuk upload manual, tapi
   fetch+import Amendment ketiga mengeksekusi SELURUH isi file sebagai
   command RouterOS) — baris PEM mentah bukan syntax command yang valid,
   `/import` gagal begitu certificate/key sungguhan terlibat. Diperbaiki
   dengan mendesain ulang, bukan sekadar membetulkan escaping: setiap file
   (`ca.crt`/`client.crt`/`client.key`) sekarang punya token
   download-sekali-pakai sendiri (mekanisme sama dengan
   `ScriptDownloadTokenService`), diambil via `/tool fetch` terpisah tepat
   sebelum `/certificate import` — tanpa langkah manual tambahan buat admin,
   dan PEM tidak pernah lagi terlihat di browser (private key kini "shown
   once" sama seperti WireGuard). **Diverifikasi nyata**: script dijalankan
   via API di router asli, tuntas tanpa syntax error, kedua certificate
   berhasil ter-import (`private-key=true` pada client cert), file sementara
   di router bersih terhapus.
   - **Bonus temuan saat verifikasi konektivitas nyata (di luar scope
     laporan awal, tapi memblokir hal yang sedang diverifikasi)**: server
     OpenVPN mewajibkan `tls-crypt`, padahal fitur itu baru ada di RouterOS
     mulai 7.17rc3 — router produksi ini (dan kemungkinan besar mayoritas
     RouterOS 7.x yang sudah deployed) masih di bawah versi itu, membuat
     server secara struktural TIDAK MUNGKIN cocok dengan client manapun yang
     lebih lama. Dilepas dari `docker/openvpn/server.conf.template` (kembali
     ke TLS berbasis certificate murni, tanpa lapisan HMAC tambahan
     tls-crypt/tls-auth — tls-auth juga tidak dipakai karena RouterOS's
     `/interface ovpn-client add` tidak punya parameter inline untuknya).
     Setelah dilepas, real TLS handshake sukses (`VERIFY OK`) — tapi
     ditemukan gejala baru (reconnect loop dengan `ECONNREFUSED` di sisi
     server) yang **belum diselesaikan**, di luar cakupan laporan asli;
     dihentikan investigasinya di titik ini untuk fokus ke 2 bug yang
     eksplisit dilaporkan.
2. **WireGuard — 2 bug nyata ditemukan berurutan, keduanya sekarang
   terverifikasi teratasi total secara end-to-end**:
   - Bug pertama: `allowed-address` pada peer RouterOS **tidak otomatis
     mengisi routing table** — bertentangan dengan asumsi lama di CLAUDE.md
     ("allowed-address sudah cukup jadi isolasi routing"). Dibuktikan
     langsung via `/ip route print`: satu-satunya rute ke IP internal
     FreeRADIUS adalah rute lama milik interface OpenVPN, tidak aktif.
     Diperbaiki dengan menambahkan `/ip route add` eksplisit di
     `wireGuardScript()`, sama seperti OpenVPN/L2TP.
   - Bug kedua, jauh lebih dalam — ditemukan lewat binary-search sistematis
     (bukan tebakan) setelah bug pertama ternyata belum cukup: ping masih
     100% gagal walau rute sudah benar dan handshake WireGuard sukses.
     Serangkaian tes isolasi (`allowed-address` permisif vs sempit,
     kombinasi sisi client/server, address `/24` vs `/32`) mempersempit
     masalah ke satu variabel: `AllowedIPs` di **sisi server** — hanya
     `0.0.0.0/0` yang berhasil, subnet manapun lain (termasuk yang secara
     teori seharusnya cocok) gagal. `rx` byte counter naik (paket
     terenkripsi diterima & valid secara kriptografi) tapi `tx` statis dan
     0 paket pernah muncul di `wg0` — bukti paket dibuang SETELAH decrypt.
     Root cause sebenarnya: **NAT rule di router produksi ini sendiri**
     (`"NAT KE ANTEN"`, `src-address-list=!NO-NAT`, tanpa batasan interface
     keluar) ikut menerapkan source-NAT ke traffic tunnel WireGuard kita
     karena subnet VPN (`172.23.194.0/24`/`172.23.195.0/24`/`172.23.196.0/24`)
     tidak terdaftar di address-list `NO-NAT` router — source paket berubah
     dari `172.23.195.2` jadi alamat publik NAT router SEBELUM sempat
     dienkripsi, sehingga tidak pernah cocok `AllowedIPs` server manapun
     selain yang benar-benar permisif. **Bukan bug di kode/config BOSS App
     sama sekali** — diperbaiki di firewall router (ketiga subnet VPN
     ditambahkan ke `address-list NO-NAT` via API, atas persetujuan
     eksplisit sebelum mengubah konfigurasi produksi). **Diverifikasi nyata,
     end-to-end, dengan config production penuh** (bukan test permisif):
     script digenerate lewat `VpnScriptService` resmi, `allowed-address=/32`
     seperti semestinya — hasil 6/6 paket ping diterima, 0% packet loss,
     RTT ~6ms.
3. **L2TP/IPsec — `NO-PROPOSAL-CHOSEN` benar-benar teratasi, tapi
   ditandai sebagai known limitation, bukan selesai**: root cause SEBENARNYA
   bukan soal cipher/hash/lifetime yang diduga sebelumnya (Amendment
   ketiga) — ditemukan lewat log internal `charon` sendiri, yang sebelumnya
   tidak pernah bisa dibaca sama sekali (charon default log ke syslog,
   container ini tidak punya syslog daemon; ditambahkan `syslogd` permanen
   di entrypoint untuk observability). Log itu menunjukkan
   `"no IKE config found for 172.28.0.13...144.79.52.0"` — charon menolak
   SEBELUM sempat mengevaluasi satu pun proposal cipher, karena `left=` di
   `ipsec.conf` di-pin ke IP publik server sementara Docker men-DNAT paket
   masuk menjadi IP internal container sebelum sampai ke charon, jadi
   definisi `conn` tidak pernah cocok apa pun. Diperbaiki dengan
   `left=%any` — pola standar untuk IPsec responder di belakang
   NAT/port-forward container. **Diverifikasi nyata**: IKE_SA + CHILD_SA
   established dan STABIL 1+ menit dengan DPD (Dead Peer Detection) sukses
   bolak-balik berkali-kali — `NO-PROPOSAL-CHOSEN` tuntas hilang. Perbaikan
   `address-list NO-NAT` (poin 2) juga dicoba terhadap L2TP dengan hipotesis
   akar masalah yang sama — **tidak berdampak**, dikonfirmasi lewat capture
   ulang di level host.
   **Tapi**: lapisan L2TP/PPP di ATAS IPsec yang sudah established itu tidak
   pernah benar-benar terlindungi. `tcpdump` di level **host** (interface
   publik server, bukan di dalam container — sesuai saran eksplisit
   sebelum dicoba, untuk menyingkirkan kemungkinan Docker bridge/NAT
   sebagai penyebab) membuktikan RouterOS mengirim paket kontrol L2TP
   (port 1701, `SCCRQ`) sebagai **UDP polos, bukan terbungkus ESP** — 0
   paket ESP ditemukan di seluruh capture, meski IKE Phase 1 dan Phase 2
   (Quick Mode) sukses negosiasi tepat sebelumnya. Docker networking
   (bridge vs host mode) dan NAT eksplisit disingkirkan sebagai penyebab —
   paket sampai utuh identik di level host maupun container. Root cause
   sebenarnya ada di bagaimana mode sederhana RouterOS
   `l2tp-client use-ipsec=yes` menerapkan (atau gagal menerapkan) Security
   Policy Database-nya sendiri secara internal untuk melindungi trafik
   L2TP — di luar kendali/kemampuan diagnosis dari sisi server BOSS App.
   **Keputusan eksplisit**: L2TP/IPsec ditandai sebagai *known limitation*
   dan sprint v0.6.3 tetap ditutup — OpenVPN dan WireGuard sudah fully
   functional dan terverifikasi nyata di router produksi yang sama, L2TP
   tidak memblokir keduanya. Detail arsitektur lengkap ada di
   `docs/ROADMAP.md`.

## v0.6.2 — VPN Server Node #1 (OpenVPN) & Hub-and-Spoke Routing ke FreeRADIUS

- Container Docker baru `openvpn` — dibangun sendiri dari `alpine:3.20` +
  paket `openvpn` (2.6.20) + `easy-rsa` (3.1.7) via apk, bukan image
  komunitas (`kylemanna/openvpn` terakhir update Des 2020). Satu-satunya
  service (selain `boss-nginx` 80/443) dengan host port publik: UDP 1194
  (BOSS-010, exception disengaja — NAS Mikrotik asli connect dari luar).
- `boss-network` sekarang punya IPAM subnet tetap (`172.28.0.0/24`) dan
  `freeradius` di-pin ke IP statis `172.28.0.10` — locked decision "FreeRADIUS
  selalu diakses di SATU IP internal tetap dari sisi Mikrotik" butuh alamat
  yang tidak pernah drift saat container di-recreate.
- Tabel baru `vpn_servers` (platform-level, bukan tenant/reseller-scoped —
  pola sama `payment_gateway_settings`) dan `vpn_accounts` (`cert_serial`,
  `revoked_at` untuk CRL, unique `internal_ip`). Tabel baru `vpn_ip_pool`
  (tidak diminta eksplisit di spec awal, ditambahkan karena alokasi IP
  race-condition-safe butuh pool of rows to lock — pola sama `odp_ports`
  v0.5.0, bukan tabel IP pool di spec, keputusan implementasi) —
  `VpnServer::provisionIpPool()` (via `App\Support\CidrRange`, unit-tested)
  meng-generate satu baris per host address usable di `subnet_cidr`.
- `App\Services\Network\VpnProvisioningService::provision()`/`revoke()` —
  alokasi IP di dalam `lockForUpdate()` transaction (cepat, commit dulu),
  BARU jalankan `easyrsa build-client-full` (lambat, di luar transaction —
  gagal di sini roll back alokasi IP, bukan menyisakan row "active" palsu).
  `revoke()` = `easyrsa revoke` + `gen-crl`, TANPA restart/reload daemon
  openvpn — dikonfirmasi dari dokumentasi resmi OpenVPN: CRL dibaca ulang
  otomatis di setiap koneksi baru/renegosiasi TLS.
- Arsitektur provisioning: `boss-app` dan `openvpn` berbagi volume Docker
  bernama (`vpn_pki`, `vpn_ccd`) — Laravel menjalankan `easyrsa` langsung
  (Process facade) terhadap PKI yang sama yang di-bootstrap container
  `openvpn` saat first boot, BUKAN docker exec dari host (tidak ada Docker
  socket yang di-mount ke `boss-app`).
- Isolasi jaringan hub-and-spoke: `client-config-dir` untuk `ifconfig-push`
  IP statis per-NAS, SATU `push route` global ke IP FreeRADIUS saja, iptables
  `FORWARD` default-DROP dengan satu `ACCEPT` eksplisit ke IP FreeRADIUS,
  MASQUERADE trafik keluar dari subnet VPN — NAS pelanggan tidak bisa reach
  `boss-postgresql`/`boss-redis`/container lain di `boss-network`.
- REST API `POST /nas/{nas}/vpn-account` (provision) dan
  `POST /vpn-accounts/{vpn_account}/revoke` — otorisasi diturunkan dari
  `NasPolicy::manage()` terhadap NAS pemilik (tidak ada `VpnAccountPolicy`
  terpisah, `vpn_accounts` tidak punya `reseller_id`/`tenant_id` sendiri,
  pola sama `odp_ports`/`work_order_photos`).

Bug nyata ditemukan & diperbaiki saat verifikasi end-to-end (bukan cuma
dites dengan mock — dibuktikan lewat `easyrsa` sungguhan dari container
`boss-app` terhadap PKI yang di-bootstrap container `openvpn`, provisioning
sungguhan lewat HTTP-equivalent service call, dan revoke sungguhan):
- `easyrsa init-pki` melakukan hard-reset (`rm -rf`) pada `--pki-dir` itu
  sendiri — gagal dengan `Resource busy` kalau `--pki-dir` PERSIS di root
  mount point volume Docker. Diperbaiki dengan memindahkan PKI ke
  subdirectory (`pki-data/pki`), bukan langsung di root mount.
- Volume shared butuh permission permisif (`chmod -R 0777` di entrypoint
  `openvpn`) karena `boss-app` (php-fpm worker jalan sebagai `www-data`,
  UID beda dari proses root di container `openvpn`) perlu menulis
  cert/key baru ke direktori yang sama.
- `Process::fake()` di Laravel mencocokkan pattern terhadap
  `Symfony\Process::getCommandline()` yang MENGUTIP tiap argumen
  (`'easyrsa' '--pki-dir=...'`) — pattern wildcard harus diawali `*`
  (`'*easyrsa*build-client-full*'`), bukan `'easyrsa*...'` polos, atau
  fake tidak pernah ter-trigger dan test diam-diam menjalankan proses asli.
- `docker/php/Dockerfile` menambah `openssl`/`easy-rsa` ke layer `apk add`
  yang SAMA dengan `linux-headers` (v0.6.1) — meng-invalidate cache layer
  `docker-php-ext-install sockets` sesudahnya, memicu kompilasi ulang dari
  nol (~7 menit) walau tidak ada perubahan pada extension itu sendiri.
  Bukan bug fungsional, dicatat sebagai pelajaran Dockerfile-layering untuk
  sprint berikutnya (taruh paket baru di layer terpisah kalau ingin cache
  tetap valid).

Out-of-scope v0.6.2, di-declare eksplisit sebagai backlog: WireGuard/L2TP
(v0.6.3), script generator Mikrotik siap-paste (v0.6.3), multi-node pool +
health-check otomatis + failover (v0.6.4, `vpn_servers` baru 1 baris manual
lewat tinker — belum ada `VpnServerController`/REST API karena CRUD-nya baru
jadi kebutuhan nyata saat multi-node), dynamic virtual server + CoA
(v0.6.5), force-disconnect sesi VPN yang sudah terkoneksi saat revoke (perlu
OpenVPN management interface, belum dibangun), Docker healthcheck untuk
`openvpn` (monitoring VPN node sungguhan adalah pekerjaan v0.6.4).

Detail lengkap ada di CLAUDE.md bagian "VPN Server Node #1 (v0.6.2)".

## v0.6.1 — FreeRADIUS Core & NAS Management

- Container Docker baru `freeradius-db` (Postgres 16, terpisah dari
  `boss-postgresql` — BOSS-009: `radius_db` logically separated dari
  `boss_db`, no cross-database join) dan `freeradius`
  (`freeradius/freeradius-server:3.2.10-alpine`, build custom di
  `docker/freeradius/`). Tidak ada host port dipublikasikan (BOSS-010) —
  belum ada NAS/VPN yang perlu menjangkaunya dari luar sampai v0.6.2.
- Schema PostgreSQL standar FreeRADIUS (`radcheck`, `radreply`,
  `radgroupcheck`, `radgroupreply`, `radusergroup`, `radacct`,
  `radpostauth`, `nas`, `nasreload`) diambil dari upstream
  `FreeRADIUS/freeradius-server` v3.2.x dan di-load otomatis lewat
  `docker-entrypoint-initdb.d` saat `freeradius-db` pertama kali start.
- `rlm_sql` diarahkan ke `radius_db` lewat `docker/freeradius/mods-available/sql`
  (dialect `postgresql`, driver `rlm_sql_postgresql`, connection string dari
  `$ENV{RADIUS_DB_*}`) — diverifikasi end-to-end: `radcheck` row manual ->
  `radclient auth` -> `Access-Accept` sungguhan, bukan cuma "container tidak
  crash".
- Tabel baru `nas` (di `boss_db`, BUKAN tabel `nas` bawaan FreeRADIUS di
  `radius_db` — nama sama, database beda) — inventaris router Mikrotik:
  `reseller_id` nullable (pola sama dengan `whatsapp_sessions`/
  `work_orders`), `mikrotik_ip` nullable sengaja (baru terisi lewat VPN
  provisioning v0.6.2), `api_password`/`radius_secret` encrypted,
  `auth_port`/`acct_port` nullable sampai port allocator v0.6.5,
  `coa_port` default 3799.
- `App\Services\Network\NasService` (CRUD + `testConnection()`) dan
  `App\Services\Network\Contracts\RouterOsGateway` (interface,
  implementasi nyata `RouterOsApiGateway` pakai
  `evilfreelancer/routeros-api-php` — butuh `ext-sockets`, baru
  ditambahkan ke `docker/php/Dockerfile`) — dibuat sebagai boundary
  eksplisit supaya test tidak perlu router sungguhan (bind fake
  implementasi lewat container, tidak ada `Http::fake()` karena
  protokolnya raw socket, bukan HTTP).
- REST API `GET/POST /nas`, `GET/PUT/DELETE /nas/{nas}`,
  `POST /nas/{nas}/test-connection` — reseller-scoped lewat
  `BelongsToResellerScope` + `NasPolicy` (pola identik
  Odp/Technician/WorkOrder Policy v0.5.0). Response tidak pernah
  menyertakan nilai asli `api_password`/`radius_secret`.
- Permission `nas.view`/`nas.manage` (super_admin-only, pola sama dengan
  `seedInstallationPermissions()`).
- `docs/ROADMAP.md` diperbarui: `v0.6.0` (FreeRADIUS, satu versi) dipecah
  jadi 5 sub-versi `v0.6.1`-`v0.6.5` untuk mereplikasi pola VPN+RADIUS
  MixRadius V3.2; `v0.6.1` (Mobile Self-Service Portal) yang lama digeser
  ke `v0.11.0`.

**Gap infrastruktur yang ditemukan & diperbaiki saat membangun** (sama
kelas dengan gap `.env`/build-image di sprint-sprint sebelumnya — bukan
scope baru, murni supaya container ini benar-benar bisa dipakai):
- `ext-sockets` gagal dikompilasi di image `php:8.4-fpm-alpine` karena
  Alpine tidak menyertakan kernel header lengkap secara default
  (`linux/sock_diag.h` hilang) — diperbaiki dengan menambah paket
  `linux-headers` ke `docker/php/Dockerfile` sebelum
  `docker-php-ext-install sockets`.
- `rlm_sql_postgresql.so` di image `freeradius/freeradius-server:*-alpine`
  gagal instantiate (`Error loading shared library libpq.so.5`) karena
  base image Alpine tidak menyertakan runtime client Postgres —
  diperbaiki dengan `apk add libpq` di `docker/freeradius/Dockerfile`.
- Sintaks substitusi environment variable FreeRADIUS yang benar di
  config statis (`mods-available/sql`) adalah `$ENV{VAR}` (gaya Perl),
  **bukan** `${env:VAR}` — percobaan pertama gagal dengan error
  `Reference "${env}" not found` karena `${...}` di FreeRADIUS adalah
  sintaks "rujuk nilai config lain", bukan environment-variable
  interpolation.

Detail lengkap ada di CLAUDE.md bagian "FreeRADIUS Core & NAS Management
(v0.6.1)".

## v0.5.0 — Installation (Work Order Teknisi)

- Tabel baru `odps` — inventory ODP (`code` unik per tenant, `latitude`/
  `longitude`, `total_ports`), `reseller_id` nullable (null = milik ISP A
  langsung), index `(latitude, longitude)` untuk query lokasi.
- Tabel baru `odp_ports` — satu baris per port fisik ODP (`port_number`,
  `status`: `available`/`reserved`/`used`/`damaged`, `subscription_id`
  nullable), unique `(odp_id, port_number)`. `Odp::provisionPorts()`
  auto-generate port 1..`total_ports` saat ODP dibuat lewat API (bukan
  model event — sengaja, supaya tidak collision dengan factory test).
- Tabel baru `technicians` — data teknisi (`user_id`, `name`, `phone`,
  `status`), `reseller_id` nullable sama pola dengan modul lain.
- Tabel baru `work_orders` — `reseller_id` didenormalisasi dari
  subscription, `status` state machine 8 nilai (`pending_odp_check` ->
  `pending_verification`/`odp_unavailable` -> `ready` -> `assigned` ->
  `in_progress` -> `completed`, `cancelled` dari status non-terminal
  manapun), `equipment_ready` placeholder manual (modul stok riil belum
  ada).
- Tabel baru `work_order_devices` (device_type ont/router/ap, mac_address,
  serial_number) dan `work_order_photos` (4 jenis wajib: odp/ont_device/
  signal_strength/house_front, unique 1 foto per jenis per WO).
- `App\Services\Installation\OdpLocatorService::findNearestAvailable()` —
  Haversine formula raw SQL (tanpa PostGIS), diverifikasi jalan identik di
  SQLite (test) dan Postgres, scoped ke reseller yang sama dengan customer
  (atau direct), hanya port `available`.
- `App\Services\Installation\WorkOrderService` — `createFromSubscription()`
  (cari & reserve port terdekat, atau `odp_unavailable` kalau tidak ada),
  `verify()`, `assignTechnician()`, `start()`, `complete()` (validasi
  legalitas transisi LEBIH DULU, baru validasi 4 foto + minimal 1 device
  lengkap; port -> `used`), `cancel()` (port kembali `available`).
  `App\Services\Installation\WorkOrderPhotoService::store()` — satu foto
  per jenis, replace file lama kalau upload ulang.
- REST API lengkap: `GET/POST /odps`, `GET/POST /technicians`,
  `GET/POST /work-orders` + `POST /subscriptions/{id}/work-order` +
  `verify`/`assign`/`start`/`photos`/`devices`/`complete`/`cancel`, semua
  scoped `reseller.context` + `OdpPolicy`/`TechnicianPolicy`/
  `WorkOrderPolicy` (permission `odps.*`/`technicians.*`/`work_orders.*`
  super_admin-only, reseller owner/staff lewat `reseller_users`
  membership).
### Deferred
- Modul stok/inventory barang riil (equipment_ready tetap manual)
- Notifikasi WhatsApp otomatis untuk event work order
- Komponen UI scan kamera html5-qrcode (endpoint API sudah siap)

## v0.4.0 — WhatsApp Gateway (Baileys, multi-sesi per reseller)

- Tabel baru `whatsapp_sessions` — satu sesi WhatsApp per reseller (`reseller_id`
  nullable, null = sesi "direct" ISP), `status` (`qr_pending`/`connected`/
  `disconnected`/`logged_out`), `qr_code_data`, `last_connected_at`/
  `last_disconnected_at`. Partial unique index terpisah untuk
  `reseller_id IS NOT NULL` (satu sesi per reseller) dan `reseller_id IS NULL`
  (maksimal satu sesi direct per tenant).
- Tabel baru `whatsapp_message_templates` — template per `event_type` (4 jenis:
  `invoice_due_reminder`, `payment_received`, `customer_registered`,
  `customer_suspended_reminder`), `reseller_id` nullable (null = default
  ISP-level), `is_active`. Partial unique index sama polanya dengan sessions.
- Tabel baru `whatsapp_message_logs` — audit trail tiap pesan (`customer_id`,
  `invoice_id` nullable, `phone_number`, `event_type`, `rendered_content`,
  `status`, `failed_reason`, `attempts`), index dedup `(invoice_id, event_type,
  created_at)` dan `(customer_id, event_type, created_at)` untuk guard
  reminder harian.
- Tabel baru `whatsapp_gateway_settings` — singleton platform-level (rate
  limit delay/batch size/jeda batch/jadwal harian), posisi sama seperti
  `payment_gateway_settings`.
- `App\Support\WhatsappHmac` — HMAC-SHA256 signing/verification internal
  Laravel<->Node, timestamp + toleransi 5 menit (beda dari verifikasi
  static-token Xendit).
- `App\Services\Whatsapp\WhatsappGatewayService::buildAndQueue()` — resolve
  template (override reseller > default ISP), render variabel, simpan log,
  dispatch `SendWhatsappMessageJob` ke queue `whatsapp-{session_key}`.
  `App\Services\Whatsapp\WhatsappTemplateService::resolve()`/`render()`.
  `App\Services\Whatsapp\WhatsappSessionService` — verifikasi webhook +
  reconciliation hourly dari Node gateway.
- `App\Jobs\SendWhatsappMessageJob` — rate limit delay (random 5-10 detik),
  retry max 3x dengan backoff 30s/2menit/5menit, POST HMAC-signed ke Node
  service.
- 3 scheduled command baru: `whatsapp:send-due-reminders` (H-5 & H-0, dedup
  harian, self-gate terhadap `daily_schedule_times`), `whatsapp:send-suspended-reminders`
  (harian selama status masih suspend, berhenti otomatis), `whatsapp:check-session-health`
  (hourly, reconcile status sesi dari Node gateway).
- Hook terintegrasi ke `InvoiceService::markPaid()` (event `payment_received`,
  signature TIDAK diubah) dan `RegistrationService::register()` (event
  `customer_registered`, di luar `DB::transaction()`).
- Endpoint publik `POST /api/v1/whatsapp/webhook/session-status` (HMAC
  verified, always 200) + REST API sessions/templates/message-logs/settings
  (scoped via `BelongsToResellerScope` + `WhatsappSessionPolicy`/
  `WhatsappMessageTemplatePolicy`/`WhatsappMessageLogPolicy`/
  `WhatsappGatewaySettingsPolicy`).
- Node.js service baru `whatsapp-gateway/` (container `whatsapp-gateway`,
  internal-only, tanpa host port) — multi-session Baileys
  (`@whiskeysockets/baileys`), auth state per sesi di volume
  `whatsapp-gateway/auth_state/{session_key}/`, endpoint
  `/sessions/{key}/send|qr|health` + `/sessions` (list), webhook balik ke
  Laravel di setiap `connection.update`.
- Container baru `boss-whatsapp-worker` — `queue:work` dengan daftar queue
  dinamis dari `whatsapp:queue-names` (artisan command baru), restart tiap 5
  menit (pola sama dengan `boss-scheduler`) supaya sesi reseller baru
  otomatis ikut terdengar.
- Livewire `Whatsapp\WhatsappGatewayIndex` (`/whatsapp-gateway`, cluster
  sidebar baru "Komunikasi") — reseller: tab Konfigurasi (QR)/Template/
  Antrian scoped ke reseller sendiri; ISP admin: tab Overview semua sesi +
  Template default + Antrian gabungan + Rate Limit settings.
- Permission baru: `whatsapp_gateway.view`/`.manage`,
  `whatsapp_gateway_settings.view`/`.manage` (super_admin-only — reseller
  owner/staff diotorisasi lewat `reseller_users` membership, bukan Spatie
  permission, sama pola dengan reseller/tax engine).
- `payment_gateway:import-env`-style one-time command:
  `payment-gateway:import-env` tidak berubah; tambahan `WHATSAPP_GATEWAY_URL`/
  `WHATSAPP_GATEWAY_HMAC_SECRET` di `.env.example` (root + `app/`) dan
  `config('services.whatsapp_gateway.*')`.
### Deferred
- Two-way messaging + integrasi Chatwoot
- Overdue reminder berulang (sengaja tidak ada sama sekali)
- Notifikasi work order teknisi / outage alert
- Rate limit setting per-reseller (sprint ini global saja)
- Auto-refund, retry payment flow, partial payment/cicilan, proration
  subscription (tetap deferred dari v0.3.4/v0.3.5)

## v0.3.5 — Payment Gateway (Xendit)

- Tabel baru `payments` — satu baris per attempt/instance pembayaran
  (`tenant_id`, `invoice_id`, `xendit_reference_id`, `channel_type`, `amount`,
  `status`, `paid_at`, `raw_response` jsonb), tenant-scoped via
  `BelongsToTenant`.
- Tabel baru `payment_webhook_logs` — audit trail platform-wide (sengaja
  TANPA `tenant_id`/FK), `xendit_event_id` UNIQUE sebagai backstop
  idempotency, `payload` jsonb, `signature_valid`, `processed_at`,
  `processing_result`.
- Enum baru: `App\Enums\PaymentStatus` (`pending`, `paid`, `failed`,
  `expired`), `App\Enums\WebhookProcessingResult` (`applied`, `duplicate`,
  `rejected_signature`, `rejected_amount_mismatch`, dan satu tambahan
  `rejected_invoice_not_found` di luar daftar awal — kegagalan yang genuinely
  berbeda dari amount-mismatch, kolomnya string biasa jadi aman ditambah).
  (`App\Enums\PaymentChannelType`, 3 case tetap, sempat ada di Fase A-G —
  **dihapus lagi di Fase H**, lihat bawah.)
- `App\Services\Payment\XenditGatewayService` — wrapper HTTP murni ke
  `https://api.xendit.co` (Basic Auth pakai secret key), tidak tahu apa-apa
  soal `Invoice`. Constructor menolak beroperasi (`RuntimeException`) kalau
  `XENDIT_IS_PRODUCTION=true` tapi `APP_ENV` bukan `production` — guard
  eksplisit supaya sandbox key tidak bisa "ke-upgrade" diam-diam.
- `App\Services\Payment\PaymentService` — `createPaymentFor()` (invoice +
  channel → panggil gateway sesuai channel → simpan `Payment`) dan
  `handleWebhook()` dengan urutan tetap: (1) verifikasi `x-callback-token`
  lebih dulu, tolak sebelum payload disentuh sama sekali kalau invalid;
  (2) dalam DB transaction, cek idempotency by `xendit_event_id`
  (`lockForUpdate`); (3) exact match `payload.amount` vs
  `invoice.grand_total` (bukan `>=`); (4) baru panggil
  `InvoiceService::markPaid()`. `invoice_number` (BUKAN `id` numerik) dipakai
  sebagai Xendit `external_id` di semua channel.
- Webhook endpoint publik `POST /api/v1/webhooks/xendit` (di luar
  `auth:sanctum`, throttle 60/menit) — SELALU balas HTTP 200 apa pun hasil
  internalnya (applied/duplicate/rejected/error) supaya Xendit berhenti
  retry; error internal ditangkap dan dicatat, tidak pernah bocor ke
  response.
- REST API baru: `GET/POST /api/v1/invoices/{invoice}/payments` (list +
  create payment attempt), permission `invoice.manage`/`invoice.view`
  existing dari v0.3.4, di dalam grup `reseller.context`.
- Livewire `Billing\ReconciliationReport` (`/payment-reconciliation`) —
  laporan read-only: invoice berstatus `paid` dicocokkan dengan `Payment`
  ber-status `paid` miliknya (baris tanpa match ditandai "ANOMALY"), plus
  daftar `PaymentWebhookLog` yang hasilnya bukan `applied`. **Tidak ada
  tombol perbaikan otomatis** — sengaja murni laporan/audit.
- **Keputusan arsitektur yang dikonfirmasi eksplisit oleh Agung**: endpoint
  manual `PATCH /api/v1/invoices/{invoice}/paid` (dari v0.3.4) **dipertahankan
  apa adanya**, meski tidak melalui verifikasi pembayaran sama sekali.
  Konsekuensi: `InvoiceService::markPaid()` sekarang punya dua jalur
  pemanggil yang sah — endpoint admin manual (tanpa verifikasi) dan webhook
  Xendit (dengan verifikasi penuh: signature + idempotency + exact amount
  match). Jangan "bersihkan" ini secara sepihak di sprint berikutnya; lihat
  `docs/ROADMAP.md` dan CLAUDE.md untuk detail.
- Config: `XENDIT_IS_PRODUCTION` tetap di root `.env`/`config('services.xendit.*')`
  (guard sandbox, lihat di bawah) — `XENDIT_SECRET_KEY`/`XENDIT_CALLBACK_TOKEN`
  di root `.env` HANYA dipakai untuk migrasi awal (lihat Fase H), bukan lagi
  sumber runtime. Tidak pernah hardcoded atau ter-commit.

### Fase H (amendment, sebelum commit pertama — bukan sprint baru)

Penambahan scope dikonfirmasi Agung sebelum branch ini di-commit: UI
Pengaturan Payment Gateway ala referensi MikRadius. Mengubah dua kontrak inti
dari Fase A-G di atas (bukan cuma penambahan):

- Tabel baru `payment_gateway_channels` — katalog channel Xendit yang bisa
  diatur admin (`code` unique, `label`, `category` enum
  `App\Enums\PaymentGatewayChannelCategory`, `enabled`). Diseed lewat
  `PaymentGatewayChannelSeeder` (18 channel: 9 bank VA, credit card, 2 retail
  outlet, 4 e-wallet, QRIS, Xendit Invoice — semua `enabled=false` sampai
  admin mengaktifkan lewat UI).
- Tabel baru `payment_gateway_settings` — singleton platform-level (id=1,
  dijaga oleh service, bukan constraint DB) menyimpan `xendit_secret_key`/
  `xendit_webhook_token` (cast `encrypted`), `is_configured`, `updated_by`.
- **`payments.channel_type` bukan lagi enum tetap** — `App\Enums\PaymentChannelType`
  dihapus. Sekarang varchar yang mereferensi `payment_gateway_channels.code`
  (tanpa hard FK, divalidasi di `PaymentService`). Migration
  `remap_legacy_payment_channel_types` membackfill baris lama Fase A-G
  (`virtual_account`→`BRI_VA`, `qris`→`QRIS`, `invoice`→`XENDIT_INVOICE`) agar
  tetap valid di bawah vocabulary baru — kolomnya sendiri sudah varchar sejak
  awal, jadi ini murni migrasi data, bukan alter tipe kolom.
- `App\Services\Payment\PaymentGatewaySettingsService` — satu-satunya
  pembaca/penulis `payment_gateway_settings`/`payment_gateway_channels` yang
  sah: `getSecretKey()`/`getWebhookToken()` (di-cache), `update()` (transaction,
  blank submit tidak menghapus value tersimpan, invalidate cache setelah
  commit), `isChannelEnabled()`/`enabledChannels()`.
- `XenditGatewayService`/`PaymentService::verifySignature()` **direfactor**
  untuk membaca secret/token dari service di atas, bukan lagi
  `config('services.xendit.secret_key'/'callback_token')`. `PaymentService::createPaymentFor()`
  sekarang menolak channel yang ada di katalog tapi `enabled=false`, dan
  channel kategori `ewallet`/`retail_outlet`/`credit_card` (ada di katalog
  untuk checklist UI, TAPI belum ada integrasi API Xendit-nya — hanya
  `bank_transfer_va`/`qris`/`invoice` yang benar-benar bisa dipakai sprint
  ini).
- Livewire `Settings\PaymentGatewaySettings` (`/settings/payment-gateway`,
  permission `payment_gateway_settings.manage`/`.view`, super_admin-only —
  lebih ketat dari `invoices.*`) — field API Secret/Webhook Token masked,
  tidak pernah render nilai asli (placeholder "tersimpan, diubah: {tanggal}"),
  submit kosong tidak mengubah value tersimpan, checklist channel per
  kategori, validasi minimal 1 channel aktif.
- Command manual `php artisan payment-gateway:import-env` — sekali jalan,
  mengimpor `XENDIT_SECRET_KEY`/`XENDIT_CALLBACK_TOKEN` lama dari `.env` ke
  `payment_gateway_settings`. Tidak dijalankan otomatis dari migration/seeder.
- Tests tambahan: `PaymentGatewaySettingsServiceTest` (roundtrip encrypted,
  cache invalidation, singleton, sync channel), `PaymentGatewaySettingsLivewireTest`
  (masked placeholder, blank-submit tidak menghapus, validasi minimal 1
  channel, non-admin forbidden), plus 1 test baru di `PaymentServiceSafetyTest`
  (channel disabled ditolak). Total 149/149 test suite passing.

- **Di luar scope, sengaja di-declare sebagai backlog**: notifikasi
  pembayaran via WhatsApp/Baileys (nunggu v0.4.0), auto-refund otomatis,
  retry payment flow (UI maupun service), partial payment/cicilan, proration
  subscription (masih deferred dari v0.3.4), dan integrasi Xendit API nyata
  untuk channel ewallet/retail_outlet/credit_card (baru terdaftar di katalog,
  belum bisa dipakai membuat payment).

## v0.3.4 — Invoicing Core

- Tabel baru `subscriptions` — plan berlangganan per customer.
  `reseller_id`/`reseller_package_pricing_id` **ada sejak migration pertama**
  (dependency wajib dari v0.3.2/v0.3.3, bukan retrofit): `reseller_id`
  didenormalisasi dari `customers.reseller_id` saat dibuat,
  `reseller_package_pricing_id` nullable (null untuk direct-retail — harga
  di-set manual di `monthly_amount`, karena tidak ada katalog `packages` ISP
  di codebase manapun). `billing_cycle_day` (1–31) jadi acuan tanggal jatuh
  tempo bulanan.
- Tabel baru `invoices` + `invoice_line_items`. Nomor invoice **per-reseller**
  (`INV/{kode_reseller}/{tahun}/{bulan}/{sequence 6 digit}`, `INV/DIRECT/...`
  untuk direct-retail — keputusan dikonfirmasi eksplisit sebelum migration
  ditulis) via `InvoiceNumberService` + tabel `invoice_number_sequences`
  (partial unique index Postgres, pola sama dengan `reseller_tax_policies`).
  `resellers.invoice_code` (kolom baru) auto-derive dari slug kalau admin
  tidak set manual.
- **State machine invoice** (`App\Enums\InvoiceStatus`, method
  `canTransitionTo()` mirip `CustomerStatus`): `draft → pending →
  (paid | overdue) → paid`; semua state non-terminal bisa `cancelled`;
  `paid`/`cancelled` terminal. Transisi tidak valid melempar
  `App\Exceptions\InvalidInvoiceStatusTransitionException` (pola sama
  dengan `InvalidStatusTransitionException` milik `Customer` di v0.2.0),
  bukan raw exception generik.
- **`InvoiceService::generateForPeriod()` memenuhi kontrak wajib v0.3.3**:
  memanggil `TaxCalculationService::calculateForAmount()` lalu
  `::writeLedgerEntry()` — `grand_total` invoice selalu dari
  `$breakdown->grandTotal`, tidak pernah dihitung ulang manual.
  Idempoten terhadap subscription+periode yang sama (guard di dalam
  transaction, unique constraint DB sebagai backstop terakhir).
- **Command terjadwal baru** (`boss-scheduler`, daily): `GenerateDueInvoices`
  (generate invoice untuk subscription aktif yang jatuh tempo H-7 — angka 7
  dikonfirmasi eksplisit sebagai keputusan, bukan hardcode diam-diam, via
  flag `--lead-days`) langsung auto-issue ke `pending`; `MarkOverdueInvoices`
  (ubah `pending` yang `due_date`-nya lewat jadi `overdue`).
- **Keputusan arsitektur yang dikonfirmasi eksplisit sebelum migration**
  (sesuai instruksi sprint): nomor invoice per-reseller (bukan sequence
  global), due date dari `billing_cycle_day` + generate H-7, overdue via job
  otomatis harian.
- **Known limitation, sengaja di-defer** (bukan silent gap — lihat
  `docs/ROADMAP.md`): tidak ada proration. Subscription yang mulai/berhenti
  di tengah periode billing tetap ditagih penuh satu periode.
- **Dua bug nyata ditemukan & diperbaiki selama development**:
  1. `InvoiceService::generateForPeriod()` awalnya mengembalikan
     `$invoice->fresh()` — method itu membuat instance BARU dari database,
     me-reset properti `wasRecentlyCreated` Eloquent ke `false` walau baris
     baru saja dibuat. Ini membuat `GenerateDueInvoices` gagal mendeteksi
     invoice yang baru dibuat dan tidak memanggil `markPending()` (invoice
     tertinggal di status `draft`). Fix: pakai `->load()`, bukan `->fresh()`.
  2. **Bug lintas-driver yang meluas** (juga mempengaruhi kode v0.3.3 yang
     sudah ter-tag): kolom ber-cast `'date'` bisa tersimpan dengan sufiks
     waktu di SQLite (dipakai test suite) meski tidak di Postgres (dev/prod),
     membuat `->where('kolom', '<=', $tanggal->toDateString())` gagal cocok
     tepat di tanggal yang sama persis. Fix: `->whereDate(...)` di seluruh
     `TaxCalculationService`, `ResellerTaxPolicyService`,
     `RemittanceSummaryService`, `InvoiceService`, `MarkOverdueInvoices`,
     `TaxLedgerController`, `RemittanceSummaryController`. Detail lengkap +
     alasan supaya tidak terulang di sprint berikutnya: lihat `CLAUDE.md`
     bagian "Cross-database date comparison gotcha".
- **REST API**: `GET/POST /api/v1/subscriptions` +
  `PATCH .../suspend|reactivate|cancel`, `GET /api/v1/invoices` +
  `POST /api/v1/invoices/generate` + `PATCH .../pending|paid|cancel`.
  Permission baru `subscriptions.*`/`invoices.*` — **beda dari pola
  super_admin-only ketat di v0.3.2/v0.3.3**: role `billing` (ada sejak
  v0.1.0, belum pernah dapat permission apa pun) juga diberi akses, karena
  operasional invoice adalah pekerjaan harian staff billing, bukan
  keputusan level admin seperti konfigurasi reseller/kebijakan pajak.
  Reseller owner/staff tetap read-only lewat keanggotaan `reseller_users`
  (`SubscriptionPolicy`/`InvoicePolicy`), sama seperti modul lain.
- **Livewire UI**: `Billing\SubscriptionIndex` (CRUD + tombol Generate
  Invoice Now/suspend/reactivate/cancel), `Billing\InvoiceIndex` (filter
  status + tombol transisi sesuai state machine). Masuk cluster sidebar
  "Billing & Finance" yang sudah ada dari v0.3.3.
- Tests: 125/125 passing (119 existing + 6 file test baru mencakup generate
  invoice normal, generate dengan burden reseller `split`/`reseller_borne`,
  state machine lengkap, validasi no-duplicate per subscription+periode, dan
  command terjadwal — lihat `tests/Feature/Api/SubscriptionInvoiceApiTest.php`,
  `tests/Feature/Billing/`.

## v0.3.3 — Regulatory Tax Engine

- Tabel baru `tax_components` — katalog pajak/pungutan dinamis (`code`
  stable identifier mis. `PPN`/`BHP_USO`/`PPH_BADAN`, `name` boleh di-rename
  admin, `type` `percentage`/`fixed`, `rate`), effective-dated
  (`effective_from`/`effective_to`) — perubahan tarif **tidak pernah**
  mengubah baris lama, selalu insert baris baru dengan `effective_from`
  baru lewat `TaxComponentService::updateRate()`, menutup baris lama di
  `effective_to`. Riwayat tarif jadi audit trail permanen.
- Tabel baru `reseller_tax_policies` — siapa menanggung pajak apa
  (`burden`: `customer_borne`/`reseller_borne`/`split`, `split_ratio` %
  ditanggung customer kalau `split`), per reseller **atau** direct-retail
  (`reseller_id` nullable). Partial unique index Postgres (bukan constraint
  biasa — `reseller_id` nullable butuh dua index terpisah, `WHERE reseller_id
  IS [NOT] NULL`) mencegah duplikasi policy aktif untuk kombinasi
  tenant+reseller+component+effective_from yang sama.
- Tabel baru `reseller_tax_ledger` — baris pajak per transaksi, snapshot
  `rate_applied`/`burden_applied` saat kalkulasi (tidak berubah walau
  `tax_components`/`reseller_tax_policies` di-update kemudian — audit
  trail). `reference_type`/`reference_id` polymorphic generic **tanpa** FK
  constraint (akan diisi `App\Models\Invoice` dst mulai v0.3.4 — lihat
  kontrak integrasi di `CLAUDE.md`). `source` (`seeded`/`system`) membedakan
  data uji coba dari data asli nanti.
- Tabel baru `komdigi_remittance_summary` — agregat bulanan per
  reseller×tax_component (termasuk baris direct-retail terpisah, sama pola
  partial-unique-index dengan `reseller_tax_policies`), dibuat via
  `RemittanceSummaryService::generateForPeriod()`.
- **Service layer (BOSS-006)**: `TaxComponentService` (create, updateRate
  effective-dated, toggleActive), `ResellerTaxPolicyService` (setPolicy
  dengan validasi split_ratio 0–100, getActivePolicies dengan fallback
  reseller-specific → direct-retail), `TaxCalculationService`
  (calculateForAmount → `App\DataTransferObjects\TaxBreakdown`,
  writeLedgerEntry — **kontrak stabil untuk v0.3.4**, lihat CLAUDE.md),
  `RemittanceSummaryService` (generateForPeriod, finalize).
- **Keputusan desain kunci**: policy↔component di-resolve lewat `code`
  (stable identifier), bukan `tax_component_id` mentah — supaya kesepakatan
  burden-sharing reseller tidak perlu di-set ulang setiap kali pemerintah
  mengubah tarif pajak (`updateRate()` selalu insert baris baru dengan id
  berbeda, code sama). Ditemukan sebagai bug saat testing (`calculateForAmount`
  mengembalikan tax=0 untuk periode setelah rate change), diperbaiki di
  `ResellerTaxPolicyService::getActivePolicies()`.
- **Bug tersembunyi lain yang ditemukan+diperbaiki**: `Illuminate\Database\Eloquent\Collection::merge()`
  di-override Laravel untuk merge berbasis primary key model, mengabaikan
  key kustom dari `keyBy()` — `getActivePolicies()` awalnya salah
  men-dedupe policy reseller-specific vs direct-retail karena ini. Fix:
  `->toBase()` sebelum `merge()` untuk kembali ke `Illuminate\Support\Collection`
  biasa yang merge berbasis key array, bukan primary key model.
- **REST API**: `GET/POST/PUT /api/v1/tax-components` +
  `POST .../update-rate` (admin-only), `GET/POST/PUT /api/v1/reseller-tax-policies`
  (admin + reseller owner scoped via `ResellerContext`, staff read-only),
  `GET /api/v1/tax-ledger`, `GET /api/v1/remittance-summary` +
  `POST .../generate` (admin-only, keduanya — ledger/summary detail belum
  diekspos ke reseller di sprint ini). Permission baru `tax_components.*`,
  `reseller_tax_policies.*`, `tax_ledger.view`, `remittance_summary.*` —
  hanya `super_admin`, mengikuti pola ketat `resellers.*` di v0.3.2 (role
  `billing`/`finance` TIDAK otomatis dapat akses).
- **Livewire UI**: `Tax\TaxComponentIndex` (CRUD + tombol "Update Rate"
  effective-dating), `Tax\ResellerTaxPolicyIndex` (admin pilih reseller
  manapun/direct-retail, reseller owner ter-scope otomatis ke reseller
  miliknya). Sidebar cluster baru "Billing & Finance".
- **Scope disesuaikan dari rencana awal** (fondasi tax engine saja, TANPA
  hook otomatis ke invoicing — invoicing core baru di v0.3.4): semua data
  transaksi diuji via `TaxEngineDummyDataSeeder` (local-only,
  `source='seeded'`), bukan trigger dari invoice sungguhan.
- Tests: 113/113 passing (105 existing + 8 file test baru mencakup
  kalkulasi percentage/fixed, split ratio, remittance aggregation,
  effective dating, dan otorisasi reseller owner/staff — lihat
  `tests/Feature/Api/TaxComponentApiTest.php`,
  `tests/Feature/Api/ResellerTaxPolicyApiTest.php`,
  `tests/Feature/Tax/`).

## v0.3.2 — Multi-Tenant Reseller

- Tabel baru `resellers` (child dari `tenant` — punya `tenant_id`, pakai
  trait `BelongsToTenant` yang sama seperti `Customer`/`Agent`), `slug`
  unique per tenant, `status` (`active`/`suspended`), soft delete.
- Tabel pivot `reseller_users` (`reseller_id`+`user_id`, `role`
  `owner`/`staff`, `status` `active`/`inactive`) — reseller bisa punya
  banyak staff. "Detach" staff (`DELETE /resellers/{id}/users/{user}`)
  sengaja soft: hanya mengubah `status` jadi `inactive`, tidak menghapus
  baris, supaya riwayat keanggotaan tetap ada.
- Tabel baru `reseller_package_pricing` — pricing package yang dibuat
  reseller sendiri untuk dijual ke customer-nya. **Tanpa `base_package_id`**:
  tidak ada tabel katalog `packages` ISP di codebase manapun sejauh ini,
  jadi setiap baris independen; `is_custom` murni flag kategorisasi manual.
- `customers.reseller_id` (nullable, `ON DELETE SET NULL`) — customer bisa
  milik reseller atau jadi direct customer ISP (`reseller_id` null).
- **Reseller context resolution**: middleware baru `ResolveResellerContext`
  (alias `reseller.context`) menentukan "user ini beroperasi sebagai
  reseller yang mana" dari keanggotaan `reseller_users` aktifnya, disimpan
  di `App\Support\ResellerContext` (container-bound singleton per-request,
  sengaja bukan session — lebih mudah di-test). `App\Models\Scopes\ResellerScope`
  (dipasang lewat trait opsional `BelongsToResellerScope` di `Customer` dan
  `ResellerPackagePricing`) otomatis memfilter query ke reseller itu **hanya
  kalau context ter-resolve** — user ISP admin/staff internal (tanpa
  keanggotaan reseller apa pun) tetap melihat semua data, sama seperti pola
  `TenantScope` untuk multi-tenancy. Middleware ini didaftarkan dengan
  prioritas eksplisit di depan `SubstituteBindings` (lihat `bootstrap/app.php`)
  supaya scope sudah aktif *sebelum* route-model-binding jalan — tanpa itu,
  akses ke package-pricing/customer milik reseller lain menghasilkan 403
  (baru ditolak di Policy) alih-alih 404 (ditolak dari resolusi binding-nya
  sendiri, isolasi yang lebih ketat, konsisten dengan `TenantIsolationTest`).
- Permission baru `resellers.view`/`resellers.manage`, hanya diberikan ke
  `super_admin` — reseller owner/staff diotorisasi lewat keanggotaan
  `reseller_users` mereka sendiri (`ResellerPolicy`, `CustomerPolicy`,
  `ResellerPackagePricingPolicy`), bukan lewat role/permission Spatie, karena
  mereka user eksternal (bisnis reseller), bukan staff internal ISP.
- **REST API (BOSS-006)**: `GET/POST /api/v1/resellers`,
  `GET/PUT/DELETE /api/v1/resellers/{reseller}`,
  `GET/POST /api/v1/resellers/{reseller}/users`,
  `DELETE /api/v1/resellers/{reseller}/users/{user}`,
  `GET/POST /api/v1/reseller-package-pricing`,
  `GET/PUT/DELETE /api/v1/reseller-package-pricing/{pricing}`. Business
  logic di `ResellerService`/`ResellerPackagePricingService`, dipakai bareng
  API controller dan komponen Livewire (`Resellers\ResellerIndex`,
  `Resellers\ResellerShow`, `Resellers\PackagePricingIndex`) — masuk sidebar
  cluster baru "Operasional". Lihat `docs/API.md`.
- **Scope disesuaikan dari rencana awal setelah inspeksi codebase** (lihat
  `docs/ROADMAP.md` untuk catatan dependency lengkap): tidak ada tabel
  `subscriptions` atau katalog `packages` ISP di codebase manapun sebelum
  sprint ini — keduanya baru direncanakan lahir di v0.3.4 (Invoicing Core).
  Bagian skema yang bergantung padanya (`subscriptions.reseller_id`,
  `subscriptions.reseller_package_pricing_id`, `reseller_package_pricing.base_package_id`)
  sengaja **tidak** dibuat di v0.3.2 — dicatat sebagai dependency wajib untuk
  v0.3.4 saat `subscriptions` benar-benar lahir, supaya v0.3.2 tetap sesuai
  RULE BOSS-003 (stay in scope) dan tidak diam-diam melahirkan desain
  `subscriptions` darurat yang bisa tabrakan dengan desain resmi nanti.
- **Bug infrastruktur ke-4 ditemukan & diperbaiki** (pola sama dengan 3 bug
  v0.1.0 yang sudah didokumentasikan di `CLAUDE.md`): root `.env` (dipakai
  Docker Compose sebagai `env_file`) punya `APP_ENV=production` padahal
  `app/.env` bilang `local`, dan process env container menang atas `.env` —
  server dev/sprint ini (`45.123.142.242`, satu-satunya server yang
  didokumentasikan di `docs/DEPLOYMENT.md`, direncanakan jadi production
  in-place saat go-live, bukan diganti server terpisah) diam-diam selalu
  `app()->environment() === 'production'` sejak awal. Diperbaiki dengan
  mengubah root `.env` (gitignored, bukan `.env.example`) jadi
  `APP_ENV=local` dan me-recreate container `boss-app`/`boss-worker`/
  `boss-scheduler`. Detail lengkap + reminder untuk flip balik ke
  `production` saat go-live sungguhan: lihat `CLAUDE.md`.
- Tests: 90/90 passing (84 existing + 6 file test baru mencakup CRUD
  reseller, staff management, package pricing CRUD, dan isolasi scope
  reseller — lihat `tests/Feature/Api/ResellerApiTest.php`,
  `tests/Feature/Api/ResellerPackagePricingApiTest.php`,
  `tests/Feature/Resellers/ResellerScopeIsolationTest.php`).

## v0.3.1 — Personalization & Navigation

- **Theme customization**: primary & text color custom per user, disimpan di
  `user_preferences`, di-apply lewat CSS variables (Tailwind v4 `@theme`),
  live preview instan via Alpine sebelum disimpan.
- **Language switcher**: localization Laravel (`lang/id.json`, `lang/en.json`),
  middleware `SetLocale` (resolusi: session → preferensi user → default
  config), persist per user setelah login.
- **Sidebar cluster-dropdown**: grouping menu collapsible per cluster, state
  collapse tersimpan di `localStorage`, highlight active route.
- **Dashboard widget selector**: halaman `/dashboard` baru dengan 4 widget
  (Total Pelanggan, Pelanggan Terbaru, Status Registrasi, Agent Referral
  Teratas), visibility widget dikonfigurasi per user dan tersimpan di
  `user_preferences.dashboard_widgets`.
- Stack tetap Laravel + Blade + Livewire + Alpine.js + Tailwind — tidak ada
  penambahan framework frontend baru (bukan React/Next.js).
- **REST API (BOSS-006 gap closure)**: `POST /api/v1/registrations`,
  `GET /api/v1/referrals` (v0.3.0), dan `GET`/`PUT
  /api/v1/settings/{theme,locale,dashboard-widgets}` (v0.3.1) — sebelumnya
  kedua modul ini hanya punya UI Livewire tanpa REST API, melanggar aturan
  API-first. Business logic diekstrak/reuse lewat Service class
  (`ThemeSettingsService`, `LocaleService`, `DashboardWidgetService`,
  `RegistrationService`) yang dipakai bareng oleh controller API dan
  komponen Livewire. Lihat `docs/API.md`.

## v0.3.0 — Registration & Referral

- Tabel `agents` (tipe `sales`/`teknisi`/`freelance`/`admin`, opsional
  ter-link ke akun `users`) dan `commission_ledger` (status
  `pending`/`eligible`/`approved`/`paid`/`rejected`).
- Kolom tambahan di `customers`: `referred_by_agent_id`, `registration_status`
  (`registered`/`installed`/`active`), `registration_channel`, plus `nik`,
  `latitude`/`longitude`, `package`.
- `RegistrationService`: registrasi pelanggan transaksional — buat baris
  `commission_ledger` status `pending` otomatis kalau ada agent yang
  mereferensikan, tidak membuat apa pun kalau registrasi tanpa referral.
- Livewire `RegisterCustomer` (`/customers/register`) — dropdown agent
  referral auto-terisi & readonly untuk role sales/teknisi/freelance, manual
  untuk admin.
- Permission `register-customer` di-assign ke role existing (`super_admin`,
  `sales_internal`, `teknisi`, `sales_freelance`) — tidak ada role baru.
- `AgentSeeder`: 3 agent dummy untuk testing.

## v0.2.0 — Customer CRM

- Data pelanggan (`customers`): profil, alamat, telepon utama, status
  lifecycle (`prospek`/`aktif`/`suspend`/`non_aktif`/`blacklist`) dengan
  validasi transisi.
- Kontak keluarga (`customer_contacts`): access level (`full`/`view_only`/
  `emergency`), permission flags, invariant "tepat satu authorized contact
  per pelanggan".
- Customer timeline (`customer_timeline_entries`): log otomatis (Model
  Observer) untuk semua perubahan status/profil/kontak, read-only via API.
- Permission granular Spatie per modul (`customers.view`, `customers.manage`,
  `customer_contacts.manage`, `customer_timeline.view`).
- API `Api/V1` lengkap (Migration → Enum → Model → Policy → Form Request →
  Action → Resource → Controller) + Livewire UI (`/customers`,
  `/customers/{id}`).
- **Fondasi multi-tenancy row-level**: tabel `tenants`, trait
  `BelongsToTenant` + `TenantScope` (global scope otomatis), diterapkan ke
  `Customer`/`CustomerContact`/`CustomerTimelineEntry`, kolom `tenant_id`
  wajib di `users`. `super_admin` tetap tenant-scoped (bukan role
  lintas-tenant).
- `DemoUsersSeeder`: satu tenant demo + satu user per role untuk testing
  manual.
- Perbaikan bug laten dari v0.1.0 (ditemukan saat membangun sprint ini):
  - Migration Sanctum (`personal_access_tokens`) tidak pernah di-publish —
    autentikasi token API tidak pernah benar-benar berfungsi.
  - Fortify tidak punya view login ter-bind — `/login` selalu 500.
  - `phpunit.xml` gagal mengisolasi test ke SQLite in-memory — seluruh test
    diam-diam berjalan ke database Postgres dev sungguhan.

## v0.1.0 — Foundation

- Scaffold Laravel 12 / PHP 8.4, Docker Compose (Nginx, Postgres, Redis,
  worker, scheduler).
- Fortify (auth), Sanctum (API token), Spatie Permission (role) — 8 role
  dasar: `super_admin`, `noc`, `customer_service`, `teknisi`, `billing`,
  `sales_internal`, `sales_freelance`, `finance`.
- `GET /api/v1/health` — health check app/database/redis.
- UFW + Fail2ban, backup script terjadwal.
