# Inventaris Namespace Router `test-x86-bajastu` — acuan hindari collision

> **v0.14.7 Langkah 0.** Dibaca **read-only** langsung dari router
> `test-x86-bajastu` (NAS id=1 di tabel `nas`) pada **2026-09-01**.
> **Tidak ada satu pun object router yang diubah/dihapus/dibuat** saat
> pengambilan data ini — semua query adalah `/.../print` (setara Winbox
> "read" / CLI `print`).
>
> **Tujuan file ini:** setiap nama `/ppp profile`, `/ip pool`,
> `/interface pppoe-server server`, dan setiap range IP yang dipakai
> BOSS App (sistem Profil Paket) ke `test-x86-bajastu` untuk **pelanggan
> baru** WAJIB dicek terhadap daftar di bawah supaya **tidak collision**
> dengan 219 sesi PPPoE existing (sistem lama mixradius/local secret).
>
> **295 pelanggan existing TIDAK dimigrasikan** — mereka tetap di sistem
> lama. File ini murni panduan pencegahan tabrakan untuk pelanggan baru.

## Cara pengambilan data (command persis)

Semua lewat `RouterOS\Client` (`evilfreelancer/routeros-api-php`, transport
raw socket API) dari dalam container `boss-app`, kredensial dari baris
`nas` id=1 (`mikrotik_ip=144.79.52.0`, `api_port=49198`,
`api_username=boss-app-api-1`). Query yang dijalankan:

| Data | Query RouterOS API | Setara CLI |
|---|---|---|
| Konektivitas + versi | `/system/resource/print` | `/system resource print` |
| Identity | `/system/identity/print` | `/system identity print` |
| **Baseline sesi aktif** | `/ppp/active/print` lalu `count()` array hasil | `/ppp active print count-only` |
| /ppp profile | `/ppp/profile/print` | `/ppp profile print` |
| /ip pool | `/ip/pool/print` | `/ip pool print` |
| /interface pppoe-server server | `/interface/pppoe-server/server/print` | `/interface pppoe-server server print` |
| /interface | `/interface/print` | `/interface print` |
| /interface vlan | `/interface/vlan/print` | `/interface vlan print` |
| Policy API user | `/user/print` + `/user/group/print` (filter `name`) | `/user print` / `/user group print` |

## Ringkasan router

| Field | Nilai |
|---|---|
| RouterOS | 7.11 (stable), board x86 |
| Identity | `ro-x86-kaliwungu.bajastu.id` |
| Uptime saat cek | 2w6h44m41s |
| API user BOSS App | `boss-app-api-1` → group `boss-app-api` |
| Policy group `boss-app-api` | `local,telnet,ssh,read,write,policy,test,winbox,password,web,sniff,sensitive,api,romon,rest-api,!ftp,!reboot` — **`write` tersedia** (dulu `!write` per catatan v0.6.5, sudah diperluas) |

## BASELINE — sesi PPPoE aktif (untuk verifikasi before/after Langkah 1)

**`/ppp/active/print` → 219 entri** pada 2026-09-01.

Cara hitung: hasil `/ppp/active/print` adalah array; jumlah elemen array =
jumlah sesi aktif. (Angka 295 yang sering disebut = jumlah baris
`radcheck` di `radius_db`, BUKAN yang sedang online.)

Sample 5 entri pertama (nama = nomor HP pelanggan, `service=pppoe`):

| name | address | uptime |
|---|---|---|
| 085712355470 | 10.0.110.15 | 1w2d15h34m |
| 082114567449 | 10.1.130.181 | 5d20h12m |
| 081261764888 | 10.1.130.176 | 3d23h34m |
| 088215661994 | 10.1.130.205 | 3d23h30m |
| 083840811279 | 10.1.110.34 | 3d23h30m |

Langkah 1 (kalau nanti diizinkan): hitung `/ppp/active/print` **sebelum**
push, **sesudah** push, **sesudah** hapus — ketiganya harus ≈219 (fluktuasi
kecil normal karena pelanggan connect/disconnect sendiri; yang dicari:
tidak ada drop mendadak / massal).

---

## 1. `/ppp profile` — 18 profile (SEMUA milik sistem lama, JANGAN disentuh)

| name | local-address | remote-address | comment |
|---|---|---|---|
| `default` | — | — | — |
| `expired users` | 10.127.0.1 | — | added by mixradius - expired users |
| `PPPOE-REMOTE` | 10.0.1.1 | PPPOE-REMOTE | added by mixradius |
| `HomeFixed-5Mbps` | 10.0.105.1 | HomeFixed-5Mbps | added by mixradius |
| `HomeFixed-10Mbps` | 10.0.110.1 | HomeFixed-10Mbps | added by mixradius |
| `HomeFixed-20Mbps` | 10.0.120.1 | HomeFixed-20Mbps | added by mixradius |
| `HomeFixed-30Mbps` | 10.0.130.1 | HomeFixed-30Mbps | added by mixradius |
| `HomeFixed-40Mbps` | 10.0.140.1 | HomeFixed-40Mbps | added by mixradius |
| `vpn` | HomeFixed-150Mbps-Loyalis | HomeFixed-150Mbps-Loyalis | — |
| `HomeFixed-30Mbps-Loyalis` | 10.1.130.1 | HomeFixed-30Mbps-Loyalis | added by mixradius |
| `HomeFixed-50Mbps-Loyalis` | 10.1.150.1 | HomeFixed-50Mbps-Loyalis | added by mixradius |
| `HomeFixed-100Mbps-Loyalis` | 10.1.100.1 | HomeFixed-100Mbps-Loyalis | added by mixradius |
| `HomeFixed-50Mbps` | 10.0.150.1 | HomeFixed-50Mbps | added by mixradius |
| `HomeFixed-100Mbps` | 10.0.100.1 | HomeFixed-100Mbps | added by mixradius |
| `HomeFixed-150Mbps-Loyalis` | 10.1.250.1 | HomeFixed-150Mbps-Loyalis | added by mixradius |
| `HomeFixed-10Mbps-Loyalis` | 10.1.110.1 | HomeFixed-10Mbps-Loyalis | added by mixradius |
| `OVPN-SmartOLT` | — | — | — |
| `default-encryption` | — | — | — |

**Tidak ada satu pun profile dengan comment berpola `BOSS App - ...`**
→ konvensi lookup-by-comment BOSS App (`BOSS App - PPP Package #{id}`,
`BOSS App - Network Profile Group #{id}`, dst) aman, nol tabrakan.

### Aturan penamaan untuk pelanggan baru (BOSS App)
- **JANGAN** pakai nama `default`, `default-encryption`, `PPPOE-REMOTE`,
  `expired users`, `vpn`, `OVPN-SmartOLT`, atau apa pun berawalan
  `HomeFixed-` (semua di atas milik mixradius/existing).
- Nama profile BOSS App datang dari `NetworkProfileGroup.name` /
  `PppPackage.name` — pastikan admin memberi nama yang jelas berbeda,
  mis. prefiks `BOSS-` atau nama paket internal.
- Comment BOSS App (`BOSS App - ...`) adalah lookup key — jangan diubah
  manual di router.

---

## 2. `/ip pool` — 24 pool

| name | ranges |
|---|---|
| `PARENT TOKEN 4JAM` | 172.16.10.20-172.16.10.254 |
| `PARENT TOKEN 12JAM` | 172.16.9.20-172.16.9.254 |
| `PARENT TOKEN 24JAM` | 172.16.8.20-172.16.8.254 |
| `PARENT TOKEN 1HP` | 172.16.4.10-172.16.4.254, 172.16.5.10-172.16.5.254, 172.16.6.10-172.16.6.254 |
| `PARENT TOKEN 2HP` | 172.16.5.20-172.16.5.253 |
| `PARENT TOKEN 4HP` | 172.16.6.20-172.16.6.254 |
| `PARENT TOKEN 5HP` | 172.16.7.20-172.16.7.254 |
| `PPPOE-REMOTE` | 10.0.1.3-10.0.3.254 |
| `HomeFixed-5Mbps` | 10.0.105.2-10.0.105.254 |
| `HomeFixed-10Mbps` | 10.0.110.2-10.0.110.254 |
| `HomeFixed-20Mbps` | 10.0.120.2-10.0.120.254 |
| `HomeFixed-30Mbps` | 10.0.130.2-10.0.130.254 |
| `PARENT TOKEN 1Minggu` | 172.16.11.10-172.16.11.254 |
| `HomeFixed-40Mbps` | 10.0.140.2-10.0.140.254 |
| `dhcp_pool15` | 10.1.0.2-10.1.15.254 |
| `dhcp_pool16` | 172.16.12.10-172.16.12.254 |
| `HomeFixed-30Mbps-Loyalis` | 10.1.130.2-10.1.130.254 |
| `HomeFixed-50Mbps-Loyalis` | 10.1.150.2-10.1.150.254 |
| `HomeFixed-100Mbps-Loyalis` | 10.1.100.2-10.1.100.254 |
| `HomeFixed-50Mbps` | 10.0.150.10-10.0.150.254 |
| `HomeFixed-100Mbps` | 10.0.100.10-10.0.100.254 |
| `dhcp_pool22` | 10.79.52.20-10.79.52.253 |
| `HomeFixed-150Mbps-Loyalis` | 10.1.250.10-10.1.250.254 |
| `HomeFixed-10Mbps-Loyalis` | 10.1.110.10-10.1.110.254 |

### Range IP yang SUDAH terpakai (JANGAN overlap)
- `10.0.0.0` – `10.0.3.254`, `10.0.100.x`–`10.0.150.x` (blok `/24` per profile)
- `10.1.0.0` – `10.1.15.254`, `10.1.100.x`–`10.1.250.x`
- `10.79.52.20` – `10.79.52.253`
- `10.127.0.1` (local-address `expired users`)
- `172.16.4.0` – `172.16.12.254` (token/hotspot)
- Manajemen/TR-069/OLT: `10.1.0.0/20` (TR-069), `10.168.100.0/24` (OLT) —
  dari catatan v0.7.3/v0.8.1, lewat tunnel.

### Range AMAN untuk pelanggan baru BOSS App
- **`192.0.2.0/24` (RFC 5737 TEST-NET-1) TIDAK dipakai sama sekali** di
  router ini → dipakai untuk uji coba Langkah 1 (`192.0.2.0/29`).
- Untuk produksi pelanggan baru: pilih blok `10.x` / `172.x` yang jelas
  di luar daftar di atas, dokumentasikan di sini setiap kali menambah.
- Nama pool BOSS App datang dari `CustomerIpPool.name` — pastikan unik,
  jangan `HomeFixed-*` / `PARENT TOKEN *` / `dhcp_pool*` / `PPPOE-REMOTE`.

---

## 3. `/interface pppoe-server server` — 9

| service-name | interface | default-profile | disabled |
|---|---|---|---|
| `PPPoE-Vlan110-10Mbps` | vlan110-PPPoE-10Mbps | HomeFixed-10Mbps | no |
| `service2` | vlan10-PPPoE | PPPOE-REMOTE | **yes** |
| `PPPoE-Vlan130-30Mbps` | vlan130-PPPoE-30Mbps | HomeFixed-30Mbps | no |
| `PPPoE-Vlan120-20Mbps` | vlan120-PPPoE-20Mbps | HomeFixed-20Mbps | no |
| `PPPoE-Vlan140-40Mbps` | vlan140-PPPoE-40Mbps | HomeFixed-40Mbps | no |
| `PPPoE-30Mbps-Loyalis` | vlan131-PPPoE-30Mbps-Loyalis | HomeFixed-30Mbps-Loyalis | no |
| `PPPoE-50Mbps-Loyalis` | vlan151-PPPoE-50Mbps-Loyalis | HomeFixed-50Mbps-Loyalis | no |
| `PPPoE-100Mbps-Loyalis` | vlan101-PPPoE-100Mbps-Loyalis | HomeFixed-100Mbps-Loyalis | no |
| `PPPoE-10Mbps-Loyalis` | vlan111-PPPoE-10Mbps-Loyalis | HomeFixed-10Mbps-Loyalis | no |

**Aturan:** BOSS App `NetworkProfileGroup` (tipe PPP) yang membuat PPPoE
Server memakai lookup-by-comment (`BOSS App - Network Profile Group #{id}`)
— nol tabrakan dengan 9 di atas (semuanya tanpa comment BOSS App). Nama
`service-name` dari BOSS App harus unik; jangan `PPPoE-Vlan*` /
`PPPoE-*-Loyalis` / `service2`.

---

## 4. Interface

### 4a. Ether fisik + bridge/tunnel utama

| name | type | running |
|---|---|---|
| `SFP+1` | ether | yes |
| `SFP+2` | ether | yes |
| `ether1 - IN SW` | ether | yes |
| `ether2 - OUT SW` | ether | yes |
| `ether3` | ether | yes |
| `ether4` | ether | yes |
| `SmartOLT-VPN` | ovpn-out | yes |
| `boss-vpn-wireguard` | wg | yes |
| `br-loopback` | bridge | yes |

(+ 219 interface dinamis `<pppoe-...>` type `pppoe-in` = sesi pelanggan
aktif — tidak dicatat individual, itu yang harus TIDAK terganggu.)
Total `/interface print` = 244.

### 4b. `/interface vlan` — 16

| name | vlan-id | interface |
|---|---|---|
| `vlan9-TR069` | 9 | ether2 - OUT SW |
| `vlan10-PPPoE` | 10 | ether2 - OUT SW (running=false) |
| `vlan69-MNG` | 69 | ether2 - OUT SW |
| `vlan101-PPPoE-100Mbps-Loyalis` | 101 | ether2 - OUT SW |
| `vlan105-PPPoE-5Mbps` | 105 | ether2 - OUT SW |
| `vlan110-PPPoE-10Mbps` | 110 | ether2 - OUT SW |
| `vlan111-PPPoE-10Mbps-Loyalis` | 111 | ether2 - OUT SW |
| `vlan120-PPPoE-20Mbps` | 120 | ether2 - OUT SW |
| `vlan130-PPPoE-30Mbps` | 130 | ether2 - OUT SW |
| `vlan131-PPPoE-30Mbps-Loyalis` | 131 | ether2 - OUT SW |
| `vlan140-PPPoE-40Mbps` | 140 | ether2 - OUT SW |
| `vlan151-PPPoE-50Mbps-Loyalis` | 151 | ether2 - OUT SW |
| `vlan151-PPPoE-150Mbps-Loyalis` | **251** | ether2 - OUT SW *(nama vlan151 tapi vlan-id 251)* |
| `vlan172-Hotspot` | 172 | ether2 - OUT SW |
| `vlan999-ULO` | 999 | ether2 - OUT SW |
| `vlan1709-Anten-via-LA` | 1709 | ether1 - IN SW |

**Untuk pelanggan baru BOSS App:** VLAN existing di atas semua sudah
terikat ke PPPoE Server + profile sistem lama. Kalau pelanggan baru butuh
VLAN sendiri, buat VLAN baru dengan `vlan-id` di luar daftar
{9,10,69,101,105,110,111,120,130,131,140,151,172,251,999,1709} dan nama
yang jelas berbeda. **v0.14.7 tidak membuat VLAN apa pun** (out of scope,
manual oleh admin jaringan).

---

## Kesimpulan Langkah 0

- ✅ Kredensial API `test-x86-bajastu` valid & konek; group punya `write`.
- ✅ Konvensi comment BOSS App (`BOSS App - ...`) nol tabrakan dengan
  18 profile / 24 pool / 9 pppoe-server existing.
- ✅ Range `192.0.2.0/29` (TEST-NET-1) dikonfirmasi kosong → aman untuk
  uji coba Langkah 1.
- ✅ Baseline 219 sesi PPPoE aktif tercatat (via `/ppp active print`).
- ⚠️ Nama pool/profile BOSS App datang dari input admin — file ini jadi
  checklist wajib agar nama itu tidak `HomeFixed-*` / `PARENT TOKEN *` /
  `PPPoE-Vlan*` / dll.
