package session

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"

	"go.mau.fi/whatsmeow"
	"whatsapp-gateway/internal/webhook"
)

// Redesign trigger overlay QR — sebelumnya berbasis tebakan waktu (counter
// 3s×5x di sisi Laravel), sekarang berbasis sinyal GENUINE dari whatsmeow:
// drainQRChannel()'s "timeout" case yang BUKAN percobaan pertama (kode QR
// sudah pernah tampil sebelumnya) berarti channel whatsmeow benar-benar
// habis (array kode dari server sudah dikeluarkan semua, ATAU
// events.Disconnected — lihat go.mau.fi/whatsmeow qrchan.go's emitQRs()).
// SEBELUM fix ini, cabang non-first di "timeout" nol-efek (cuma
// slog.Warn()) — test ini membuktikan webhook QrExpired=true sekarang
// genuinely terkirim di titik itu, dan TIDAK terkirim untuk kasus timeout
// PERTAMA (yang tetap harus lewat firstErrCh seperti sebelumnya, tidak ada
// regresi).

// capturingServer menangkap SATU body request StatusPayload terakhir yang
// diterima notifier — cukup untuk skenario test di file ini (drainQRChannel
// dipanggil synchronous, bukan goroutine, jadi tidak ada race antar
// panggilan webhook dalam satu test).
func capturingServer(t *testing.T) (*httptest.Server, *[]webhook.StatusPayload) {
	t.Helper()

	var mu sync.Mutex
	received := make([]webhook.StatusPayload, 0, 4)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var payload webhook.StatusPayload
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			t.Fatalf("gagal decode body webhook: %v", err)
		}

		mu.Lock()
		received = append(received, payload)
		mu.Unlock()

		w.WriteHeader(http.StatusOK)
	}))

	return srv, &received
}

func newTestManager(t *testing.T, srv *httptest.Server) *Manager {
	t.Helper()

	return &Manager{
		ctx:      context.Background(),
		notifier: webhook.NewNotifier(srv.URL, "test-hmac-secret-tidak-dipakai-verifikasi-di-sini"),
	}
}

// Skenario UTAMA — QR sudah pernah tampil ("code" dulu), lalu channel
// genuinely habis ("timeout" kedua). Webhook HARUS terkirim dengan
// Status="qr_pending" (TIDAK berubah jadi "disconnected" — status itu
// sudah dipakai skenario transient-reconnect yang beda semantik total) dan
// QrExpired=true.
func TestDrainQRChannel_TimeoutAfterCodeAlreadyShown_SendsQrExpiredWebhook(t *testing.T) {
	srv, received := capturingServer(t)
	defer srv.Close()

	m := newTestManager(t, srv)
	e := &entry{key: "direct", status: StatusQRPending}

	qrChan := make(chan whatsmeow.QRChannelItem, 2)
	qrChan <- whatsmeow.QRChannelItem{Event: "code", Code: "1@ABC,DEF,GHI"}
	qrChan <- whatsmeow.QRChannelItem{Event: "timeout"}
	close(qrChan)

	firstCodeCh := make(chan string, 1)
	firstErrCh := make(chan error, 1)

	m.drainQRChannel(func() {}, e, qrChan, firstCodeCh, firstErrCh)

	if len(*received) != 2 {
		t.Fatalf("expected 2 webhook diterima (code lalu timeout), got %d: %+v", len(*received), *received)
	}

	codePayload := (*received)[0]
	if codePayload.QRCodeData == nil {
		t.Fatalf("payload pertama (code) harus punya QRCodeData")
	}
	if codePayload.QrExpired != nil {
		t.Fatalf("payload pertama (code) TIDAK boleh punya QrExpired, got %v", *codePayload.QrExpired)
	}

	timeoutPayload := (*received)[1]
	if timeoutPayload.Status != string(StatusQRPending) {
		t.Fatalf("expected status tetap %q (BUKAN disconnected), got %q", StatusQRPending, timeoutPayload.Status)
	}
	if timeoutPayload.QrExpired == nil || !*timeoutPayload.QrExpired {
		t.Fatalf("expected QrExpired=true di payload timeout, got %+v", timeoutPayload.QrExpired)
	}
	if timeoutPayload.QRCodeData != nil {
		t.Fatalf("payload timeout TIDAK boleh membawa QRCodeData baru (tidak ada kode baru untuk dikirim), got %v", *timeoutPayload.QRCodeData)
	}

	// firstCodeCh harus sudah terisi dari event "code" — firstErrCh TIDAK
	// boleh terisi (timeout ini bukan kegagalan percobaan pertama).
	select {
	case <-firstCodeCh:
	default:
		t.Fatalf("firstCodeCh seharusnya sudah terisi dari event code")
	}
	select {
	case err := <-firstErrCh:
		t.Fatalf("firstErrCh TIDAK boleh terisi untuk timeout non-pertama, got %v", err)
	default:
	}
}

// Skenario REGRESI — "timeout" SEBAGAI event PERTAMA (belum pernah ada
// "code" sama sekali) harus TETAP lewat firstErrCh seperti perilaku
// sebelumnya, dan TIDAK mengirim webhook QrExpired sama sekali (tidak ada
// QR yang pernah ditampilkan untuk "kedaluwarsa").
func TestDrainQRChannel_TimeoutAsFirstEvent_UsesFirstErrChNotWebhook(t *testing.T) {
	srv, received := capturingServer(t)
	defer srv.Close()

	m := newTestManager(t, srv)
	e := &entry{key: "direct", status: StatusQRPending}

	qrChan := make(chan whatsmeow.QRChannelItem, 1)
	qrChan <- whatsmeow.QRChannelItem{Event: "timeout"}
	close(qrChan)

	firstCodeCh := make(chan string, 1)
	firstErrCh := make(chan error, 1)

	m.drainQRChannel(func() {}, e, qrChan, firstCodeCh, firstErrCh)

	if len(*received) != 0 {
		t.Fatalf("expected NOL webhook untuk timeout sebagai percobaan pertama, got %d: %+v", len(*received), *received)
	}

	select {
	case err := <-firstErrCh:
		if err == nil {
			t.Fatalf("expected error non-nil di firstErrCh")
		}
	default:
		t.Fatalf("firstErrCh seharusnya terisi untuk timeout percobaan pertama")
	}
}
