// Package session adalah jantung gateway Go — padanan sessionManager.js
// lama, dibangun di atas whatsmeow alih-alih Baileys. Setiap keputusan
// perilaku di sini SENGAJA mengikuti sessionManager.js persis kecuali
// dicatat berbeda (lihat komentar tiap fungsi) — acuan lengkap ada di
// docs/whatsapp-gateway-api-surface.md.
//
// CATATAN ARSITEKTUR PENTING: whatsmeow (lewat sqlstore.Container) TIDAK
// menyimpan konsep "sessionKey" kita sendiri (reseller_id atau literal
// "direct") sama sekali — dia hanya tahu JID WhatsApp. Karena itu package
// ini menambah SATU tabel kecil milik sendiri, `boss_session_keys`, di
// DATABASE YANG SAMA (whatsmeow_store) tapi TERPISAH dari tabel-tabel yang
// dikelola sqlstore.Container.Upgrade() sendiri — supaya tidak pernah
// tabrakan dengan migration internal whatsmeow di masa depan. Ini keputusan
// desain yang perlu dilaporkan balik ke Agung, bukan sesuatu yang
// terdokumentasi di whatsmeow sendiri.
package session

import (
	"context"
	"database/sql"
	"encoding/base64"
	"errors"
	"fmt"
	"log/slog"
	"math"
	"sync"
	"time"

	"github.com/skip2/go-qrcode"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	waLog "go.mau.fi/whatsmeow/util/log"
	"golang.org/x/sync/singleflight"
	"google.golang.org/protobuf/proto"

	"whatsapp-gateway/internal/disconnectmap"
	"whatsapp-gateway/internal/jidnorm"
	"whatsapp-gateway/internal/webhook"
)

const (
	// reconnectBaseDelay/reconnectMaxDelay: backoff eksponensial 5s->60s,
	// IDENTIK dengan RECONNECT_BASE_MS/RECONNECT_MAX_MS di
	// sessionManager.js (fix v0.9.6/v0.9.9) — jangan diubah sepihak tanpa
	// alasan yang didokumentasikan di sini juga.
	reconnectBaseDelay = 5 * time.Second
	reconnectMaxDelay  = 60 * time.Second

	// sendTimeout: identik SEND_TIMEOUT_MS=20000 di sessionManager.js.
	sendTimeout = 20 * time.Second

	// qrFirstCodeTimeout: batas tunggu SINKRON untuk kode QR PERTAMA
	// sebelum endpoint HTTP membalas — tidak ada padanan langsung di Node
	// (Baileys mengemit qr lewat event connection.update yang juga
	// ditunggu sinkron oleh getOrRefreshQr() di sana), angka ini dipilih
	// dengan margin di atas observasi umum whatsmeow menerbitkan QR
	// pertama dalam beberapa detik setelah Connect() — BELUM diverifikasi
	// terhadap server WhatsApp asli (lihat catatan Fase 3 di laporan).
	qrFirstCodeTimeout = 15 * time.Second
)

type Status string

const (
	StatusQRPending    Status = "qr_pending"
	StatusConnected    Status = "connected"
	StatusDisconnected Status = "disconnected"
	StatusLoggedOut    Status = "logged_out"
)

// State adalah snapshot state satu sesi — dikembalikan lewat
// GET /sessions dan GET /sessions/:key/health.
type State struct {
	SessionKey  string `json:"sessionKey"`
	Status      string `json:"status"`
	PhoneNumber string `json:"phoneNumber,omitempty"`
}

type entry struct {
	mu               sync.Mutex
	key              string
	client           *whatsmeow.Client
	status           Status
	qrCodeDataURI    string
	phoneNumber      string
	reconnectAttempt int
	reconnectTimer   *time.Timer
}

func (e *entry) snapshot() State {
	e.mu.Lock()
	defer e.mu.Unlock()

	return State{SessionKey: e.key, Status: string(e.status), PhoneNumber: e.phoneNumber}
}

// Manager adalah padanan langsung class SessionManager di sessionManager.js
// — satu instance untuk seluruh proses, satu peta in-memory per sesi.
type Manager struct {
	ctx      context.Context
	store    *sqlstore.Container
	db       *sql.DB
	log      waLog.Logger
	notifier *webhook.Notifier
	sessions sync.Map // string(sessionKey) -> *entry

	// connectGroup adalah padanan LANGSUNG connectLocks (Map<sessionKey,
	// Promise>) di sessionManager.js — fix race condition NYATA v0.9.9
	// (dua request Connect() bersamaan untuk sessionKey yang sama
	// menghasilkan device_removed conflict karena WhatsApp mendeteksi dua
	// socket mengautentikasi sebagai device yang sama). singleflight.Group
	// adalah IDIOM GO NATIF untuk persis masalah ini — dedup panggilan
	// konkuren dengan key yang sama jadi SATU eksekusi, yang lain
	// menunggu hasil yang sama. Ini LEBIH RAPI daripada port manual
	// Map<Promise> Node ke Go (yang butuh mutex+map+channel manual) — poin
	// yang wajib dilaporkan balik sesuai instruksi checkpoint.
	connectGroup singleflight.Group
}

func NewManager(ctx context.Context, store *sqlstore.Container, db *sql.DB, log waLog.Logger, notifier *webhook.Notifier) (*Manager, error) {
	m := &Manager{ctx: ctx, store: store, db: db, log: log, notifier: notifier}

	if _, err := db.ExecContext(ctx, `
		CREATE TABLE IF NOT EXISTS boss_session_keys (
			session_key TEXT PRIMARY KEY,
			jid TEXT NOT NULL,
			created_at TIMESTAMPTZ NOT NULL DEFAULT now()
		)
	`); err != nil {
		return nil, fmt.Errorf("failed to ensure boss_session_keys table: %w", err)
	}

	return m, nil
}

// RestoreAll adalah padanan restoreAll() Node — dipanggil sekali saat
// proses Go boot, menyambungkan ulang setiap sesi yang sudah pernah
// ter-pairing (device tersimpan di sqlstore + baris di boss_session_keys)
// TANPA perlu scan/pairing ulang, persis seperti Baileys.
func (m *Manager) RestoreAll() {
	rows, err := m.db.QueryContext(m.ctx, `SELECT session_key, jid FROM boss_session_keys`)
	if err != nil {
		slog.Error("restoreAll: failed to query session keys", "err", err)

		return
	}
	defer rows.Close()

	type pair struct{ key, jid string }

	var pairs []pair

	for rows.Next() {
		var p pair
		if err := rows.Scan(&p.key, &p.jid); err != nil {
			slog.Error("restoreAll: scan failed", "err", err)

			continue
		}

		pairs = append(pairs, p)
	}

	for _, p := range pairs {
		jid, err := types.ParseJID(p.jid)
		if err != nil {
			slog.Error("restoreAll: invalid stored JID, skipping", "sessionKey", p.key, "jid", p.jid, "err", err)

			continue
		}

		device, err := m.store.GetDevice(m.ctx, jid)
		if err != nil || device == nil {
			slog.Warn("restoreAll: device not found in store, skipping", "sessionKey", p.key, "jid", p.jid, "err", err)

			continue
		}

		e := &entry{key: p.key, status: StatusDisconnected, client: whatsmeow.NewClient(device, m.log)}
		m.registerHandlers(e)
		m.sessions.Store(p.key, e)

		go func(key string) {
			if _, err := m.Connect(key); err != nil {
				slog.Error("restoreAll: initial connect failed", "sessionKey", key, "err", err)
			}
		}(p.key)
	}

	slog.Info("restoreAll finished", "sessionsRestored", len(pairs))
}

// ListStates — padanan listStates() Node, dipakai GET /sessions.
func (m *Manager) ListStates() []State {
	var out []State

	m.sessions.Range(func(_, v interface{}) bool {
		out = append(out, v.(*entry).snapshot())

		return true
	})

	if out == nil {
		out = []State{}
	}

	return out
}

// GetState — padanan getState() Node, dipakai GET /sessions/:key/health
// (endpoint ini TIDAK punya caller Laravel aktif per investigasi Langkah 0,
// tetap disediakan untuk debugging manual).
func (m *Manager) GetState(key string) (State, bool) {
	v, ok := m.sessions.Load(key)
	if !ok {
		return State{}, false
	}

	return v.(*entry).snapshot(), true
}

// Connect adalah wrapper tipis ber-singleflight — padanan connect() Node
// yang sudah di-fix v0.9.9. JANGAN panggil doConnect langsung dari luar.
func (m *Manager) Connect(key string) (State, error) {
	v, err, _ := m.connectGroup.Do(key, func() (interface{}, error) {
		return m.doConnect(key)
	})
	if err != nil {
		return State{}, err
	}

	return v.(*entry).snapshot(), nil
}

func (m *Manager) doConnect(key string) (*entry, error) {
	v, ok := m.sessions.Load(key)
	if !ok {
		return nil, fmt.Errorf("no session entry for key %q — must be created via QR/pairing first", key)
	}

	e := v.(*entry)

	if e.client.IsConnected() {
		return e, nil
	}

	if err := e.client.Connect(); err != nil {
		return e, fmt.Errorf("connect failed: %w", err)
	}

	return e, nil
}

// GetOrRefreshQR — padanan getOrRefreshQr() Node. Mengembalikan data URI
// PNG base64 (identik bentuknya dengan yang dihasilkan lib `qrcode` Node)
// supaya UI Livewire (<img src="...">) tidak perlu berubah sama sekali.
func (m *Manager) GetOrRefreshQR(key string) (string, error) {
	if v, exists := m.sessions.Load(key); exists {
		e := v.(*entry)

		e.mu.Lock()
		status := e.status
		hasIdentity := e.client.Store.ID != nil
		cachedQR := e.qrCodeDataURI
		reconnectTimer := e.reconnectTimer
		e.mu.Unlock()

		switch {
		case status == StatusConnected:
			return "", errors.New("session already connected")
		case status == StatusLoggedOut:
			m.wipeSession(key)
		case hasIdentity:
			// Creds masih valid (disconnected/qr_pending transien) — paksa
			// reconnect, JANGAN minta QR baru (persis perilaku Node:
			// getOrRefreshQr() pada status 'disconnected' membatalkan
			// timer backoff yang masih menunggu lalu ensureConnected
			// force=true, bukan menghasilkan QR palsu untuk sesi yang
			// creds-nya masih sah).
			if reconnectTimer != nil {
				reconnectTimer.Stop()
			}

			if _, err := m.Connect(key); err != nil {
				return cachedQR, err
			}

			return cachedQR, nil
		}
	}

	return m.startFreshQR(key)
}

func (m *Manager) startFreshQR(key string) (string, error) {
	v, exists := m.sessions.Load(key)

	var e *entry

	if exists {
		e = v.(*entry)
	} else {
		device := m.store.NewDevice()
		e = &entry{key: key, status: StatusQRPending, client: whatsmeow.NewClient(device, m.log)}
		m.registerHandlers(e)
		m.sessions.Store(key, e)
	}

	if e.client.Store.ID != nil {
		return "", errors.New("session already has a paired identity — wipe first")
	}

	qrCtx, cancel := context.WithCancel(m.ctx)

	qrChan, err := e.client.GetQRChannel(qrCtx)
	if err != nil {
		cancel()

		return "", fmt.Errorf("failed to open QR channel: %w", err)
	}

	if err := e.client.Connect(); err != nil {
		cancel()

		return "", fmt.Errorf("connect failed: %w", err)
	}

	firstCodeCh := make(chan string, 1)
	firstErrCh := make(chan error, 1)

	go m.drainQRChannel(cancel, e, qrChan, firstCodeCh, firstErrCh)

	select {
	case code := <-firstCodeCh:
		return code, nil
	case err := <-firstErrCh:
		return "", err
	case <-time.After(qrFirstCodeTimeout):
		return "", errors.New("timed out waiting for first QR code")
	}
}

func (m *Manager) drainQRChannel(cancel context.CancelFunc, e *entry, qrChan <-chan whatsmeow.QRChannelItem, firstCodeCh chan<- string, firstErrCh chan<- error) {
	defer cancel()

	first := true

	for item := range qrChan {
		switch item.Event {
		case "code":
			dataURI, encErr := encodeQRDataURI(item.Code)
			if encErr != nil {
				slog.Error("qr: failed to encode PNG", "sessionKey", e.key, "err", encErr)

				if first {
					firstErrCh <- encErr
					first = false
				}

				continue
			}

			e.mu.Lock()
			e.qrCodeDataURI = dataURI
			e.status = StatusQRPending
			e.mu.Unlock()

			slog.Info("new QR code generated", "sessionKey", e.key)
			m.notifier.NotifySessionStatus(webhook.StatusPayload{
				SessionKey: e.key,
				Status:     string(StatusQRPending),
				QRCodeData: webhook.StrPtr(dataURI),
			})

			if first {
				firstCodeCh <- dataURI
				first = false
			}
		case "success":
			slog.Info("qr scanned, pairing in progress", "sessionKey", e.key)
		case "timeout":
			slog.Warn("qr channel timed out waiting for scan", "sessionKey", e.key)

			if first {
				firstErrCh <- errors.New("QR channel timed out waiting for scan")
				first = false
			} else {
				// Kode QR SUDAH pernah tampil (bukan kegagalan di percobaan
				// pertama) — channel whatsmeow GENUINELY habis (array kode
				// dari server sudah dikeluarkan semua, whatsmeow SENDIRI
				// sudah memanggil cli.Disconnect() di titik ini, lihat
				// go.mau.fi/whatsmeow qrchan.go's emitQRs()). SEBELUM fix
				// ini, cabang ini nol-efek — Laravel tidak pernah tahu QR
				// yang masih ditampilkan sudah basi. Status yang dikirim
				// TETAP "qr_pending" (TIDAK "disconnected" — string itu
				// sudah dipakai skenario transient-reconnect yang beda
				// semantik total, lihat disconnectAndScheduleReconnect())
				// — cuma QrExpired=true sebagai sinyal TERPISAH.
				e.mu.Lock()
				e.status = StatusQRPending
				e.mu.Unlock()

				m.notifier.NotifySessionStatus(webhook.StatusPayload{
					SessionKey: e.key,
					Status:     string(StatusQRPending),
					QrExpired:  webhook.BoolPtr(true),
				})
			}
		default:
			slog.Info("qr channel event", "sessionKey", e.key, "event", item.Event)
		}
	}
}

func encodeQRDataURI(code string) (string, error) {
	png, err := qrcode.Encode(code, qrcode.Medium, 256)
	if err != nil {
		return "", err
	}

	return "data:image/png;base64," + base64.StdEncoding.EncodeToString(png), nil
}

// RequestPairingCode — padanan requestPairingCode() Node (fitur "Kode
// Pairing", v0.9.9). Selalu wipe state lama dulu (fresh state) sebelum
// meminta kode baru, sama seperti Node — supaya
// client.Store.ID/creds genuinely nil saat PairPhone() dipanggil.
func (m *Manager) RequestPairingCode(key, phoneNumber string) (string, error) {
	if v, exists := m.sessions.Load(key); exists {
		e := v.(*entry)

		e.mu.Lock()
		status := e.status
		hasIdentity := e.client.Store.ID != nil
		e.mu.Unlock()

		if status == StatusConnected {
			return "", errors.New("session already connected")
		}

		if hasIdentity {
			return "", errors.New("session already has a paired identity — wipe first")
		}
	}

	m.wipeSession(key)

	device := m.store.NewDevice()
	e := &entry{key: key, status: StatusQRPending, client: whatsmeow.NewClient(device, m.log)}
	m.registerHandlers(e)
	m.sessions.Store(key, e)

	qrCtx, cancel := context.WithCancel(m.ctx)
	defer cancel()

	// GetQRChannel HARUS dibuka sebelum Connect() (persis pola
	// startFreshQR) walau QR-nya sendiri TIDAK dipakai di alur Kode
	// Pairing — whatsmeow's PairPhone() docblock (pair-code.go) mewajibkan
	// menunggu event PERTAMA dari kanal ini dulu supaya handshake koneksi
	// genuinely selesai. BUG NYATA ditemukan sebelum fix ini: tanpa
	// menunggu, PairPhone() 100% gagal "info query returned status 400:
	// bad-request" — dikonfirmasi lewat pengujian langsung terhadap
	// server WhatsApp asli, bukan cuma dugaan dari membaca dokumentasi.
	qrChan, err := e.client.GetQRChannel(qrCtx)
	if err != nil {
		return "", fmt.Errorf("failed to open QR channel: %w", err)
	}

	if err := e.client.Connect(); err != nil {
		return "", fmt.Errorf("connect failed: %w", err)
	}

	select {
	case <-qrChan:
	case <-time.After(10 * time.Second):
		return "", errors.New("timed out waiting for connection to establish before requesting pairing code")
	}

	// Sisa event di kanal (QR berikutnya, kalau ada) tidak relevan untuk
	// alur Kode Pairing — dibuang di goroutine terpisah supaya tidak bocor
	// dan tidak menghambat panggilan PairPhone di bawah.
	go func() {
		for range qrChan { //nolint:revive // sengaja membuang semua item
		}
	}()

	normalized := jidnorm.NormalizeIndonesian(phoneNumber)

	// clientDisplayName WAJIB format "Browser (OS)" dan divalidasi SERVER
	// WhatsApp sendiri (menolak 400 kalau tidak cocok pola browser/OS yang
	// dikenal) — dikonfirmasi lewat bug NYATA: "BOSS App" (nama bebas)
	// ditolak 400 "info query returned status 400: bad-request" walau
	// timing/handshake sudah benar. "Chrome (Linux)" dipilih karena cocok
	// dengan whatsmeow.PairClientChrome di parameter sebelumnya.
	code, err := e.client.PairPhone(m.ctx, normalized, true, whatsmeow.PairClientChrome, "Chrome (Linux)")
	if err != nil {
		return "", fmt.Errorf("pairing code request failed: %w", err)
	}

	return code, nil
}

// resolveSendableEntry — v0.26.4, diekstrak dari SendMessage() lama supaya
// SendMessage() (individu) dan SendGroupMessage() (grup) berbagi PERSIS
// validasi yang sama (sesi ada, status Connected, identitas sudah
// ter-pair) — tidak ada alasan bisnis bagi keduanya berbeda perlakuan di
// titik ini, dan drift antara dua jalur adalah kelas bug yang mudah
// terjadi kalau logic ini diduplikasi mentah.
func (m *Manager) resolveSendableEntry(key string) (*entry, error) {
	v, exists := m.sessions.Load(key)
	if !exists {
		return nil, errors.New("session not found")
	}

	e := v.(*entry)

	e.mu.Lock()
	status := e.status
	hasIdentity := e.client.Store.ID != nil
	e.mu.Unlock()

	if status != StatusConnected || !hasIdentity {
		return nil, errors.New("session not connected")
	}

	return e, nil
}

// sendToJID — v0.26.4, diekstrak dari SendMessage() lama. Inti kirim yang
// GENUINELY generic (client.SendMessage() whatsmeow menerima types.JID
// apa pun, individu maupun grup) — satu-satunya titik yang benar-benar
// memanggil whatsmeow untuk mengirim pesan teks, dipakai SendMessage() DAN
// SendGroupMessage() supaya timeout/error-handling tidak pernah drift
// antara dua jalur.
func (m *Manager) sendToJID(e *entry, jid types.JID, message string) error {
	ctx, cancel := context.WithTimeout(m.ctx, sendTimeout)
	defer cancel()

	msg := &waE2E.Message{Conversation: proto.String(message)}

	_, err := e.client.SendMessage(ctx, jid, msg)
	if err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return fmt.Errorf("send timeout after %s", sendTimeout)
		}

		return err
	}

	return nil
}

// SendMessage — padanan sendMessage() Node. Normalisasi nomor (fix v0.9.6)
// dan timeout keras (fix robustness v0.9.6/v0.9.9) DIPERTAHANKAN PERSIS.
func (m *Manager) SendMessage(key, phoneNumber, message string) error {
	e, err := m.resolveSendableEntry(key)
	if err != nil {
		return err
	}

	normalized := jidnorm.NormalizeIndonesian(phoneNumber)
	jid := jidnorm.BuildJID(normalized)

	return m.sendToJID(e, jid, message)
}

// SendGroupMessage — v0.26.4. BEDA TOTAL dari SendMessage(): groupJIDString
// di sini SUDAH berupa JID grup LENGKAP (hasil ListGroups()/GetJoinedGroups(),
// bentuk "xxxxxxxxxx-xxxxxxxxxx@g.us") — TIDAK BOLEH lewat
// jidnorm.NormalizeIndonesian()/BuildJID() sama sekali. Kedua fungsi itu
// khusus nomor telepon individu (strip semua karakter non-digit lalu bangun
// JID dengan Server=DefaultUserServer) — memaksakannya ke string JID grup
// akan MERUSAK totalnya (karakter "-"/"@"/"g"/"."/"u"/"s" ikut ter-strip).
// Parse lewat types.ParseJID() resmi whatsmeow, lalu guard EKSPLISIT
// Server harus "g.us" — defense-in-depth di sisi Go, jangan cuma percaya
// request dari Laravel sudah genuinely JID grup.
func (m *Manager) SendGroupMessage(key, groupJIDString, message string) error {
	e, err := m.resolveSendableEntry(key)
	if err != nil {
		return err
	}

	jid, err := parseGroupJID(groupJIDString)
	if err != nil {
		return err
	}

	return m.sendToJID(e, jid, message)
}

// parseGroupJID — diekstrak dari SendGroupMessage() SENGAJA sebagai fungsi
// murni (tidak butuh *Manager/*entry/koneksi whatsmeow sungguhan apa pun)
// supaya guard "ini genuinely JID grup, bukan individu" bisa dites
// independen dari state koneksi sesi — lihat manager_group_test.go.
func parseGroupJID(groupJIDString string) (types.JID, error) {
	jid, err := types.ParseJID(groupJIDString)
	if err != nil {
		return types.JID{}, fmt.Errorf("invalid group JID: %w", err)
	}

	if jid.Server != types.GroupServer {
		return types.JID{}, fmt.Errorf("JID %q is not a group JID (server=%q, expected %q)", groupJIDString, jid.Server, types.GroupServer)
	}

	return jid, nil
}

// IsPermanentGroupSendError — v0.26.4. True kalau err menandakan kegagalan
// PERMANEN saat kirim ke grup (bot sudah bukan/tidak-lagi anggota grup itu)
// — dikonfirmasi lewat pembacaan LANGSUNG source whatsmeow (send.go): saat
// tujuan kirim adalah JID grup, client.SendMessage() SELALU memanggil
// getCachedGroupData() DULU untuk resolve daftar anggota — kalau device kita
// bukan anggota, itu gagal dengan whatsmeow.ErrNotInGroup, dibungkus lewat
// `fmt.Errorf("failed to get group members: %w", err)` (wrapping "%w"
// mempertahankan rantai errors.Is). Dipakai handleSendGroup() supaya
// Laravel bisa membedakan kegagalan PERMANEN ini (retry berulang sia-sia,
// grup itu tidak akan "kembali" sendiri) dari kegagalan TRANSIEN (timeout/
// koneksi, layak dicoba ulang) — TANPA perlu string-matching rapuh atas
// pesan error yang sudah di-render jadi teks di sisi Laravel.
func IsPermanentGroupSendError(err error) bool {
	return errors.Is(err, whatsmeow.ErrNotInGroup)
}

// GroupSummary — bentuk ringkas grup yang diekspos ke Laravel lewat
// endpoint HTTP `GET /sessions/{key}/groups`. SENGAJA bukan types.GroupInfo
// mentah — struct itu punya banyak field internal (Participants/OwnerJID/
// GroupCreated/dll) yang tidak relevan buat dropdown pemilihan grup dan
// berpotensi membocorkan metadata yang tidak perlu (siapa saja anggotanya,
// dst) lewat respons HTTP yang toh cuma butuh "JID mana + nama apa".
type GroupSummary struct {
	JID  string `json:"jid"`
	Name string `json:"name"`
}

// ListGroups — v0.26.4. Daftar SEMUA grup yang nomor bot session ini sudah
// jadi anggota, APA ADANYA — keputusan eksplisit Agung (bukan diputuskan
// sepihak di sini): kalau bot anggota 10 grup, kembalikan semua 10 tanpa
// filter nama/kata kunci apa pun. Admin yang memilih grup mana yang
// relevan lewat dropdown di sisi Laravel, gateway ini tidak menebak.
func (m *Manager) ListGroups(key string) ([]GroupSummary, error) {
	e, err := m.resolveSendableEntry(key)
	if err != nil {
		return nil, err
	}

	ctx, cancel := context.WithTimeout(m.ctx, sendTimeout)
	defer cancel()

	groups, err := e.client.GetJoinedGroups(ctx)
	if err != nil {
		return nil, err
	}

	summaries := make([]GroupSummary, 0, len(groups))
	for _, g := range groups {
		summaries = append(summaries, GroupSummary{JID: g.JID.String(), Name: g.Name})
	}

	return summaries, nil
}

func (m *Manager) persistMapping(key string, jid types.JID) {
	_, err := m.db.ExecContext(m.ctx, `
		INSERT INTO boss_session_keys (session_key, jid) VALUES ($1, $2)
		ON CONFLICT (session_key) DO UPDATE SET jid = EXCLUDED.jid
	`, key, jid.String())
	if err != nil {
		slog.Error("persistMapping: failed to store session_key->jid mapping", "sessionKey", key, "err", err)
	}
}

// Logout — padanan langsung logout() Node (sessionManager.js), tombol
// "Logout" UI (branch migrasi-whatsmeow). BEDA dari wipeSession() polos
// (dipakai internal saat server WhatsApp SENDIRI sudah menganggap sesi
// tidak sah, mis. events.LoggedOut) — ini memanggil client.Logout(ctx)
// whatsmeow DULU (best-effort) supaya server WhatsApp diberi tahu
// perangkat ini SENGAJA diputus, sebelum device dihapus dari store. Tanpa
// ini, entri "Perangkat Tertaut" di HP pengguna tertinggal sebagai entri
// hantu di sisi WhatsApp walau state lokal kita sudah bersih.
func (m *Manager) Logout(key string) error {
	v, exists := m.sessions.Load(key)
	if !exists {
		// Tidak ada entry in-memory — tetap coba bersihkan baris pemetaan
		// (kalau ada, dari sesi yang sempat ada sebelum proses restart)
		// supaya idempotent, bukan error keras.
		m.wipeSession(key)

		return nil
	}

	e := v.(*entry)

	if e.client != nil && e.client.Store.ID != nil {
		if err := e.client.Logout(m.ctx); err != nil {
			slog.Warn("Logout: client.Logout() failed, proceeding with local wipe anyway", "sessionKey", key, "err", err)
		}
	}

	m.wipeSession(key)

	m.notifier.NotifySessionStatus(webhook.StatusPayload{SessionKey: key, Status: string(StatusLoggedOut)})

	slog.Info("session logged out (manual)", "sessionKey", key)

	return nil
}

func (m *Manager) wipeSession(key string) {
	if v, exists := m.sessions.LoadAndDelete(key); exists {
		e := v.(*entry)

		e.mu.Lock()
		if e.reconnectTimer != nil {
			e.reconnectTimer.Stop()
		}
		e.mu.Unlock()

		if e.client != nil {
			e.client.Disconnect()

			if e.client.Store.ID != nil {
				if err := e.client.Store.Delete(m.ctx); err != nil {
					slog.Error("wipeSession: failed to delete device from store", "sessionKey", key, "err", err)
				}
			}
		}
	}

	if _, err := m.db.ExecContext(m.ctx, `DELETE FROM boss_session_keys WHERE session_key = $1`, key); err != nil {
		slog.Error("wipeSession: failed to delete session key mapping", "sessionKey", key, "err", err)
	}
}

func (m *Manager) registerHandlers(e *entry) {
	e.client.AddEventHandler(func(evt interface{}) {
		switch v := evt.(type) {
		case *events.Connected:
			m.onConnected(e)

		case *events.PairSuccess:
			m.persistMapping(e.key, v.ID)
			slog.Info("pair success", "sessionKey", e.key, "jid", v.ID.String())

		case *events.LoggedOut:
			slog.Warn("session logged out", "sessionKey", e.key, "reason", v.Reason)
			m.handleRequireRepair(e)

		case *events.StreamReplaced:
			// Setara DisconnectReason.connectionReplaced (440) Baileys —
			// sinyal kelas "device_removed" root cause insiden v0.9.9. Fix
			// connectLocks/singleflight menutup PENYEBAB strukturalnya,
			// tapi tetap ditangani di sini untuk kasus yang belum
			// ditemukan — delay penuh (bukan backoff dari awal) sebagai
			// jeda ekstra kehati-hatian, lihat internal/disconnectmap.
			slog.Warn("session replaced by another connection (device_removed class)", "sessionKey", e.key)
			m.disconnectAndScheduleReconnect(e, reconnectMaxDelay)

		case *events.ConnectFailure:
			m.handleConnectFailureBehavior(e, disconnectmap.ClassifyConnectFailure(int(v.Reason)))

		case *events.TemporaryBan:
			slog.Error("session temporarily banned by WhatsApp", "sessionKey", e.key, "expire", v.Expire)
			m.disconnectAndScheduleReconnect(e, v.Expire)

		case *events.Disconnected:
			slog.Warn("session disconnected", "sessionKey", e.key)
			m.disconnectAndScheduleReconnect(e, 0)

		case *events.KeepAliveTimeout:
			slog.Warn("keepalive timeout", "sessionKey", e.key, "errorCount", v.ErrorCount)

		case *events.Message:
			m.onIncomingMessage(e, v)
		}
	})
}

// resolveSenderPhone — investigasi + keputusan Agung 2026-09-15 (bug LID:
// sender_phone kadang tersimpan sebagai raw WhatsApp LID — mis.
// 44435932971043 — bukan nomor HP asli). Referensi: GitHub discussion
// whatsmeow #905 (komunitas, terverifikasi production independen).
//
// PENTING: deteksi LID TIDAK PERNAH pakai evt.Info.AddressingMode —
// dikonfirmasi via referensi komunitas field itu KADANG KOSONG walau Sender
// genuinely LID (tergantung tipe event/device pengirim), jadi tidak cukup
// robust dipakai sebagai kondisi utama. Kondisi utama yang dipakai di sini
// adalah sender.Server == types.HiddenUserServer — dikonfirmasi langsung
// dari source whatsmeow yang di-pin (types/jid.go: HiddenUserServer = "lid"
// adalah nilai JID.Server untuk LID, konstan yang sama dipakai
// message.go::parseMessageSource() sendiri untuk membedakan cabang LID/PN).
//
// Urutan fallback:
//
//	a. sender.Server != HiddenUserServer -> bukan LID sama sekali, pakai
//	   sender.User apa adanya. is_lid=false.
//	b. LID DAN senderAlt terisi (types.MessageInfo.SenderAlt, diisi
//	   whatsmeow LANGSUNG dari attribute node XML pesan yang sama —
//	   message.go::parseMessageSource(), BUKAN hasil lookup async
//	   terpisah) -> pakai senderAlt.User (PN asli). is_lid=false — sender
//	   aslinya LID, tapi kita BERHASIL dapat PN, bukan kegagalan.
//	c. LID DAN senderAlt kosong -> fallback query LOKAL (bukan network
//	   call ke server WhatsApp — whatsmeow tidak expose method publik
//	   untuk itu) ke LIDStore.GetPNForLID(ctx, sender) — mapping yang
//	   sudah pernah tersimpan dari histori (whatsmeow otomatis
//	   menyimpannya tiap kali SATU pesan dari kontak itu PERNAH membawa
//	   senderAlt terisi, lihat message.go::handleEncryptedMessage() ->
//	   StoreLIDPNMapping() — jadi "belum pernah kontak" adalah skenario
//	   realistis satu-satunya mapping ini kosong). is_lid=false kalau
//	   ketemu.
//	d. Masih tidak ketemu -> PN genuinely tidak tersedia dari sisi kita di
//	   titik ini, bukan bug yang bisa diperbaiki lebih lanjut (lihat
//	   laporan investigasi 2026-09-15) -> simpan sender.User APA ADANYA
//	   (raw LID, TIDAK dikonversi ToLocalIndonesian — itu bukan nomor).
//	   is_lid=true.
//
// lidStore diterima sebagai parameter (bukan diambil langsung dari
// e.client.Store.LIDs di dalam fungsi) supaya fungsi ini testable tanpa
// membangun *whatsmeow.Client sungguhan — lihat manager_lid_test.go.
func resolveSenderPhone(ctx context.Context, sender, senderAlt types.JID, lidStore store.LIDStore) (phone string, isLid bool) {
	if sender.Server != types.HiddenUserServer {
		return jidnorm.ToLocalIndonesian(sender.User), false
	}

	if !senderAlt.IsEmpty() {
		return jidnorm.ToLocalIndonesian(senderAlt.User), false
	}

	if lidStore != nil {
		if pn, err := lidStore.GetPNForLID(ctx, sender); err == nil && !pn.IsEmpty() {
			return jidnorm.ToLocalIndonesian(pn.User), false
		}
	}

	return sender.User, true
}

// onIncomingMessage — v0.13.1, listener pesan masuk (padanan case
// events.Message di eventHandler() contoh resmi whatsmeow, client_test.go —
// lihat komentar di atas package ini). Filter IsFromMe/IsGroup diterapkan DI
// SINI, sebelum apa pun diteruskan ke Laravel — gateway ini konteksnya
// percakapan 1-on-1 customer/teknisi ke nomor bisnis, bukan grup, dan pesan
// dari device kita sendiri (mis. pesan yang kita kirim lewat WhatsApp app
// asli, bukan lewat SendMessage()) bukan "pesan masuk" yang relevan.
//
// TIDAK ADA state machine/routing/business logic di sini — v0.13.1 murni
// menangkap+meneruskan payload mentah ke Laravel lewat webhook. Keputusan
// apa yang dilakukan dengan isi pesan ini (v0.13.2+) sama sekali bukan
// urusan package ini.
func (m *Manager) onIncomingMessage(e *entry, evt *events.Message) {
	if evt.Info.IsFromMe || evt.Info.IsGroup {
		return
	}

	text := evt.Message.GetConversation()
	if text == "" {
		text = evt.Message.GetExtendedTextMessage().GetText()
	}

	if text == "" {
		// Bukan pesan teks (media/lokasi/stiker/dll) — v0.13.1 cuma
		// menangani teks, sesuai instruksi. Bukan error, tidak perlu log.
		return
	}

	var lidStore store.LIDStore
	if e.client != nil && e.client.Store != nil {
		lidStore = e.client.Store.LIDs
	}
	senderPhone, isLid := resolveSenderPhone(context.Background(), evt.Info.Sender, evt.Info.SenderAlt, lidStore)

	slog.Info("incoming text message", "sessionKey", e.key, "sender", evt.Info.Sender.User, "messageId", evt.Info.ID, "isLid", isLid)

	m.notifier.NotifyIncomingMessage(webhook.IncomingMessagePayload{
		SessionKey:  e.key,
		SenderPhone: senderPhone,
		IsLid:       isLid,
		ChatJID:     evt.Info.Chat.String(),
		Text:        text,
		MessageID:   evt.Info.ID,
		Timestamp:   evt.Info.Timestamp.Unix(),
		PushName:    evt.Info.PushName,
	})
}

func (m *Manager) onConnected(e *entry) {
	e.mu.Lock()
	e.status = StatusConnected
	e.qrCodeDataURI = ""
	e.reconnectAttempt = 0

	if e.client.Store.ID != nil {
		e.phoneNumber = e.client.Store.ID.User
	}

	phone := e.phoneNumber
	e.mu.Unlock()

	if e.client.Store.ID != nil {
		m.persistMapping(e.key, *e.client.Store.ID)
	}

	slog.Info("session connected", "sessionKey", e.key, "phoneNumber", phone)
	m.notifier.NotifySessionStatus(webhook.StatusPayload{
		SessionKey:  e.key,
		Status:      string(StatusConnected),
		PhoneNumber: webhook.StrPtr(phone),
	})
}

func (m *Manager) handleRequireRepair(e *entry) {
	e.mu.Lock()
	e.status = StatusLoggedOut
	e.mu.Unlock()

	m.notifier.NotifySessionStatus(webhook.StatusPayload{SessionKey: e.key, Status: string(StatusLoggedOut)})
	m.wipeSession(e.key)
}

func (m *Manager) handleConnectFailureBehavior(e *entry, behavior disconnectmap.Behavior) {
	switch behavior {
	case disconnectmap.BehaviorRequireRepair:
		m.handleRequireRepair(e)
	case disconnectmap.BehaviorBanned, disconnectmap.BehaviorReplacedByAnotherSession:
		m.disconnectAndScheduleReconnect(e, reconnectMaxDelay)
	default: // BehaviorTransientReconnect
		m.disconnectAndScheduleReconnect(e, 0)
	}
}

func (m *Manager) disconnectAndScheduleReconnect(e *entry, forceDelay time.Duration) {
	e.mu.Lock()
	e.status = StatusDisconnected
	e.mu.Unlock()

	m.notifier.NotifySessionStatus(webhook.StatusPayload{SessionKey: e.key, Status: string(StatusDisconnected)})
	m.scheduleReconnect(e, forceDelay)
}

// scheduleReconnect: forceDelay<=0 pakai backoff eksponensial normal
// (5s->60s, identik sessionManager.js); forceDelay>0 memaksa jeda tertentu
// (StreamReplaced/Banned — lihat komentar masing-masing pemanggil).
func (m *Manager) scheduleReconnect(e *entry, forceDelay time.Duration) {
	e.mu.Lock()
	defer e.mu.Unlock()

	if e.reconnectTimer != nil {
		e.reconnectTimer.Stop()
	}

	delay := forceDelay
	if delay <= 0 {
		attempt := e.reconnectAttempt
		e.reconnectAttempt++
		delay = time.Duration(math.Min(
			float64(reconnectBaseDelay)*math.Pow(2, float64(attempt)),
			float64(reconnectMaxDelay),
		))
	}

	slog.Info("scheduling reconnect", "sessionKey", e.key, "delay", delay)

	e.reconnectTimer = time.AfterFunc(delay, func() {
		if _, err := m.Connect(e.key); err != nil {
			slog.Error("reconnect failed", "sessionKey", e.key, "err", err)
		}
	})
}
