// Package httpapi mereplikasi PERSIS 5 endpoint whatsapp-gateway/index.js
// (Node) — termasuk INKONSISTENSI envelope response yang sudah
// terdokumentasi di docs/whatsapp-gateway-api-surface.md (sessions/
// qr_code_data/pairing_code di level TERATAS, bukan di dalam "data") —
// SENGAJA TIDAK "diperbaiki" ke konsisten, supaya sisi Laravel
// (WhatsappSessionService) tidak perlu diubah sama sekali.
package httpapi

import (
	"bytes"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"

	"whatsapp-gateway/internal/hmacsig"
	"whatsapp-gateway/internal/session"
)

type Server struct {
	manager    *session.Manager
	hmacSecret string
}

func NewServer(manager *session.Manager, hmacSecret string) *Server {
	return &Server{manager: manager, hmacSecret: hmacSecret}
}

func (s *Server) Routes() http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /sessions", s.withHMAC(s.handleListSessions))
	mux.HandleFunc("GET /sessions/{sessionKey}/health", s.withHMAC(s.handleHealth))
	mux.HandleFunc("GET /sessions/{sessionKey}/qr", s.withHMAC(s.handleQR))
	mux.HandleFunc("POST /sessions/{sessionKey}/pair", s.withHMAC(s.handlePair))
	mux.HandleFunc("POST /sessions/{sessionKey}/logout", s.withHMAC(s.handleLogout))
	mux.HandleFunc("POST /sessions/{sessionKey}/send", s.withHMAC(s.handleSend))
	mux.HandleFunc("GET /sessions/{sessionKey}/groups", s.withHMAC(s.handleListGroups))
	mux.HandleFunc("POST /sessions/{sessionKey}/send-group", s.withHMAC(s.handleSendGroup))

	return mux
}

// envelope — bentuk dasar {success, message, data, meta} yang selalu ada,
// PERSIS App\Http\Controllers\Concerns\ApiResponds (Laravel) dan
// whatsapp-gateway/index.js (Node) lama. Field tambahan level-teratas
// (sessions/qr_code_data/pairing_code) di-inject manual per endpoint lewat
// map, bukan struct tetap — supaya persis "sibling dari data", bukan
// nested di dalamnya.
func writeEnvelope(w http.ResponseWriter, status int, success bool, message string, extra map[string]interface{}) {
	body := map[string]interface{}{
		"success": success,
		"message": message,
		"data":    nil,
		"meta":    map[string]interface{}{},
	}

	for k, v := range extra {
		body[k] = v
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func (s *Server) withHMAC(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		rawBody, err := io.ReadAll(r.Body)
		if err != nil {
			writeEnvelope(w, http.StatusBadRequest, false, "Failed to read request body", nil)

			return
		}
		r.Body.Close()

		signature := r.Header.Get("X-Whatsapp-Signature")
		timestamp := r.Header.Get("X-Whatsapp-Timestamp")

		if !hmacsig.Verify(s.hmacSecret, string(rawBody), signature, timestamp) {
			slog.Warn("rejected request with invalid/missing HMAC signature", "path", r.URL.Path)
			writeEnvelope(w, http.StatusUnauthorized, false, "Invalid signature", nil)

			return
		}

		// Simpan body yang sudah dibaca supaya handler berikutnya masih
		// bisa decode JSON darinya (io.ReadAll di atas sudah mengonsumsi
		// r.Body sekali).
		r.Body = io.NopCloser(bytes.NewReader(rawBody))

		next(w, r)
	}
}

func (s *Server) handleListSessions(w http.ResponseWriter, _ *http.Request) {
	writeEnvelope(w, http.StatusOK, true, "OK", map[string]interface{}{
		"sessions": s.manager.ListStates(),
	})
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("sessionKey")

	state, ok := s.manager.GetState(key)
	if !ok {
		// Node mengembalikan getState() apa adanya (bisa undefined/null)
		// dengan tetap HTTP 200 — dipertahankan sama persis, endpoint ini
		// toh tidak punya caller Laravel aktif (lihat docs/whatsapp-
		// gateway-api-surface.md bagian 5).
		writeEnvelope(w, http.StatusOK, true, "OK", map[string]interface{}{"data": nil})

		return
	}

	writeEnvelope(w, http.StatusOK, true, "OK", map[string]interface{}{"data": state})
}

func (s *Server) handleQR(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("sessionKey")

	qrCodeData, err := s.manager.GetOrRefreshQR(key)
	if err != nil {
		slog.Error("failed to get/refresh QR", "sessionKey", key, "err", err)
		writeEnvelope(w, http.StatusInternalServerError, false, err.Error(), nil)

		return
	}

	writeEnvelope(w, http.StatusOK, true, "OK", map[string]interface{}{"qr_code_data": qrCodeData})
}

func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("sessionKey")

	if err := s.manager.Logout(key); err != nil {
		slog.Error("failed to logout session", "sessionKey", key, "err", err)
		writeEnvelope(w, http.StatusInternalServerError, false, err.Error(), nil)

		return
	}

	writeEnvelope(w, http.StatusOK, true, "Logged out", nil)
}

type pairRequest struct {
	PhoneNumber string `json:"phone_number"`
}

func (s *Server) handlePair(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("sessionKey")

	var req pairRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.PhoneNumber == "" {
		writeEnvelope(w, http.StatusUnprocessableEntity, false, "phone_number is required", nil)

		return
	}

	code, err := s.manager.RequestPairingCode(key, req.PhoneNumber)
	if err != nil {
		slog.Error("failed to request pairing code", "sessionKey", key, "err", err)
		writeEnvelope(w, http.StatusInternalServerError, false, err.Error(), nil)

		return
	}

	writeEnvelope(w, http.StatusOK, true, "OK", map[string]interface{}{"pairing_code": code})
}

type sendRequest struct {
	PhoneNumber string `json:"phone_number"`
	Message     string `json:"message"`
}

func (s *Server) handleSend(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("sessionKey")

	var req sendRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.PhoneNumber == "" || req.Message == "" {
		writeEnvelope(w, http.StatusUnprocessableEntity, false, "phone_number and message are required", nil)

		return
	}

	if err := s.manager.SendMessage(key, req.PhoneNumber, req.Message); err != nil {
		slog.Error("failed to send message", "sessionKey", key, "err", err)
		// 502, BUKAN 500 — beda sengaja dari kegagalan QR/pairing, persis
		// index.js Node (bedakan "gateway itu sendiri error" vs "pesan
		// spesifik ini gagal dikirim").
		writeEnvelope(w, http.StatusBadGateway, false, err.Error(), nil)

		return
	}

	writeEnvelope(w, http.StatusOK, true, "Sent", nil)
}

// handleListGroups — v0.26.4. Daftar SEMUA grup yang bot session ini sudah
// jadi anggota, apa adanya (lihat docblock Manager.ListGroups()). Field
// "groups" di level teratas, sibling dari "data" — pola sama persis
// "sessions"/"qr_code_data"/"pairing_code" di endpoint lain.
func (s *Server) handleListGroups(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("sessionKey")

	groups, err := s.manager.ListGroups(key)
	if err != nil {
		slog.Error("failed to list groups", "sessionKey", key, "err", err)
		writeEnvelope(w, http.StatusInternalServerError, false, err.Error(), nil)

		return
	}

	writeEnvelope(w, http.StatusOK, true, "OK", map[string]interface{}{"groups": groups})
}

type sendGroupRequest struct {
	GroupJID string `json:"group_jid"`
	Message  string `json:"message"`
}

// handleSendGroup — v0.26.4. Endpoint TERPISAH dari handleSend (bukan
// field opsional tambahan di sendRequest) — "1 endpoint = 1 tanggung
// jawab jelas", konsisten gaya endpoint lain di file ini. Validasi
// bentuk JID grup (types.ParseJID + guard Server="g.us") terjadi DI
// Manager.SendGroupMessage(), bukan di sini — handler HTTP ini murni
// decode+dispatch, sama pembagian tanggung jawab dengan handleSend.
func (s *Server) handleSendGroup(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("sessionKey")

	var req sendGroupRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.GroupJID == "" || req.Message == "" {
		writeEnvelope(w, http.StatusUnprocessableEntity, false, "group_jid and message are required", nil)

		return
	}

	if err := s.manager.SendGroupMessage(key, req.GroupJID, req.Message); err != nil {
		slog.Error("failed to send group message", "sessionKey", key, "groupJid", req.GroupJID, "err", err)

		// "permanent": true — hasil pembacaan langsung source whatsmeow
		// (lihat docblock session.IsPermanentGroupSendError()), BUKAN
		// ditebak dari pesan error. Dipakai Laravel untuk memutuskan
		// retry berulang atau langsung menyerah (bot bukan anggota grup
		// itu lagi — mengulang tidak akan pernah berhasil sendiri).
		extra := map[string]interface{}{}
		if session.IsPermanentGroupSendError(err) {
			extra["permanent"] = true
		}

		// 502, BUKAN 500 — sama alasan persis handleSend: bedakan "gateway
		// itu sendiri error" vs "pesan spesifik ini gagal dikirim".
		writeEnvelope(w, http.StatusBadGateway, false, err.Error(), extra)

		return
	}

	writeEnvelope(w, http.StatusOK, true, "Sent", nil)
}
