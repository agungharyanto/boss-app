# Auto-WAN provision — script referensi (rekan Agung)

`auto_setup_wan_pppoe.js` (WAN1 = internet PPPoE) dan `auto_setup_wan_bridge.js`
(WAN2 = bridge kedua) adalah provision GenieACS milik rekan Agung, dipakai
sebagai **acuan** saat membangun `app/resources/genieacs/default-wan.js`
(branch `genieacs-auto-wan-configurable`).

Perbedaan utama versi BOSS App:
- VLAN (`targetVlan` 1000 / `targetVlanWan2` 1200) dan username/password default
  PPPoE **TIDAK lagi hardcoded** — datang dari `args` preset yang ditulis
  `App\Services\Network\GenieAcsPresetService` dari baris singleton
  `remote_wan_configs` (halaman "Konfig Remote").
- Kedua script digabung jadi SATU provision `default-wan` dengan blok WAN1 +
  WAN2, dijaga master switch `enabled`.
- Deteksi vendor (Huawei via `X_HW_SerialNumber`, CMCC via
  `X_CMCC_UserInfo.ServiceName`, ZTE generic via
  `X_ZTE-COM_WANPONInterfaceConfig.RXPower`), struktur WANConnectionDevice.1
  instance ke-2, dan guard idempoten dipertahankan PERSIS dari sini.

Disimpan di git (BOSS-001) untuk jejak asal-usul, bukan file yang dijalankan.
