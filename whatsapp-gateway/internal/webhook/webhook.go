// Package webhook mem-port PERSIS notifySessionStatus() dari
// whatsapp-gateway/src/webhook.js — payload dan endpoint tujuan WAJIB
// identik byte-untuk-byte dengan yang App\Http\Controllers\Api\V1\
// WhatsappWebhookController::sessionStatus() -> WhatsappSessionService::
// updateStatusFromWebhook() harapkan HARI INI (dikonfirmasi dari pembacaan
// langsung kedua file itu, bukan tebakan):
//
//	{ "session_key": "...", "status": "qr_pending"|"connected"|"disconnected"|"logged_out",
//	  "phone_number"?: "...", "qr_code_data"?: "..." }
//
// Fire-and-forget dengan log kegagalan — webhook yang gagal terkirim
// dipulihkan lewat WhatsappSessionService::reconcileFromGateway()
// (whatsapp:check-session-health, ->hourly()) yang membaca GET /sessions.
package webhook

import (
	"bytes"
	"encoding/json"
	"log/slog"
	"net/http"
	"strconv"
	"time"

	"whatsapp-gateway/internal/hmacsig"
)

type Notifier struct {
	laravelBaseURL string
	hmacSecret     string
	client         *http.Client
}

func NewNotifier(laravelBaseURL, hmacSecret string) *Notifier {
	return &Notifier{
		laravelBaseURL: laravelBaseURL,
		hmacSecret:     hmacSecret,
		client:         &http.Client{Timeout: 10 * time.Second},
	}
}

type StatusPayload struct {
	SessionKey  string  `json:"session_key"`
	Status      string  `json:"status"`
	PhoneNumber *string `json:"phone_number,omitempty"`
	QRCodeData  *string `json:"qr_code_data,omitempty"`
}

// NotifySessionStatus mengirim event status sesi ke Laravel. Dipanggil dari
// goroutine terpisah oleh internal/session — TIDAK PERNAH memblokir alur
// koneksi Baileys/whatsmeow menunggu respons Laravel.
func (n *Notifier) NotifySessionStatus(payload StatusPayload) {
	body, err := json.Marshal(payload)
	if err != nil {
		slog.Error("webhook: failed to marshal payload", "err", err, "payload", payload)

		return
	}

	timestamp := time.Now().Unix()
	signature := hmacsig.Sign(n.hmacSecret, string(body), timestamp)

	req, err := http.NewRequest(http.MethodPost, n.laravelBaseURL+"/api/v1/whatsapp/webhook/session-status", bytes.NewReader(body))
	if err != nil {
		slog.Error("webhook: failed to build request", "err", err)

		return
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Whatsapp-Timestamp", strconv.FormatInt(timestamp, 10))
	req.Header.Set("X-Whatsapp-Signature", signature)

	resp, err := n.client.Do(req)
	if err != nil {
		slog.Error("webhook: failed to POST session-status to Laravel", "err", err, "payload", payload)

		return
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		slog.Warn("webhook: session-status rejected by Laravel", "status", resp.StatusCode, "payload", payload)
	}
}

// StrPtr adalah helper kecil untuk membangun StatusPayload — field opsional
// json harus *string, bukan string kosong, supaya "phone_number": null
// (bukan "") saat memang tidak ada nilainya.
func StrPtr(s string) *string { return &s }

// IncomingMessagePayload — v0.13.1, listener pesan masuk. session_key sama
// dengan yang dipakai StatusPayload (reseller_id atau literal "direct").
// sender_phone SUDAH dikonversi ke format lokal "0xxx" (lihat
// jidnorm.ToLocalIndonesian) di pemanggil, bukan di sini — package ini tidak
// tahu apa-apa soal format nomor, cuma bawa payload apa adanya persis pola
// StatusPayload.
type IncomingMessagePayload struct {
	SessionKey  string `json:"session_key"`
	SenderPhone string `json:"sender_phone"`
	// IsLid — true kalau SenderPhone di atas adalah raw WhatsApp LID
	// (Linked ID), BUKAN nomor telepon asli — terjadi saat pengirim
	// pakai AddressingMode LID dan resolusi ke PN gagal di SEMUA fallback
	// (SenderAlt kosong DAN tidak ada mapping tersimpan di LIDStore
	// lokal, lihat internal/session/manager.go::resolveSenderPhone()).
	// false berarti SenderPhone genuinely nomor telepon (baik pengirim
	// AddressingMode PN dari awal, maupun LID yang berhasil di-resolve).
	IsLid     bool   `json:"is_lid"`
	ChatJID   string `json:"chat_jid"`
	Text      string `json:"text"`
	MessageID string `json:"message_id"`
	Timestamp int64  `json:"timestamp"`
	PushName  string `json:"push_name"`
}

// NotifyIncomingMessage — pola PERSIS NotifySessionStatus() di atas: HMAC
// sign, fire-and-forget, TIDAK ADA retry (dicek eksplisit sebelum
// implementasi — NotifySessionStatus juga tidak retry, dipulihkan lewat
// polling GET /sessions terpisah untuk status sesi). Pesan masuk TIDAK
// PUNYA mekanisme pemulihan setara — kalau POST ini gagal, pesan itu hilang
// permanen di v0.13.1 ini (tidak ada endpoint "riwayat pesan" whatsmeow yang
// bisa di-reconcile ulang seperti GET /sessions) — risiko yang sudah
// dilaporkan & diterima saat decision-gate, dicatat di sini sebagai jejak,
// bukan diselesaikan di sub-versi ini.
func (n *Notifier) NotifyIncomingMessage(payload IncomingMessagePayload) {
	body, err := json.Marshal(payload)
	if err != nil {
		slog.Error("webhook: failed to marshal incoming-message payload", "err", err, "payload", payload)

		return
	}

	timestamp := time.Now().Unix()
	signature := hmacsig.Sign(n.hmacSecret, string(body), timestamp)

	req, err := http.NewRequest(http.MethodPost, n.laravelBaseURL+"/api/v1/whatsapp/webhook/incoming-message", bytes.NewReader(body))
	if err != nil {
		slog.Error("webhook: failed to build incoming-message request", "err", err)

		return
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Whatsapp-Timestamp", strconv.FormatInt(timestamp, 10))
	req.Header.Set("X-Whatsapp-Signature", signature)

	resp, err := n.client.Do(req)
	if err != nil {
		slog.Error("webhook: failed to POST incoming-message to Laravel", "err", err, "sessionKey", payload.SessionKey, "messageId", payload.MessageID)

		return
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		slog.Warn("webhook: incoming-message rejected by Laravel", "status", resp.StatusCode, "sessionKey", payload.SessionKey, "messageId", payload.MessageID)
	}
}
