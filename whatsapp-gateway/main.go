// whatsapp-gateway — gateway WhatsApp berbasis Go + whatsmeow. Menggantikan
// gateway Node/Baileys lama (v0.4.0-v0.9.9) sejak migrasi selesai — lihat
// docs/whatsapp-gateway-api-surface.md dan CLAUDE.md untuk kronologi
// migrasi lengkap dan kontrak HTTP/HMAC/webhook (dipertahankan identik
// dengan gateway lama supaya sisi Laravel tidak perlu berubah kecuali
// target URL/port).
package main

import (
	"context"
	"database/sql"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"

	_ "github.com/lib/pq"
	"go.mau.fi/whatsmeow/proto/waCompanionReg"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/store/sqlstore"
	waLog "go.mau.fi/whatsmeow/util/log"
	"google.golang.org/protobuf/proto"

	"whatsapp-gateway/internal/httpapi"
	"whatsapp-gateway/internal/session"
	"whatsapp-gateway/internal/webhook"
)

// disableFullHistorySync — padanan opsi Baileys `syncFullHistory: false` di
// sessionManager.js lama. TEMUAN investigasi (dikonfirmasi lewat pembacaan
// source whatsmeow langsung, bukan asumsi): whatsmeow TIDAK punya field
// per-Client untuk ini — konfigurasi historySyncConfig dikirim ke server
// WhatsApp sebagai bagian dari `store.DeviceProps` (variabel package-level
// GLOBAL di paket go.mau.fi/whatsmeow/store, dipakai SEMUA client dalam satu
// proses, bukan per-sesi) saat pairing (lihat store/clientpayload.go,
// pair.go). Diset SEKALI di sini, sebelum sesi mana pun dibuat — bukan
// per-panggilan seperti opsi makeWASocket() Baileys, karena memang tidak
// bisa per-sesi di whatsmeow.
//
// markOnlineOnConnect: false (Baileys) TIDAK punya field setara apa pun di
// whatsmeow (dikonfirmasi tidak ada field seperti itu di Client struct) —
// tapi whatsmeow TIDAK PERNAH mengirim presence "available" secara otomatis
// sendiri; presence hanya terkirim kalau kode pemanggil eksplisit memanggil
// client.SendPresence(...). Package internal/session TIDAK PERNAH memanggil
// SendPresence sama sekali — jadi kesetaraan perilaku di sini STRUKTURAL
// (tidak ada kode yang mengirim presence), bukan sekadar "kebetulan default
// sama seperti Baileys".
func disableFullHistorySync() {
	store.DeviceProps.HistorySyncConfig = &waCompanionReg.DeviceProps_HistorySyncConfig{
		FullSyncDaysLimit:   proto.Uint32(0),
		FullSyncSizeMbLimit: proto.Uint32(0),
		StorageQuotaMb:      proto.Uint32(0),
	}
}

func main() {
	port := envOr("PORT", "3000")
	hmacSecret := os.Getenv("WHATSAPP_GATEWAY_HMAC_SECRET")
	laravelBaseURL := envOr("LARAVEL_BASE_URL", "http://boss-nginx")
	dbDSN := os.Getenv("WHATSMEOW_DB_DSN")
	logLevel := envOr("LOG_LEVEL", "info")

	setupLogger(logLevel)
	disableFullHistorySync()

	if hmacSecret == "" {
		slog.Warn("WHATSAPP_GATEWAY_HMAC_SECRET is empty — every request will be rejected. Set it in whatsapp-gateway's env.")
	}

	if dbDSN == "" {
		slog.Error("WHATSMEOW_DB_DSN is required (Postgres DSN for the whatsmeow_store database)")
		os.Exit(1)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	db, err := sql.Open("postgres", dbDSN)
	if err != nil {
		slog.Error("failed to open Postgres connection", "err", err)
		os.Exit(1)
	}
	defer db.Close()

	if err := db.PingContext(ctx); err != nil {
		slog.Error("failed to ping Postgres (whatsmeow_store)", "err", err)
		os.Exit(1)
	}

	dbLog := waLog.Stdout("Database", logLevel, true)

	// sqlstore.New melakukan koneksi + upgrade skema whatsmeow SENDIRI
	// (tabel-tabel yang dikelola whatsmeow, terpisah dari boss_session_keys
	// yang dibuat internal/session.NewManager) — lihat
	// docs/whatsapp-gateway-api-surface.md bagian 3 untuk keputusan
	// database logis terpisah ini.
	container, err := sqlstore.New(ctx, "postgres", dbDSN, dbLog)
	if err != nil {
		slog.Error("failed to initialize whatsmeow sqlstore container", "err", err)
		os.Exit(1)
	}

	clientLog := waLog.Stdout("Client", logLevel, true)
	notifier := webhook.NewNotifier(laravelBaseURL, hmacSecret)

	manager, err := session.NewManager(ctx, container, db, clientLog, notifier)
	if err != nil {
		slog.Error("failed to initialize session manager", "err", err)
		os.Exit(1)
	}

	slog.Info("restoring previously paired sessions", "component", "boot")
	manager.RestoreAll()

	server := httpapi.NewServer(manager, hmacSecret)

	httpServer := &http.Server{
		Addr:    ":" + port,
		Handler: server.Routes(),
	}

	go func() {
		<-ctx.Done()
		slog.Info("shutting down")
		_ = httpServer.Close()
	}()

	slog.Info("whatsapp-gateway listening", "port", port)

	if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		slog.Error("http server error", "err", err)
		os.Exit(1)
	}
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}

	return fallback
}

func setupLogger(level string) {
	var lvl slog.Level

	switch level {
	case "debug":
		lvl = slog.LevelDebug
	case "warn":
		lvl = slog.LevelWarn
	case "error":
		lvl = slog.LevelError
	default:
		lvl = slog.LevelInfo
	}

	slog.SetDefault(slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: lvl})))
}
