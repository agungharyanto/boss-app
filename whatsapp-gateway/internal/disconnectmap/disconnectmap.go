// Package disconnectmap mendokumentasikan secara EKSPLISIT pemetaan
// Baileys DisconnectReason -> perilaku whatsmeow, sesuai instruksi
// investigasi migrasi ("WAJIB dibuat eksplisit, jangan asumsi 1:1").
//
// TEMUAN: Baileys `DisconnectReason` dan whatsmeow
// `events.ConnectFailureReason` sama-sama berasal dari atribut statusCode
// pada stanza <stream:error> yang dikirim SERVER WhatsApp sendiri (protokol
// WhatsApp Web multi-device yang sama, dua implementasi client berbeda) —
// bukan nilai bebas masing-masing library. Karena itu beberapa kode
// numeriknya memang cocok (401 = logged out di keduanya, 403 = forbidden/
// MainDeviceGone di keduanya) — TAPI TIDAK SEMUA kode Baileys punya
// padanan whatsmeow bernama identik:
//   - DisconnectReason.badSession (500) — TIDAK ADA di whatsmeow
//     ConnectFailureReason. Baileys sendiri men-treat ini SAMA seperti
//     loggedOut (401) — wipe + re-pair — jadi tidak ada regresi perilaku
//     dengan memetakannya ke BehaviorRequireRepair juga di sini.
//   - DisconnectReason.restartRequired (515), connectionClosed (428),
//     connectionLost/timedOut (408) — bukan kegagalan PAIRING/AUTH,
//     melainkan disconnect transien biasa. Di whatsmeow ini muncul sebagai
//     event STRUKTURAL berbeda (events.Disconnected{} polos,
//     events.KeepAliveTimeout{}), bukan lewat ConnectFailureReason sama
//     sekali — classifier di sini HANYA untuk kode yang lewat
//     events.ConnectFailure/events.LoggedOut.
//   - DisconnectReason.connectionReplaced (440) — padanannya BUKAN sebuah
//     kode angka di whatsmeow, tapi tipe event terpisah:
//     events.StreamReplaced{} — inilah sinyal kasus "device_removed" yang
//     jadi root cause insiden v0.9.6 (race condition dua socket
//     mengautentikasi device yang sama). Fix connectLocks/singleflight di
//     internal/session menutup PENYEBABnya secara struktural, tapi event
//     ini tetap perlu ditangani (lihat BehaviorReplacedByAnotherSession)
//     untuk kasus yang belum tentu ditemukan.
package disconnectmap

// Behavior adalah klasifikasi tindakan, bukan kode mentah — session
// manager bertindak berdasarkan Behavior ini, tidak pernah bercabang
// langsung di angka kode di luar package ini.
type Behavior string

const (
	// BehaviorTransientReconnect: putus sementara (jaringan, keepalive
	// timeout, kode statusCode generik/tidak dikenal) — lanjutkan backoff
	// eksponensial 5s->60s (identik sessionManager.js), JANGAN wipe auth
	// state. Dipilih juga sebagai default untuk kode YANG TIDAK DIKENAL —
	// lebih aman salah retry (buang waktu) daripada salah wipe sesi yang
	// masih genuinely valid.
	BehaviorTransientReconnect Behavior = "transient_reconnect"

	// BehaviorRequireRepair: sesi tidak sah lagi — WAJIB wipe device dari
	// sqlstore.Container (dan baris pemetaan session_key->JID milik kita
	// sendiri) lalu tunggu QR/Kode Pairing baru. Setara
	// DisconnectReason.loggedOut (401) DAN DisconnectReason.badSession
	// (500) Baileys — keduanya di-treat sama di sessionManager.js lama.
	BehaviorRequireRepair Behavior = "require_repair"

	// BehaviorReplacedByAnotherSession: events.StreamReplaced — setara
	// DisconnectReason.connectionReplaced (440) Baileys. JANGAN
	// auto-reconnect langsung tanpa jeda (berisiko loop saling menendang
	// kalau memang ada dua proses mengautentikasi device yang sama) — log
	// sebagai WARNING keras, tetap jadwalkan reconnect tapi dengan delay
	// penuh (60s, bukan mulai dari 5s) sebagai jeda ekstra kehati-hatian.
	BehaviorReplacedByAnotherSession Behavior = "replaced_by_another_session"

	// BehaviorBanned: events.TemporaryBan (Code + Expire) — setara kasar
	// DisconnectReason.forbidden (403) Baileys. JANGAN retry cepat —
	// retry sebelum Expire lewat berisiko memperpanjang/memperberat ban.
	BehaviorBanned Behavior = "banned"
)

// ClassifyConnectFailure memetakan events.ConnectFailureReason (kode
// numerik statusCode dari server WhatsApp, dibaca dari events.LoggedOut
// atau events.ConnectFailure) ke Behavior.
func ClassifyConnectFailure(reasonCode int) Behavior {
	switch reasonCode {
	case 401: // events.ConnectFailureLoggedOut
		return BehaviorRequireRepair
	case 406: // events.ConnectFailureUnknownLogout
		return BehaviorRequireRepair
	case 403: // events.ConnectFailureMainDeviceGone
		return BehaviorRequireRepair
	case 402: // events.ConnectFailureTempBanned
		return BehaviorBanned
	case 405: // events.ConnectFailureClientOutdated — butuh intervensi
		// (update client), bukan retry buta, tapi juga bukan "logged out"
		// sesungguhnya. Diperlakukan require-repair supaya operator sadar
		// (tampil di UI sebagai perlu scan/pairing ulang) daripada diam-diam
		// retry selamanya terhadap versi client yang ditolak server.
		return BehaviorRequireRepair
	case 400: // events.ConnectFailureGeneric
		return BehaviorTransientReconnect
	default:
		return BehaviorTransientReconnect
	}
}
