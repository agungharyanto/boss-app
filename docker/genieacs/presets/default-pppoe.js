// v0.9.5 (fix parameter PPPoE, 2026-09-02) — nilai leaf koneksi PPPoE
// pelanggan, disimpan di provision SENDIRI (alasan isolasi sama seperti
// default-optical.js): sebuah fault pada path WAN spekulatif di sini tidak
// boleh menghentikan declare SSID/Hosts/MAC yang sudah andal di "default".
//
// Penemuan-ulang INSTANCE WANPPPConnection sudah jadi tugas "default"
// (declare `{path: minutes}` pada objek wildcard `...WANPPPConnection`).
// Provision ini HANYA menambah refresh NILAI untuk lima leaf yang dibaca
// halaman Detail Perangkat CPE + App\Services\Network\
// CpeParameterResolverService (resolvePppoeConnection() /
// resolveWanConnectionsSummary()):
//   Username, ConnectionStatus, ExternalIPAddress, Uptime, Name
//
// `{value: hourly}` SAJA — bukan `{path: minutes}`. Penemuan instance
// adalah tugas "default", jadi ini cuma ~5 pembacaan leaf per pasangan
// (WANConnectionDevice, WANPPPConnection) yang SUDAH diketahui — tidak
// memicu penelusuran ulang pohon. Scoped ke
// WANConnectionDevice.*.WANPPPConnection.* — SENGAJA BUKAN root refresh
// (jebakan `too_many_commits` yang terus dijumpai investigasi GenieACS
// ini; lihat CLAUDE.md).
//
// Wildcard `WANConnectionDevice.*` (bukan indeks di-hardcode 1/2 seperti
// declare MACAddress lama di default.js) — dikonfirmasi terhadap
// F86CE1-F663NV3a nyata: koneksi INTERNET pelanggan yang asli ada di
// WANConnectionDevice.6.WANPPPConnection.1 (Username "0882005790505",
// ExternalIPAddress "10.1.130.175"), sementara WANConnectionDevice.4
// adalah VLAN "Other" dengan Username kosong — resolver sudah memilih
// instance ber-Username pertama, provision ini tinggal menjaga nilainya
// segar untuk SEMUA instance, indeks berapa pun.
const hourly = Date.now() - 3590000;

declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Username", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.ConnectionStatus", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.ExternalIPAddress", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Uptime", {value: hourly});
declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Name", {value: hourly});
