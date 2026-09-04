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

	"whatsapp-gateway-go/internal/hmacsig"
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
