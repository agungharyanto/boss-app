# BOSS App — Roadmap Sprint (Urutan Dikunci)

| Versi   | Cluster         | Nama                          | Isi Utama                                                                                   | Status  |
|---------|-----------------|-------------------------------|-----------------------------------------------------------------------------------------------|---------|
| v0.1.0  | Operasional     | Foundation                    | Repo, Docker, Laravel, PostgreSQL, Redis, Nginx, login, role, UFW/Fail2ban, backup            | Selesai |
| v0.2.0  | Operasional     | Customer CRM                  | Data pelanggan, kontak keluarga, authorized contact, customer timeline                        | Selesai |
| v0.3.0  | Operasional     | Registration & Referral       | Registrasi multi-channel, agent, referral, komisi pending                                     | Selesai |
| v0.3.1  | UI/UX           | Personalization & Navigation  | Theme custom (primary/text color), language switcher, dashboard widget selector, sidebar cluster-dropdown | Selesai |
| v0.3.2  | Operasional     | Multi-Tenant Reseller         | Tabel resellers (child dari tenant), reseller_id menyebar, guard/role reseller, reseller_package_pricing | Selesai |
| v0.3.3  | Billing & Finance | Regulatory Tax Engine        | tax_components dinamis (nama bebas, persen/nominal, on/off, versi-per-tanggal), reseller_tax_policies, reseller_tax_ledger, komdigi_remittance_summary | Selesai |
| v0.3.4  | Billing & Finance | Invoicing Core               | Subscription plan per customer, generate invoice bulanan, invoice line items, status invoice   | Selesai |
| v0.3.5  | Billing & Finance | Payment Gateway (Xendit)     | Integrasi Xendit (VA/QRIS/invoice), webhook handler + signature verification, idempotency, reconciliation | Selesai |
| v0.4.0  | Komunikasi      | Communication (Baileys)       | WhatsApp gateway multi-sesi per reseller, template pesan, reminder invoice/suspend, reconciliation session | Selesai |
| v0.5.0  | Operasional     | Installation                  | Work order teknisi (ODP locator Haversine, state machine, scan device, 4 foto wajib)          | Selesai |
| v0.6.1  | Network         | FreeRADIUS Core & NAS Management | Container FreeRADIUS terpisah + rlm_sql ke `radius_db` (Postgres terpisah), tabel `nas` (port RADIUS unik per-NAS sejak migration pertama), NasService CRUD + test koneksi Mikrotik API | Selesai |
| v0.6.2  | Network         | VPN Server Node #1 (OpenVPN)  | Hub-and-spoke: VPN node sebagai concentrator/relay, FreeRADIUS diakses di satu IP internal tetap dari sisi Mikrotik                          | Selesai |
| v0.6.3  | Network         | Multi-Protokol VPN & Script Generator | WireGuard, L2TP/IPsec (SSTP di-skip; L2TP/IPsec sendiri berstatus known limitation, lihat catatan di bawah), Script Generator (VPN + RADIUS script siap-paste ke terminal Mikrotik) | Selesai |
| v0.6.4  | Network         | VPN Pool & Failover           | Pool 3 node nyata x 2 protokol (OpenVPN + WireGuard, L2TP tetap 1 node — known limitation), load-distribution, health-check terjadwal, auto-switch failover Mikrotik — diverifikasi end-to-end nyata (matikan node, konfirmasi pindah otomatis) | Selesai |
| v0.6.5  | Network         | Dynamic Virtual Server & CoA  | Virtual server FreeRADIUS dinamis per-NAS + port allocator (diverifikasi race-safe nyata) + CoA/Disconnect + fix `require_message_authenticator` per-NAS (akar masalah sesungguhnya) — sesi PPPoE nyata pertama berhasil end-to-end lewat FreeRADIUS produksi, config router sepenuhnya hasil generate resmi BOSS App | Selesai |
| v0.7.1  | Network         | GenieACS Core                 | Deploy GenieACS+MongoDB, auto-binding device dari Installation (work_order_devices), CpeDevice model + API + UI list read-only | Selesai |
| v0.7.2  | Network         | GenieACS Vendor Mapping       | Mapping parameter per-vendor (`cpe_parameter_maps`), resolve RX/TX power via `refreshObject` — refresh on-demand lewat Connection Request/tunnel VPN masih diblokir, lihat catatan v0.7.3 | Selesai |
| v0.7.3  | Network         | GenieACS Connection Request Routing | Routing Connection Request GenieACS lewat tunnel VPN ke subnet manajemen TR-069 NAS (prasyarat jaringan, bukan fitur remote action itu sendiri) — reboot/push SSID instan tetap backlog terpisah | Implementasi selesai — verifikasi akhir pending |
| v0.7.4  | Network         | GenieACS Remote Actions       | Reboot + ganti SSID/password WiFi lewat task queue GenieACS + audit log (`cpe_action_logs`) — sengaja "tidak instan" (Connection Request dicoba tapi tidak diandalkan, lihat catatan v0.7.3), instant-push otomatis aktif tanpa perubahan kode begitu v0.7.3 terverifikasi | Implementasi selesai — verifikasi UI komprehensif dijadwalkan sebelum v0.8 |
| v0.7.5  | Network         | GenieACS Auto-Provisioning (SSID/Password) | Reuse `CpeActionService` (v0.7.4) — SSID/password hasil input teknisi (`work_order_devices.ssid`/`wifi_password`, direkam CS lewat bridge endpoint sementara) otomatis didorong ke device begitu dikenal GenieACS, lewat hook di `CpeBindingService` (binding ATAU reconciliation). PPPoE di luar scope ini, item roadmap terpisah. Slot ini sebelumnya bernomor v0.7.4, digeser karena v0.7.4 akhirnya dipakai buat Remote Actions | Implementasi selesai — verifikasi UI komprehensif dijadwalkan sebelum v0.8 |
| v0.7.6  | Network         | GenieACS Connected Clients (dengan histori) | Baca TR-069 `Hosts.Host` (client WiFi/LAN terhubung) — histori per `(device, MAC)` di `cpe_connected_hosts`, bukan snapshot per poll, sync command terjadwal 5 menit dari data yang sudah tersimpan GenieACS (tidak memicu refresh sendiri) | Implementasi selesai — verifikasi UI komprehensif dijadwalkan sebelum v0.8 |
| v0.8.0  | Network         | LibreNMS & Graph              | Device monitoring, graph jaringan, graph pemakaian per-pelanggan, alert                       | Backlog |
| v0.9.0  | Billing & Finance | Commission                   | Eligibility, approval, payment, clawback (menyempurnakan commission_ledger v0.3.0)             | Backlog |
| v0.10.0 | Network         | Outage Engine                 | ONT down detection, korelasi area, incident, maintenance                                      | Backlog |
| v0.11.0 | Customer App    | Mobile Self-Service Portal    | Auth guard customer terpisah, ganti password (OTP), cek pemakaian, bayar tagihan               | Backlog |

Kita tidak loncat versi dalam satu cluster. Setiap versi selesai penuh
(lihat Definition of Done di RULES.md) sebelum lanjut ke versi berikutnya.
Urutan antar-cluster mengikuti dependency teknis: reseller sebelum
billing/tax (hindari retrofit), tax sebelum invoicing, invoicing sebelum
payment gateway, FreeRADIUS sebelum mobile app & usage graph.

**Amendment saat v0.6.1 dimulai (dikonfirmasi Agung)**: `v0.6.0` FreeRADIUS
(satu versi) dipecah jadi 5 sub-versi `v0.6.1`-`v0.6.5` karena scope-nya
besar — mereplikasi pola VPN+RADIUS dari referensi kompetitor MixRadius V3.2.
Ini membuat slot `v0.6.1` yang sebelumnya dipakai "Mobile Self-Service
Portal" bentrok; Mobile Self-Service Portal **digeser ke `v0.11.0`** (akhir
backlog, setelah cluster Network selesai penuh) — bukan disisipkan di
tengah lagi. Nomor `v0.7.0`-`v0.10.0` (GenieACS/LibreNMS/Commission/Outage)
tidak berubah.

**Keputusan arsitektur terkunci untuk seluruh cluster `v0.6.x`** (jangan
dinegosiasi ulang tanpa konfirmasi eksplisit baru, BOSS-003):
- VPN multi-protokol: OpenVPN, WireGuard, L2TP/IPsec. SSTP di-skip (server
  open-source kurang matang).
- Topologi VPN pool-ready dari awal (schema `vpn_servers` di v0.6.4 sudah
  mengakomodasi banyak node + health-check + failover), tapi implementasi
  aktual cuma 1 node untuk sekarang (server dev = calon server produksi
  in-place, tidak ada VPS terpisah saat ini). Model hub-and-spoke: VPN node
  adalah concentrator/relay, FreeRADIUS selalu diakses di satu IP internal
  tetap dari sisi Mikrotik, apa pun node VPN yang dipakai.
- Port RADIUS unik per-NAS (bukan 1812/1813 shared standar, pola MixRadius).
  Konsekuensi: skema `nas` di v0.6.1 sudah menyertakan kolom
  `auth_port`/`acct_port`/`coa_port` sejak migration pertama walau dynamic
  virtual server + port allocator baru dibangun di v0.6.5 — supaya tidak
  retrofit.

**2 keputusan arsitektur diselesaikan bersama Agung saat v0.6.3 dimulai**
(sebelum migration/container ditulis, sesuai instruksi eksplisit):
- **Topologi container**: WireGuard dan L2TP/IPsec masing-masing container
  Docker terpisah dari `openvpn` (bukan satu container multi-protokol) —
  konsisten pola single-responsibility repo ini. Konsekuensi skema:
  `vpn_servers.protocol_support` (json array, v0.6.2) **diganti** kolom
  `protocol` (string/enum) polos — sekarang **satu baris per (host,
  protocol)**, bukan satu baris menaungi banyak protokol. Migration alter
  (bukan edit migration v0.6.2 yang sudah ter-tag) + backfill data
  otomatis.
- **Port RADIUS di Script Generator**: tab RADIUS Script SEMENTARA
  memakai port default FreeRADIUS (1812/1813), BUKAN `nas.auth_port`/
  `acct_port` (walau kolom itu sudah ada sejak v0.6.1) — karena FreeRADIUS
  baru benar-benar dengar di port unik per-NAS setelah dynamic virtual
  server (v0.6.5). Script yang digenerate v0.6.3 WAJIB digenerate ulang
  setelah v0.6.5 shipped.

**Known limitation ditutup sebagai v0.6.3, bukan dianggap gagal — L2TP/IPsec
tidak benar-benar bisa dipakai produksi saat ini**, ditemukan lewat sesi
verifikasi mendalam terhadap NAS Mikrotik produksi asli (`test-x86-bajastu`,
RouterOS 7.11) via `RouterOsApiGateway`, bukan simulasi. Kronologi singkat
(detail teknis lengkap + evidence di CHANGELOG.md "Amendment kelima" dan
CLAUDE.md): `NO-PROPOSAL-CHOSEN` yang awalnya dilaporkan **berhasil
diselesaikan tuntas** — root cause sebenarnya adalah `left=` di
`ipsec.conf` yang di-pin ke IP publik server, padahal Docker men-DNAT paket
masuk ke IP internal container sebelum sampai ke charon, sehingga `conn`
tidak pernah cocok apa pun proposal yang ditawarkan; diperbaiki dengan
`left=%any` dan dikonfirmasi via log `charon` sendiri (baru bisa dibaca
setelah `syslogd` ditambahkan ke container — sebelumnya log charon hilang
begitu saja, tidak ke mana pun): IKE_SA + CHILD_SA established dan stabil
1+ menit dengan DPD berhasil bolak-balik berkali-kali. **Tapi** lapisan
L2TP/PPP di atas IPsec yang sudah established itu tidak pernah benar-benar
terlindungi — capture paket di level host (`tcpdump` pada interface publik
server, bukan di dalam container) membuktikan RouterOS mengirim paket
kontrol L2TP (port 1701, `SCCRQ`) sebagai UDP polos, bukan terbungkus ESP,
bahkan setelah IPsec SA berhasil established — 0 paket ESP ditemukan di
seluruh capture, meski IKE Phase 1+2 sukses negosiasi. Root cause ini ada
di bagaimana mode sederhana RouterOS `l2tp-client use-ipsec=yes` menerapkan
(atau gagal menerapkan) Security Policy Database-nya sendiri secara
internal — bukan sesuatu yang bisa didiagnosis atau diperbaiki lebih lanjut
dari sisi server BOSS App; kemungkinan butuh dukungan resmi MikroTik atau
riset RouterOS yang lebih dalam. **Ditandai sebagai known limitation, scope
v0.6.3 tetap ditutup** — OpenVPN dan WireGuard sudah fully functional dan
terverifikasi nyata di router produksi yang sama (lihat CHANGELOG.md), jadi
sprint ini tidak diblokir oleh L2TP. Backlog: revisit L2TP/IPsec di sprint
Network mendatang kalau ada temuan baru atau dukungan MikroTik.

**Keputusan arsitektur v0.6.4 (dikonfirmasi bersama Agung sebelum
implementasi)**: pool **3 node VPN nyata** untuk OpenVPN dan WireGuard
(bukan cuma skema siap N>1 seperti rencana awal v0.6.2) — masing-masing
protokol 3 container terpisah, konsisten pola single-responsibility sejak
v0.6.3. L2TP TIDAK ikut pool (known limitation di atas belum selesai).
**Ketiga node per protokol berbagi trust domain yang sama** (satu PKI
untuk OpenVPN, satu server keypair + direktori peer untuk WireGuard) —
supaya auto-switch failover cukup ganti endpoint/port tanpa re-import
credential. Detail teknis lengkap (termasuk 3 bug nyata soal sintaks
RouterOS `/import` yang ditemukan & diperbaiki lewat deploy sungguhan ke
`test-x86-bajastu`, dan verifikasi failover end-to-end dengan mematikan
node secara paksa) ada di CHANGELOG.md "v0.6.4" dan CLAUDE.md.

**Catatan verifikasi tertunda untuk v0.6.4 (bukan bug, bukan sprint belum
selesai)**: implementasi dan fungsinya sudah terbukti nyata sepenuhnya
lewat API langsung ke `test-x86-bajastu` — load-distribution, health-check,
dan auto-switch failover semua dikonfirmasi bekerja end-to-end (termasuk
mematikan satu node secara paksa dan mengamati router pindah otomatis).
**Yang belum dicoba: jalur UI Livewire (Script Generator) untuk skenario
multi-node/failover ini belum pernah diverifikasi manual oleh Agung lewat
browser** — verifikasi sejauh ini seluruhnya lewat Claude Code
(`RouterOsApiGateway` + API langsung). Agung akan menyiapkan router
Mikrotik terpisah khusus untuk testing UI menyeluruh nanti.

**v0.6.5 (Dynamic Virtual Server & CoA) selesai — penutup cluster v0.6.0.**
Virtual server FreeRADIUS per-NAS, port allocator (sekarang diverifikasi
race-safe lewat 5 proses konkuren nyata terhadap Postgres produksi, bukan
cuma sekuensial), dan Script Generator RADIUS tab semuanya diverifikasi
nyata penuh — termasuk akar masalah sesungguhnya yang baru ketemu setelah
tag awal: `radiusd.conf`'s `require_message_authenticator = yes` (mitigasi
BlastRADIUS) diam-diam membuang setiap Access-Request dari RouterOS (yang
tidak pernah menyertakan atribut ini di PPP CHAP/MSCHAP), bukan sekadar
"performa" atau "network" seperti dugaan awal — dilacak sampai byte-level
lewat `tcpdump`. Fix-nya: override `require_message_authenticator = no`
scoped ke client per-NAS saja (rekomendasi resmi dari `radiusd.conf`
sendiri, bukan diubah global). Setelah fix, config router untuk
`test-x86-bajastu` juga sudah dipindahkan dari hasil edit manual berkali-kali
sepanjang investigasi menjadi **hasil generate resmi lewat Script Generator
BOSS App** (fetch+import biasa, tidak ada langkah manual tersisa) — akun
`085166445368` (sekarang jadi akun test permanen, lihat CLAUDE.md) berhasil
konek PPPoE nyata end-to-end, IP ter-assign, sesi stabil.

**CoA/Disconnect — akhirnya diuji nyata (bukan lagi cuma level transit
paket), akar masalah ketemu, statusnya known limitation v0.6.4, bukan lagi
"tertunda"**: memakai `085166445368` (akun test milik sendiri, aman untuk
diputus, bukan pelanggan asli), Disconnect-Request nyata dikirim ke NAS —
terkirim benar (radclient, 3x retry, format paket benar) tapi tidak ada
balasan. Ditelusuri: rute CoA `freeradius` untuk subnet WireGuard NAS ini
mengarah ke node POOL OWNER (node1), padahal akun WireGuard NAS ini
sebenarnya nyambung ke node SIBLING (node2, dari load-distribution v0.6.4)
yang tidak pernah handshake dengan NAS ini sama sekali dari sisi node1.
Ini **persis** limitation yang sudah didokumentasikan sejak v0.6.4
(`CoaService`'s own docblock) — sekarang terbukti nyata terjadi, bukan
cuma teori. Perbaikan (CoA router yang sadar multi-node) tetap backlog
untuk sprint Network mendatang, BUKAN scope v0.6.5. Detail lengkap ada di
CHANGELOG.md "v0.6.5" dan CLAUDE.md.

## Ringkasan penutup cluster v0.6.0 — FreeRADIUS Integration (v0.6.1-v0.6.5)

Seluruh cluster Network "FreeRADIUS Integration" (dipecah jadi 5 sub-versi
saat v0.6.1 dimulai — lihat amendment di atas) sekarang **selesai**. Status
akhir per komponen:

- **FreeRADIUS core + NAS inventory** (v0.6.1): selesai, terverifikasi.
- **OpenVPN hub-and-spoke, 1 node** (v0.6.2): selesai, terverifikasi
  (provisioning + isolasi 3-lapis terbukti nyata).
- **WireGuard + L2TP/IPsec + Script Generator** (v0.6.3): OpenVPN & WireGuard
  selesai & terverifikasi penuh di produksi. **L2TP/IPsec — known limitation
  yang MASIH TERBUKA**: IKE/IPsec SA berhasil established, tapi trafik L2TP
  tidak pernah benar-benar terenkripsi ESP (root cause di luar kendali sisi
  server, kemungkinan perilaku internal `l2tp-client use-ipsec=yes`
  RouterOS). Backlog untuk sprint Network mendatang kalau ada temuan baru
  atau dukungan resmi MikroTik.
- **Multi-node pool & auto-switch failover** (v0.6.4): selesai & terverifikasi
  nyata lewat API (matikan node, konfirmasi pindah otomatis). Verifikasi UI
  Livewire untuk skenario ini masih tertunda (item terpisah, bukan blocker).
- **Dynamic virtual server & CoA** (v0.6.5): selesai & terverifikasi nyata
  penuh — sesi PPPoE nyata pertama berhasil end-to-end lewat FreeRADIUS
  produksi (bukan cuma Access-Accept di level paket), setelah akar masalah
  sesungguhnya (`require_message_authenticator`, bukan performa/jaringan)
  ditemukan dan diperbaiki per-NAS. Config router sepenuhnya hasil generate
  resmi Script Generator, tidak ada lagi hasil edit manual tersisa.
  CoA/Disconnect sudah diuji nyata (bukan cuma level jaringan lagi) — hasilnya
  mengonfirmasi known limitation v0.6.4 (CoA belum sadar multi-node,
  backlog terpisah), bukan lagi status "tertunda".

**Catatan operasional wajib untuk onboarding NAS Mikrotik berikutnya** (bukan
cuma histori debugging — ini langkah nyata yang perlu dicek setiap kali NAS
baru tidak mau autentikasi): jangan asumsikan semua RouterOS mengirim
Message-Authenticator di Access-Request PPP CHAP/MSCHAP. Kalau NAS baru
menunjukkan gejala "retry terus-menerus, `/radius/monitor` mencatat timeout,
tapi `radiusd -X` TIDAK PERNAH mencatat 'Received Access-Request' sama
sekali" — itu bukan masalah performa atau jaringan, cek dulu byte mentah
paketnya (`tcpdump`) untuk keberadaan atribut ini sebelum menelusuri arah
lain. Fix-nya sudah jadi pola siap pakai di `FreeradiusVirtualServerService`
(override per-client, bukan global) — tinggal diterapkan kalau NAS lain
menunjukkan gejala yang sama.

**Yang TIDAK pernah masuk scope v0.6.0** (bukan lupa — sengaja di luar,
sesuai kickoff v0.6.1): stok/inventaris RADIUS, integrasi billing otomatis
dengan status RADIUS (isolir otomatis saat invoice overdue lewat CoA — CoA-
nya sudah ada endpoint-nya di v0.6.5, tapi trigger otomatis dari billing
belum dibangun, itu scope terpisah), dashboard monitoring RADIUS real-time
(itu domain LibreNMS, v0.8.0), dan multi-tenant SaaS penuh untuk beberapa ISP
berbagi satu `freeradius`/VPN node (posisi operasional saat ini: satu
deployment BOSS App = satu ISP, sama seperti `payment_gateway_settings`/
`whatsapp_gateway` "direct" session — didokumentasikan di CLAUDE.md).

## v0.7.0 dipecah jadi sub-cluster v0.7.1-v0.7.6 (dikonfirmasi Agung saat v0.7.1 dimulai; digeser dari v0.7.1-v0.7.4 ke v0.7.1-v0.7.5 saat v0.7.4 selesai, lalu ke v0.7.1-v0.7.6 saat v0.7.6 ditambahkan — lihat catatan v0.7.4/v0.7.6 di bawah)

Sama pola dengan `v0.6.0` → `v0.6.1`-`v0.6.5`: `v0.7.0` (GenieACS, satu baris)
dipecah jadi sub-versi karena scope-nya juga besar dan berlapis (deploy
dasar → mapping per-vendor → routing prasyarat jaringan → aksi remote →
provisioning otomatis, masing-masing punya dependency teknis ke yang
sebelumnya — awalnya direncanakan 4 sub-versi, jadi 5 setelah routing
prasyarat jaringan ternyata perlu sub-versinya sendiri, lihat catatan v0.7.3
di bawah). **BOSS-002 relaxed
untuk cluster `v0.7.x` ini** — sub-versi boleh berjalan berurutan dalam satu
alur kerja tanpa commit/tag terpisah tiap sub-versi kalau memang lebih
efisien, beda dari `v0.6.x` yang tiap sub-versi ditag sendiri-sendiri
(v0.7.1 sendiri tetap ditag seperti biasa karena ini penutupan sub-versi
pertama, bukan pekerjaan yang menyambung ke sub-versi berikutnya dalam satu
sesi).

**v0.7.1 (GenieACS Core) selesai**: container `mongo`+`genieacs-cwmp`+
`genieacs-nbi`+`genieacs-fs`+`genieacs-ui` (npm install, tidak ada image
resmi — sama alasan seperti freeradius/openvpn/wireguard/l2tp), auto-binding
`cpe_devices` dari `work_order_devices` lewat hook di
`WorkOrderService::complete()` (best-effort, tidak pernah menggagalkan
penyelesaian work order), API+UI list read-only. **Bentuk respons NBI
diverifikasi nyata** (bukan diasumsikan dari dokumentasi resmi yang tidak
memberi contoh JSON lengkap) — TR-069 Inform mentah dikirim langsung ke
`genieacs-cwmp` yang hidup, hasilnya dibedah: `_id` formatnya persis
"OUI-ProductClass-SerialNumber", tiap parameter TR-069 muncul sebagai object
bersarang dengan field `_value`, dan `_deviceId` (berisi Manufacturer/OUI/
ProductClass/SerialNumber langsung dari struct DeviceId milik RPC Inform)
ternyata tersedia independen dari root TR-098/TR-181 apa pun.

**Penyimpangan dari spec awal yang perlu dicatat**: spec awal bilang "MAC
address biasanya paling reliable" untuk mencocokkan device hasil scan
teknisi ke device GenieACS. Setelah verifikasi nyata di atas, ternyata MAC
address TIDAK punya path standar yang sama di semua vendor (baru
dipetakan per-vendor di v0.7.2) — sedangkan serial number, lewat
`_deviceId._SerialNumber`, tersedia otomatis dari SETIAP device TR-069 tanpa
perlu tahu vendor-nya (itu field wajib RPC Inform, bukan parameter
opsional). `CpeBindingService` karena itu mencocokkan lewat serial number,
bukan MAC — didokumentasikan lengkap di docblock class itu sendiri.

`genieacs-sim` (simulator resmi project GenieACS) dicoba dulu sesuai arahan,
tapi paketnya (v0.9.0 di npm) sudah terlalu using untuk Node.js modern —
dependensi native `libxmljs`-nya gagal build bahkan dengan toolchain
lengkap (python3/make/g++) karena memang menyasar Node 6.x. Diganti dengan
mengirim SOAP Inform mentah secara manual (justru inilah yang dipakai untuk
verifikasi bentuk respons NBI di atas) — cukup untuk kebutuhan verifikasi
v0.7.1, tidak butuh simulator penuh untuk test otomatis (reconciliation job
di-test lewat `Http::fake()`, bukan device simulator sungguhan).

**v0.7.2 (GenieACS Vendor Mapping) selesai**: `cpe_parameter_maps`
(platform-level, key `oui`+`product_class`+`parameter_key`) +
`ParameterConversionService` (`raw`/`linear`/`sff8472_optical_log10`) +
`CpeParameterResolverService` (cocokkan device nyata ke katalog, tarik raw
value, konversi) + CRUD API/`resolve` endpoint/UI Livewire dengan panel "Tes
Resolve" langsung terhadap device live. Formula `sff8472_optical_log10`
(power SFF-8472 standar: `dBm = 10*log10(raw*scale)`) **diverifikasi nyata**
terhadap satu ONT ZTE F663NV3.1 (`F86CE1-F663NV3a-ZICG296C2E7B`) — bukan cuma
RX power sendirian, tapi keempat field DDM optik di object yang sama
(`BiasCurrent`/`RXPower`/`TXPower`/`SupplyVottage`/`TransceiverTemperature`)
sama-sama mendarat di nilai dunia-nyata yang masuk akal di bawah skala yang
sama (RX -28.24 dBm, TX 2.33 dBm, ~16.3mA, ~3.28V, ~57°C) — lihat CLAUDE.md
"GenieACS Vendor Parameter Mapping (v0.7.2)" untuk detail lengkap. Raw value
0 sengaja di-reject (`InvalidArgumentException`), bukan didiamkan jadi -∞.

**Bug infrastruktur kritis ditemukan & diperbaiki di tengah sprint ini**:
proxy `boss-nginx` → `genieacs-cwmp` yang sejak v0.7.1 masih di level HTTP
(`proxy_pass` tanpa `upstream keepalive`) ternyata membuat SEMUA Digest auth
gagal tanpa terkecuali — GenieACS mengikat nonce challenge-nya ke socket TCP
tertentu, sedangkan nginx membuka koneksi backend baru tiap request, jadi
nonce dari request pertama tidak pernah ditemukan lagi di request kedua.
Diperbaiki dengan proxy TCP murni (`stream {}` module nginx, bukan `http{}`)
— lihat CLAUDE.md "GenieACS Core & TR-069 CWMP proxying gotcha (v0.7.2)".
Tanpa fix ini, tidak ada satu device pun yang bisa sukses connect ke
GenieACS lewat auth apa pun — jadi bug ini blocker keras buat pembuktian
formula konversi di atas, bukan sekadar temuan sampingan.

**Dua temuan discovery, BUKAN bagian selesai v0.7.2, dicatat sebagai
follow-up untuk sprint mendatang**:
1. **Backfill 400+ modem existing** (di luar v0.7.x, rencana terpisah):
   tabel `customers` **tidak punya kolom ID pelanggan sistem lama** sama
   sekali (`legacy_id`/`old_system_id`/dst — sudah dicek migration+schema
   langsung, nihil) — perlu kolom baru sebelum backfill bisa jalan. Selain
   itu, MAC address device **tidak tersimpan di parameter tree GenieACS**
   kecuali ada preset eksplisit yang menariknya (`db.presets` kosong total
   di instance ini) — jadi strategi cross-reference MAC→SerialNumber lewat
   data GenieACS cuma bisa jalan untuk device yang preset-nya sudah
   dipasang, bukan otomatis untuk device yang sekadar pernah connect.
2. **Connection Request / refresh on-demand untuk v0.7.3**: diinvestigasi
   penuh, ternyata **belum bisa jalan** — tapi BUKAN karena tunnel mati
   seperti sempat disimpulkan keliru di sini. **Amendment**: klaim
   "tunnel WireGuard `test-x86-bajastu` tidak pernah handshake" di atas
   **salah** — itu hasil mengecek node WireGuard yang salah (`wireguard`/
   node-1, padahal `vpn_accounts` NAS ini di-assign ke `vpn-node-2`).
   Dicek ulang di node yang benar: handshake aktif, trafik nyata mengalir
   (150+ KiB kedua arah). Akar masalah sebenarnya: `AllowedIPs` WireGuard
   (kripto-routing, bukan cuma iptables) di kedua ujung tunnel dikunci ke
   `172.28.0.10/32` (FreeRADIUS) saja — perlu di-widen, bukan diperbaiki
   dari "mati". Firewall hub-and-spoke sejak v0.6.2 sengaja dikunci ke
   SATU rule `/32` tujuan FreeRADIUS saja (keputusan keamanan terkunci,
   bukan celah). **Juga dikoreksi**: tidak ada "jaringan ZTE" yang
   terpisah — dicek langsung ke API router `test-x86-bajastu` (port API
   custom `49198`, bukan default), device Huawei (`10.1.12.87`) DAN ZTE
   (`10.1.13.229`) sama-sama lease DHCP aktif dari SATU DHCP server
   (`dhcp2`, interface `vlan9-TR069`, pool `10.1.0.0/20` penuh) di router
   yang SAMA — cuma satu NAS/lokasi, bukan dua. v0.7.3 (implementasi,
   lihat CHANGELOG) menyelesaikan ini dengan widen `AllowedIPs` +
   kolom `nas.tr069_management_subnet` + firewall exception baru, bukan
   tunnel/lokasi baru.

**v0.7.3 (GenieACS Connection Request Routing) — implementasi selesai,
verifikasi akhir BELUM dikonfirmasi.** Ini keputusan sadar Agung untuk
lanjut ke v0.7.4 sebelum retest terakhir dijalankan, bukan klaim bahwa
fitur ini sudah terbukti jalan end-to-end. Dibangun: kolom
`nas.tr069_management_subnet`, static IP boss-network untuk
`genieacs-cwmp`/`genieacs-nbi` (`GENIEACS_CWMP_INTERNAL_IP`/
`GENIEACS_NBI_INTERNAL_IP`), widen WireGuard `AllowedIPs` (server+router)
+ firewall exception per-NAS di `docker/wireguard/entrypoint.sh`, dan
perbaikan `MikrotikScriptGenerator` supaya route balik dihasilkan
per-service (FreeRADIUS + GenieACS NBI/CWMP), bukan hardcode satu
service saja.

Tiga bug nyata ditemukan+diperbaiki sepanjang sprint ini (detail teknis
lengkap di CLAUDE.md bagian "GenieACS Core & TR-069 CWMP proxying
gotcha"/gotcha v0.7.3 baru):
1. **Rotasi private key WireGuard tak disadari** — tombol "Cabut &
   Generate Ulang" menghasilkan keypair baru (bukan reuse), sempat
   disalahpahami sebagai "tunnel putus" padahal sebenarnya key lama
   memang sengaja diganti.
2. **Route balik tidak lengkap/salah target** — cut pertama menambahkan
   SATU route ke seluruh `tr069_management_subnet` NAS, ternyata mati
   total di router nyata (connected route ke subnet lokal NAS sendiri
   selalu menang atas static route lewat tunnel). Diganti dengan route
   `/32` per-service (FreeRADIUS, GenieACS NBI, GenieACS CWMP) — pola
   yang sama seperti route FreeRADIUS yang sudah terbukti jalan sejak
   v0.6.2/v0.6.3.
3. **MASQUERADE vs allowed-address tidak sinkron** — ditemukan lewat
   inspeksi langsung state live container `wireguard-node3`: traffic
   GenieACS NBI/CWMP di-MASQUERADE oleh `docker/wireguard/entrypoint.sh`
   ke IP tunnel milik VPN node sendiri (`.1` dari `subnet_cidr`, bukan IP
   asli container GenieACS) sebelum sampai ke router — sehingga
   `allowed-address` peer WireGuard di router HARUS mencantumkan IP
   tunnel node tersebut sebagai source yang diterima, kalau tidak paket
   di-drop di layer kripto WireGuard sebelum sempat diproses RouterOS
   sama sekali. Ini yang paling terakhir ditemukan dan diperbaiki di
   `MikrotikScriptGenerator`/`VpnScriptService` (parameter
   `$vpnNodeTunnelIp`, dihitung via `App\Support\CidrRange::gatewayAddress()`).

**Yang BELUM dikonfirmasi**: retry TCP connectivity test + Connection
Request end-to-end setelah fix #3 di atas diterapkan **belum pernah
dijalankan ulang dan sukses**. Dua task `refreshObject` masih diantre di
GenieACS, belum pernah retry pasca-fix terakhir:
- Huawei EG8141A5 (`00259E-EG8141A5-48575443796B91A7`) — task
  `6a7897028f1edd3ee0656c81`
- ZTE F663NV3a (`F86CE1-F663NV3a-ZICG296C2E7B`) — task
  `6a789984a542ad1c34df1865`

**TODO wajib sebelum v0.7.4 benar-benar mengandalkan fitur ini**: jalankan
ulang tes TCP mentah (`nc -zv` dari `genieacs-nbi` ke kedua device) dan
retry Connection Request kedua task di atas, buktikan Connection Request
benar-benar sampai (bukan cuma tunnel handshake hidup). Sampai itu
dikonfirmasi, anggap fitur ini "implementasi selesai, belum terbukti
jalan" — bukan "selesai". **Amendment saat v0.7.4 ditutup**: TODO ini
sengaja BELUM dikerjakan — Agung memutuskan lanjut ke v0.7.4 duluan,
karena task queue + audit log v0.7.4 tetap berguna dan benar terlepas dari
status v0.7.3 (lihat catatan v0.7.4 di bawah untuk kenapa ini aman).

**v0.7.4 (GenieACS Remote Actions) selesai** — sengaja dibangun dalam mode
"tidak instan" TANPA menunggu TODO v0.7.3 di atas selesai lebih dulu,
keputusan sadar Agung: `App\Services\Network\CpeActionService` (`reboot()`/
`setWifiCredentials()`) selalu menulis `cpe_action_logs` dulu (status
`queued`) sebelum mengirim apa pun ke GenieACS, lalu update ke `delivered`
(task berhasil masuk antrean) atau `failed` (enqueue-nya sendiri yang
gagal — device belum punya `genieacs_device_id`, mapping parameter tidak
ada, atau GenieACS menolak). `GenieAcsClientService::sendTask()` SELALU
mencoba `connection_request` juga (default `true`) — kegagalannya (device
tidak reachable, sama seperti temuan investigasi v0.7.3) tidak pernah
dianggap gagal, cuma berarti perintah baru diterapkan di Inform
berikutnya. Konsekuensi penting: **begitu TODO v0.7.3 di atas selesai dan
Connection Request terbukti jalan, v0.7.4 otomatis jadi instan tanpa
perubahan kode sama sekali** — mekanismenya sudah ada sejak hari pertama
sprint ini, cuma belum pernah berhasil karena v0.7.3 belum terverifikasi.

`cpe_parameter_maps` diperluas dengan `wifi_ssid`/`wifi_password` untuk ZTE
F663NV3.1 — `wifi_ssid` terverifikasi penuh (path + nilai nyata `'RUMAHVIA'`
terbaca dari tree device yang sudah tersimpan). `wifi_password` **sengaja
tidak ditandai verified** — device ini (dan device TR-069 pada umumnya)
selalu mengembalikan string kosong untuk field password saat dibaca
(perilaku keamanan CPE standar, bukan gap discovery), jadi tidak ada nilai
nyata untuk dicocokkan, dan belum ada percobaan `setParameterValues` nyata
yang dikonfirmasi berhasil mengubah password sungguhan. Password sendiri
tidak pernah disimpan plaintext di `cpe_action_logs` — hanya fingerprint
SHA-256 buat keperluan audit "apakah sama dengan perubahan sebelumnya".

**Definition of Done v0.7.4 sengaja TIDAK mencakup "device benar-benar
tereksekusi"** — itu di luar kendali BOSS App sampai Connection Request
v0.7.3 terverifikasi (dan bahkan setelah itu, BOSS App tetap tidak akan
tahu device sudah selesai menjalankan task tanpa mekanisme konfirmasi
device-side yang belum dibangun). Scope yang selesai dan teruji: task
berhasil diantre + tercatat di audit log + UI jujur soal status ini —
implementasi dan 374 test regresi (lalu 391 setelah v0.7.5) hijau.

**Amendment — verifikasi UI ditunda ke akhir cluster, bukan diklaim
selesai** (dikonfirmasi Agung): tombol Reboot/Ganti WiFi di `/cpe-devices`
belum pernah dicoba langsung di browser. Daripada verifikasi UI
sepotong-sepotong per sub-versi, satu sesi tes komprehensif dijadwalkan
sebelum mulai v0.8 — mencakup v0.7.3 (retest Connection Request), v0.7.4
(tombol Reboot/Ganti WiFi ini), DAN v0.7.5 (alur provisioning) sekaligus.
Status tabel di atas karena itu **"Implementasi selesai — verifikasi UI
komprehensif dijadwalkan sebelum v0.8"**, bukan "Selesai" polos — sama
polanya dengan v0.7.3. Branch tetap di-merge ke `develop`/`main` dan
di-tag sekarang supaya branch tidak menumpuk, bukan berarti klaim fully
verified.

**v0.7.5 (GenieACS Auto-Provisioning — SSID/Password saja) selesai** —
scope dipersempit dari rencana awal setelah verifikasi: PPPoE (username/
password RADIUS) TIDAK termasuk, karena kredensial itu ternyata sama
sekali tidak tersimpan di alur instalasi manapun (`work_order_devices`
cuma MAC/serial, tidak ada link balik `radcheck`→work order) — jadi
scope-nya SSID/password WiFi saja, reuse penuh `CpeActionService` (v0.7.4).
Ditemukan juga saat verifikasi: **tidak ada mekanisme input teknisi sama
sekali** — WhatsApp bot masih outbound-only (v0.4.0), Mobile App belum
dibangun (masih v0.11.0 backlog) — jadi v0.7.5 terpaksa mencakup jalur
input CS/admin manual (`PATCH /work-orders/{id}/devices/{device}/
provisioning` + halaman `WorkOrderShow`, Livewire pertama untuk modul
Installation) sebagai BRIDGE sementara, ditandai eksplisit di kode/docs
bukan UI teknisi final.

Push otomatis terjadi lewat `CpeBindingService::provisionWifiIfPending()`,
dipanggil dari DUA titik (`bindFromWorkOrder()` saat binding langsung
online, `reconcilePending()` saat job terjadwal berhasil match) — log
`cpe_action_logs`-nya punya `performed_by` NULL (tidak ada aktor manusia
untuk aksi otomatis ini; `cpe_action_logs.performed_by` sengaja dijadikan
nullable, dikonfirmasi Agung lebih jujur daripada user sistem palsu) +
`parameters.triggered_by` (`auto_provisioning_binding`/
`auto_provisioning_reconciliation`) buat bedain sumbernya di riwayat aksi.
`cpe_devices.wifi_provisioned_at` jadi guard anti-duplikat, hanya ke-set
kalau push benar-benar `delivered` — **tidak ada retry otomatis** kalau
gagal (device yang sudah `online` tidak pernah disentuh lagi oleh
`reconcilePending()`), CS perlu push manual lewat tombol "Ganti WiFi"
v0.7.4 untuk kasus itu.

**Sama seperti v0.7.4**: implementasi + 391 test regresi hijau, tapi UI
(halaman `WorkOrderShow`, alur isi SSID/password) belum pernah dicoba
langsung di browser — masuk sesi verifikasi UI komprehensif yang sama
dengan v0.7.3/v0.7.4 sebelum mulai v0.8.

**v0.7.6 (GenieACS Connected Clients) selesai** — baca object TR-069
`LANDevice.{i}.Hosts.Host.{n}` (client WiFi/LAN yang terhubung ke CPE).
Desain sengaja **histori, bukan snapshot**: `App\Services\Network\
CpeConnectedHostsService::syncFromGenieAcs()` upsert satu baris per
`(cpe_device_id, mac_address)` di `cpe_connected_hosts` — bukan satu baris
per poll (hindari tabel membengkak) — `first_seen_at` diisi sekali,
`last_seen_at` di-update tiap MAC masih muncul, `is_active` jadi `false`
(baris tidak pernah dihapus) begitu MAC yang sebelumnya tercatat hilang
dari satu poll. Command terjadwal `cpe:sync-connected-hosts` (5 menit,
pola sama `cpe:reconcile` v0.7.1) yang memanggilnya — **tidak pernah
memicu `refreshObject`/Connection Request sendiri**, cuma baca data yang
sudah tersimpan GenieACS, sama posture seperti discovery v0.7.4/v0.7.5.

Field standar `Active`/`HostName`/`IPAddress`/`MACAddress` dikonfirmasi
ada di DUA vendor nyata sebelum migration ditulis (bukan diasumsikan dari
satu device) — ZTE F663NV3.1 (5 host, `HostName` terisi semua) dan Huawei
EG8141A5 (2 host, plus field vendor `X_HW_*` yang tidak ada di ZTE).
Ditemukan juga: **nomor instance `Host.{n}` TIDAK stabil/tidak
berurutan** antar device (ZTE: 7/10/11/67/68, Huawei: 1/2) — makanya
`mac_address`, bukan `{n}`, jadi satu-satunya kunci identitas yang aman,
sesuai unique constraint tabel ini sejak awal desain.

**Temuan sampingan saat Langkah 0 (v0.7.6), di luar scope sprint ini —
dicatat buat sesi verifikasi komprehensif nanti, TIDAK dikejar sekarang
sesuai arahan eksplisit**: tree GenieACS milik Huawei EG8141A5 ternyata
sudah jauh lebih lengkap dari investigasi v0.7.3/v0.7.4 terakhir (dulu
cuma 8 parameter leaf tanpa `WLANConfiguration`/`Hosts` sama sekali,
sekarang ribuan leaf termasuk keduanya, `_lastInform` jauh lebih baru).
**Ini bisa jadi sinyal Connection Request v0.7.3 sebenarnya sudah mulai
jalan** (device dapat tree lebih lengkap dari Inform biasa atau dari
Connection Request yang berhasil — belum dibedakan) — **belum
dikonfirmasi**, jangan diasumsikan v0.7.3 terbukti selesai hanya dari
temuan ini. Relevan untuk jadi titik awal saat sesi tes komprehensif
v0.7.3-v0.7.6 nanti.

Implementasi + 404 test regresi hijau, tapi UI (tombol "Client", modal
tabel host) belum pernah dicoba langsung di browser — masuk sesi
verifikasi yang sama dengan v0.7.3-v0.7.5 sebelum mulai v0.8.

**Dependency wajib untuk v0.3.4** (dicatat saat v0.3.2 selesai): tabel
`subscriptions` yang lahir di v0.3.4 **harus** langsung menyertakan kolom
`reseller_id` (didenormalisasi dari `customers.reseller_id` saat subscription
dibuat, sama seperti pola yang sudah dipakai di `customers`) dan
`reseller_package_pricing_id`, diisi otomatis di Service layer — bukan
ditambahkan belakangan lewat migration alter terpisah. v0.3.2 sengaja tidak
menyentuh `subscriptions` sama sekali karena tabel itu belum ada di titik ini
(lihat `CHANGELOG.md` v0.3.2 untuk detail keputusan ini).

**Dependency wajib untuk v0.3.4** (dicatat saat v0.3.3 selesai): saat
`InvoiceService` dibuat, setiap invoice **harus** memanggil
`TaxCalculationService::calculateForAmount()` lalu
`TaxCalculationService::writeLedgerEntry()` — kontrak integrasi lengkap
(urutan panggilan, tipe parameter, contoh kode) didokumentasikan di
CLAUDE.md bagian "Tax engine integration contract (v0.3.4)". Tidak perlu
migration tambahan untuk ini — `reseller_tax_ledger.reference_type`/
`reference_id` sudah polymorphic generic sejak v0.3.3, tinggal diisi.
Dipenuhi di v0.3.4 — lihat `InvoiceService::generateForPeriod()`.

**Known limitation dari v0.3.4 (sengaja di-defer, bukan silent gap)**: tidak
ada proration. Subscription yang mulai/berhenti di tengah periode billing
tetap ditagih penuh satu periode. Kalau proration dibutuhkan, itu scope
sprint terpisah (kandidat: v0.3.5 atau versi Billing & Finance sesudahnya),
bukan retrofit diam-diam ke `InvoiceService::generateForPeriod()`.

**Dependency untuk v0.3.5 (Payment Gateway)**: `Invoice.status` sudah punya
state machine lengkap (`draft → pending → paid/overdue`, semua bisa
`cancelled`) via `InvoiceService`/`App\Enums\InvoiceStatus` — webhook
payment gateway v0.3.5 tinggal memanggil `InvoiceService::markPaid()`,
jangan `update(['status' => ...])` manual (melewati validasi transisi).

**Catatan teknis penting untuk SEMUA sprint berikutnya yang menambah query
tanggal** (ditemukan sebagai bug nyata saat membangun v0.3.4, mempengaruhi
kode v0.3.3 yang sudah ter-tag juga — lihat CLAUDE.md bagian "Cross-database
date comparison gotcha" untuk detail lengkap): jangan pernah
`->where('kolom_date', '<=', $tanggal->toDateString())` untuk kolom ber-cast
`'date'` — pakai `->whereDate(...)` selalu. SQLite (dipakai test suite) bisa
menyimpan kolom `date` dengan sufiks waktu, sehingga perbandingan string
biasa gagal persis di titik tanggal yang sama persis.

**Keputusan v0.3.5 yang perlu diketahui sprint berikutnya**: endpoint manual
`PATCH /api/v1/invoices/{invoice}/paid` (dari v0.3.4) **sengaja dipertahankan**
meski v0.3.5 membangun jalur otomatis via `PaymentService::handleWebhook()` —
dikonfirmasi eksplisit oleh Agung, bukan oversight. Konsekuensinya:
`InvoiceService::markPaid()` punya DUA jalur pemanggil yang sah (manual admin
tanpa verifikasi pembayaran, dan webhook Xendit dengan verifikasi penuh),
bukan cuma satu — kalau sprint berikutnya butuh audit trail lebih ketat
untuk pembayaran manual (mis. mewajibkan `payments` row juga dibuat utk
pembayaran manual, bukan cuma `update(['status'=>'paid'])` polos), itu
scope baru yang perlu dikonfirmasi eksplisit, dicatat sebagai backlog di
sini, bukan retrofit diam-diam.

**Out-of-scope v0.3.5, di-declare eksplisit sebagai backlog** (jangan
diasumsikan otomatis ada): WhatsApp payment notification (Baileys, nunggu
v0.4.0), auto-refund otomatis, retry payment flow (UI/service), partial
payment/cicilan, proration subscription (masih deferred dari v0.3.4 juga).

**Amendment v0.3.5 Fase H (dikonfirmasi Agung sebelum commit pertama, jadi
masuk branch yang sama, bukan sprint baru)**: UI Pengaturan > Payment
Gateway ditambahkan (ala referensi MikRadius) — mengubah dua kontrak inti
dari catatan lama di atas:
- `payments.channel_type` **bukan lagi** enum tetap `App\Enums\PaymentChannelType`
  (3 case: virtual_account/qris/invoice) — enum itu sudah dihapus. Sekarang
  varchar yang mereferensi `code` di katalog baru `payment_gateway_channels`
  (`code`/`label`/`category`/`enabled`, admin-managed lewat UI, tanpa hard FK
  — divalidasi di `PaymentService`). Menambah channel baru sekarang murni
  data (row baru di katalog), TAPI integrasi Xendit-nya (method baru di
  `XenditGatewayService` + arm baru di `PaymentService::createPaymentFor()`
  match-nya) tetap perlu kode — katalog v0.3.5 sudah memuat channel ewallet/
  retail_outlet/credit_card (OVO, DANA, Alfamart, dst) untuk checklist UI,
  TAPI belum ada integrasi API-nya (hanya bank_transfer_va/qris/invoice yang
  benar-benar bisa dipakai `createPaymentFor()`). Jangan asumsikan channel
  yang di-enable di checklist otomatis bisa dipakai — cek dulu.
- Kredensial Xendit (`XENDIT_SECRET_KEY`/`XENDIT_CALLBACK_TOKEN`) **pindah
  sumber runtime** dari `.env` ke `payment_gateway_settings` (DB, encrypted,
  singleton row id=1) via `PaymentGatewaySettingsService`. `.env` cuma
  dipakai sekali lagi lewat command manual `payment-gateway:import-env`
  untuk migrasi kredensial Fase A-G yang sempat di-set. Permission
  `payment_gateway_settings.*` super_admin-only (lebih ketat dari
  `invoices.*` yang juga dipegang `billing`), karena halaman ini memegang
  secret asli.

Detail lengkap ada di CLAUDE.md bagian "Payment gateway (Xendit, v0.3.5)".

## v0.4.0 — WhatsApp Gateway (Baileys, multi-sesi per reseller)

Deferred item dari v0.3.5 (WhatsApp payment notification) selesai di sprint
ini via hook `InvoiceService::markPaid()` -> `WhatsappGatewayService::buildAndQueue('payment_received', ...)`.

**Keputusan arsitektur final (jangan diasumsikan lain tanpa konfirmasi
ulang)**: satu nomor WhatsApp per reseller + satu sesi "direct" untuk
customer tanpa reseller; Node.js `whatsapp-gateway` container terpisah,
multi-session (Baileys, key = `session_key` = reseller_id sebagai string
atau literal `"direct"`); komunikasi Laravel<->Node via HTTP internal +
HMAC-SHA256 (bukan static token seperti Xendit) dengan timestamp + toleransi
5 menit; auth state per sesi di volume `whatsapp-gateway/auth_state/{session_key}/`;
outbound-only sprint ini (two-way/Chatwoot dideferred); template pesan bisa
di-override per reseller per event_type, fallback ke default ISP-level;
pengiriman lewat queue Redis bernama `whatsapp-{session_key}` (bukan satu
queue global) supaya sesi reseller yang disconnect tidak menghambat reseller
lain; rate limit (delay antar pesan, batch size, jeda antar batch, jadwal
batch harian) global untuk semua sesi, dikelola admin ISP.

**Gap yang ditemukan & keputusan yang diambil saat membangun (dikonfirmasi
Agung sebelum migration dijalankan)**:
- Flow registrasi pelanggan (`RegistrationService::register()`, v0.3.0)
  **tidak pernah mengisi `customer.reseller_id`** — jadi notifikasi
  `customer_registered` SELALU resolve ke sesi `"direct"`, tidak pernah ke
  sesi reseller manapun, walau secara bisnis ada reseller yang menaungi.
  Diterima apa adanya untuk sprint ini (bukan bug baru — behavior registrasi
  v0.3.0 tidak diubah, di luar scope v0.4.0). Kalau ini perlu diperbaiki
  (registrasi bisa atribusi reseller), itu scope baru untuk sprint terpisah.
- Tidak ada kolom `invoice_url`/`payment_link` tersimpan di mana pun
  (`payments.raw_response` jsonb ada tapi dari channel VA/QRIS tidak
  memilikinya, dan invoice belum tentu punya `Payment` row saat reminder
  dikirim). `{payment_link}` di template `invoice_due_reminder` di-generate
  **on-demand** saat `buildAndQueue()` dipanggil, lewat
  `PaymentService::createPaymentFor($invoice, 'XENDIT_INVOICE')` — kalau
  gagal (channel belum aktif, dsb), reminder tetap terkirim tanpa link
  (di-log sebagai warning, tidak pernah membatalkan pengiriman).
- `app/.env.example` (template dev non-Docker) masih `QUEUE_CONNECTION=database`
  sementara root `.env.example` (template Docker Compose yang sungguhan
  dipakai container) sudah benar `redis` sejak v0.1.0 — **bukan bug baru**,
  hanya `app/.env.example`-nya yang diselaraskan supaya konsisten dengan
  tech stack yang didokumentasikan; tidak ada perubahan pada deployment
  sungguhan.
- `whatsapp-{session_key}` adalah queue name dinamis (satu per reseller +
  "direct") yang tidak bisa dienumerasi statis di flag `--queue=` biasa.
  Container terpisah `boss-whatsapp-worker` menjalankan
  `whatsapp:queue-names` (artisan command baru) untuk membangun daftar queue
  saat ini, lalu restart `queue:work` tiap 5 menit (`--max-time=300`) supaya
  sesi reseller baru otomatis ikut terdengar tanpa restart manual — pola
  polling-restart yang sama dengan `boss-scheduler`.
- Literal session_key `"direct"` hanya unik selama deployment ini melayani
  SATU tenant ISP (asumsi operasional saat ini, sama seperti
  `payment_gateway_settings` — lihat CLAUDE.md). Kalau ini benar-benar jadi
  SaaS multi-tenant dengan banyak ISP berbagi satu `whatsapp-gateway`
  container, `"direct"` perlu diganti jadi key yang mengandung tenant_id —
  dicatat sebagai known limitation, bukan dikerjakan sekarang.

**Out-of-scope v0.4.0, di-declare eksplisit sebagai backlog**: two-way
messaging + integrasi Chatwoot (1 nomor gabungan WA + CS), overdue reminder
berulang (sengaja TIDAK ada — sekali lewat H-0 tanpa bayar, tidak ada
notifikasi WA lanjutan untuk invoice itu), notifikasi work order
teknisi/outage alert (nunggu modul teknisi/monitoring), rate limit
per-reseller (sprint ini rate limit global saja), auto-refund/retry payment
flow/partial payment/proration subscription (tetap deferred dari v0.3.4/
v0.3.5, belum masuk scope manapun).

Detail lengkap ada di CLAUDE.md bagian "WhatsApp Gateway (Baileys, v0.4.0)".

## v0.5.0 — Installation (Work Order Teknisi)

**Keputusan arsitektur final**: `odps`/`technicians`/`work_orders` tenant-scoped +
`reseller_id` nullable (null = milik ISP A langsung), pola identik dengan
`whatsapp_sessions`/`customers`. `OdpLocatorService::findNearestAvailable()` pakai
Haversine murni raw SQL (bukan PostGIS) — diverifikasi bekerja identik di SQLite
(driver test suite) maupun Postgres, termasuk fungsi `acos`/`radians`/`cos`/`sin`
yang ternyata tersedia di build SQLite PHP environment ini. `WorkOrderStatus`
adalah state machine linear (`pending_odp_check -> pending_verification -> ready
-> assigned -> in_progress -> completed`, dengan `odp_unavailable` sebagai
dead-end dari `pending_odp_check`, dan `cancelled` bisa dicapai dari status
non-terminal manapun) — tidak bisa loncat state (mis. `assigned -> completed`
tanpa `in_progress` ditolak dengan `InvalidWorkOrderStatusTransitionException`).

**Gap yang ditemukan & diputuskan saat membangun**:
- Migration `odps`/`odp_ports` tidak menyediakan endpoint terpisah untuk
  generate baris `odp_ports` — kalau tidak ditambal, sebuah ODP yang baru
  dibuat tidak akan pernah punya port sama sekali (tidak bisa dipakai
  `OdpLocatorService`). Ditambal dengan `Odp::provisionPorts()` (method
  eksplisit di model, BUKAN model event `created`) yang otomatis membuat
  port 1..`total_ports` berstatus `available`, dipanggil dari
  `OdpController::store()`. Sengaja bukan event `created` supaya
  `Odp::factory()->create()` di test tetap bisa dipakai independen dari
  `OdpPortFactory` tanpa collision di constraint `unique(odp_id,
  port_number)`.
- `WorkOrderService::complete()` awalnya mengecek kelengkapan foto/device
  SEBELUM mengecek legalitas transisi status — ditemukan lewat test sendiri
  ("illegal transition ... is rejected" gagal karena melempar
  `IncompleteWorkOrderException` duluan, bukan
  `InvalidWorkOrderStatusTransitionException`). Diperbaiki: legalitas
  transisi dicek PALING DULU, baru validasi kelengkapan foto/device.
- Disk `'local'` di Laravel 12 root-nya `storage/app/private/`, bukan bare
  `storage/app/` seperti diasumsikan di draft awal spec — detail versi
  framework, tidak mengubah keputusan "pakai disk local" itu sendiri.

**Out-of-scope v0.5.0, di-declare eksplisit sebagai backlog**: modul stok/
inventory barang riil (field `equipment_ready` di `work_orders` masih
placeholder manual, bukan dari data stok sungguhan), notifikasi WhatsApp
otomatis untuk work order (menyusul, terintegrasi dengan modul WhatsApp
Gateway v0.4.0 yang sudah ada), UI scan kamera html5-qrcode (endpoint API
`POST /work-orders/{id}/devices` sudah siap menerima hasil scan, komponen
kamera browser menyusul).

Detail lengkap ada di CLAUDE.md bagian "Installation / Work Order (v0.5.0)".
