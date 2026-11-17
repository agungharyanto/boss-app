package session

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"time"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
)

// v0.13.6 — TERIMA media masuk (foto/dokumen/audio/video/stiker, 1:1 saja —
// grup tetap dibuang di onIncomingMessage). Gateway MENGUNDUH (satu-satunya
// pemegang kunci dekripsi whatsmeow), menyimpan ke volume bersama
// `incomingMediaDir` + sidecar JSON, lalu memperkaya webhook. Laravel HANYA
// MEMBACA volume (reconcile sidecar yang webhook-nya hilang) — TIDAK pernah
// menghapus; pembersihan volume dilakukan gateway sendiri (retensi pendek).

var (
	// Direktori volume bersama (di-bind-mount juga ke boss-app read-only).
	incomingMediaDir = strEnv("WA_INCOMING_MEDIA_DIR", "/wa-incoming")
	// Retensi file di volume GATEWAY (hari) — pendek; Laravel sudah menyalin
	// ke disk privatnya sendiri. Default 7 hari.
	incomingMediaRetentionDays = intEnv("WA_INCOMING_MEDIA_RETENTION_DAYS", 7)
)

func strEnv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}

	return def
}

// Cap ukuran per jenis (byte) — ditolak SEBELUM unduh bila FileLength
// metadata melebihi, lalu dicek ulang atas ukuran nyata hasil unduh.
var incomingCaps = map[string]int64{
	"image":    10 * 1024 * 1024,
	"document": 16 * 1024 * 1024,
	"audio":    16 * 1024 * 1024,
	"video":    32 * 1024 * 1024,
	"sticker":  1 * 1024 * 1024,
}

// Whitelist MIME per jenis → ekstensi file. Mime WA-reported difilter di sini
// (pass pertama); Laravel re-validasi dari ISI byte (finfo) sebagai otoritas.
// Dokumen SENGAJA hanya PDF (keputusan D1) — SVG/HTML/exe/apk/js/sh yang
// dikirim sebagai dokumen otomatis ditolak karena mime-nya bukan
// application/pdf.
var incomingAllowedMimes = map[string]map[string]string{
	"image":    {"image/jpeg": "jpg", "image/png": "png", "image/webp": "webp"},
	"document": {"application/pdf": "pdf"},
	"audio":    {"audio/ogg": "ogg", "audio/mpeg": "mp3", "audio/mp4": "m4a", "audio/amr": "amr", "audio/aac": "aac"},
	"video":    {"video/mp4": "mp4", "video/3gpp": "3gp"},
	"sticker":  {"image/webp": "webp"},
}

// incomingMedia — hasil deteksi satu pesan media masuk.
type incomingMedia struct {
	mediaType  string
	mime       string
	caption    string
	fileName   string
	fileLength uint64
	downloader whatsmeow.DownloadableMessage
}

// baseMime — buang parameter setelah ';' (mis. "audio/ogg; codecs=opus" →
// "audio/ogg") + trim + lowercase.
func baseMime(m string) string {
	if i := strings.IndexByte(m, ';'); i >= 0 {
		m = m[:i]
	}

	return strings.ToLower(strings.TrimSpace(m))
}

// detectIncomingMedia — kenali jenis media dari waE2E.Message. Grup sudah
// difilter di pemanggil. Mengembalikan ok=false untuk teks/lokasi/kontak/dll.
func detectIncomingMedia(msg *waE2E.Message) (incomingMedia, bool) {
	switch {
	case msg.GetImageMessage() != nil:
		im := msg.GetImageMessage()

		return incomingMedia{"image", baseMime(im.GetMimetype()), im.GetCaption(), "", im.GetFileLength(), im}, true
	case msg.GetDocumentMessage() != nil:
		d := msg.GetDocumentMessage()

		return incomingMedia{"document", baseMime(d.GetMimetype()), d.GetCaption(), d.GetFileName(), d.GetFileLength(), d}, true
	case msg.GetAudioMessage() != nil:
		a := msg.GetAudioMessage()

		return incomingMedia{"audio", baseMime(a.GetMimetype()), "", "", a.GetFileLength(), a}, true
	case msg.GetVideoMessage() != nil:
		v := msg.GetVideoMessage()

		return incomingMedia{"video", baseMime(v.GetMimetype()), v.GetCaption(), "", v.GetFileLength(), v}, true
	case msg.GetStickerMessage() != nil:
		s := msg.GetStickerMessage()

		return incomingMedia{"sticker", baseMime(s.GetMimetype()), "", "", s.GetFileLength(), s}, true
	}

	return incomingMedia{}, false
}

// incomingMediaSidecar — ditulis bersama file media; dibaca command reconcile
// Laravel untuk menutup webhook yang hilang (idempoten via message_id UNIQUE).
type incomingMediaSidecar struct {
	MessageID   string `json:"message_id"`
	SessionKey  string `json:"session_key"`
	SenderPhone string `json:"sender_phone"`
	IsLid       bool   `json:"is_lid"`
	ChatJID     string `json:"chat_jid"`
	MediaType   string `json:"media_type"`
	Mime        string `json:"mime"`
	FileName    string `json:"file_name"`
	FileSize    int64  `json:"file_size"`
	Caption     string `json:"caption"`
	Sha256      string `json:"sha256"`
	Timestamp   int64  `json:"timestamp"`
	PushName    string `json:"push_name"`
	MediaPath   string `json:"media_path"`
}

// storeIncomingMedia — tulis file media + sidecar JSON ke
// incomingMediaDir/{session_key}/{message_id}.{ext}. Dir 0755 & file 0644
// (world-readable) supaya www-data (boss-app, uid beda) bisa membaca meski
// gateway menulis sebagai root. Mengembalikan path relatif terhadap
// incomingMediaDir (dipakai di webhook media_path) + sidecar.
func storeIncomingMedia(sessionKey, messageID, ext string, data []byte, meta incomingMediaSidecar) (string, error) {
	dir := filepath.Join(incomingMediaDir, sanitizePathPart(sessionKey))
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", err
	}

	base := sanitizePathPart(messageID)
	rel := filepath.Join(sanitizePathPart(sessionKey), base+"."+ext)
	full := filepath.Join(incomingMediaDir, rel)

	// Tulis file media (temp + rename = atomic; hindari sidecar menunjuk file
	// setengah tertulis saat reconcile membacanya).
	if err := writeFileAtomic(full, data, 0o644); err != nil {
		return "", err
	}

	meta.MediaPath = rel
	sidecarBytes, err := json.MarshalIndent(meta, "", "  ")
	if err != nil {
		return "", err
	}

	sidecarPath := filepath.Join(dir, base+".json")
	if err := writeFileAtomic(sidecarPath, sidecarBytes, 0o644); err != nil {
		return "", err
	}

	return rel, nil
}

// sanitizePathPart — cegah traversal: hanya izinkan [A-Za-z0-9._-], sisanya
// jadi '_'. session_key ("direct"/angka) & message_id WhatsApp memang sudah
// aman, ini defense-in-depth.
func sanitizePathPart(s string) string {
	var b strings.Builder
	for _, r := range s {
		switch {
		case r >= 'A' && r <= 'Z', r >= 'a' && r <= 'z', r >= '0' && r <= '9', r == '.', r == '_', r == '-':
			b.WriteRune(r)
		default:
			b.WriteByte('_')
		}
	}
	out := b.String()
	if out == "" || out == "." || out == ".." {
		return "_"
	}

	return out
}

func writeFileAtomic(path string, data []byte, mode os.FileMode) error {
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, mode); err != nil {
		return err
	}
	// os.WriteFile menghormati umask; pastikan mode final eksplisit.
	if err := os.Chmod(tmp, mode); err != nil {
		_ = os.Remove(tmp)

		return err
	}

	return os.Rename(tmp, path)
}

func sha256Hex(data []byte) string {
	sum := sha256.Sum256(data)

	return hex.EncodeToString(sum[:])
}

// startIncomingMediaCleanup — goroutine retensi volume GATEWAY: hapus file +
// sidecar lebih tua dari incomingMediaRetentionDays, tiap jam. Laravel sudah
// menyalin ke disk privatnya sendiri jauh sebelum ini. Berhenti saat ctx
// selesai.
func startIncomingMediaCleanup(ctx context.Context) {
	if incomingMediaRetentionDays <= 0 {
		return
	}

	go func() {
		ticker := time.NewTicker(1 * time.Hour)
		defer ticker.Stop()

		pruneIncomingMedia() // sekali di awal

		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				pruneIncomingMedia()
			}
		}
	}()
}

func pruneIncomingMedia() {
	cutoff := time.Now().AddDate(0, 0, -incomingMediaRetentionDays)

	_ = filepath.Walk(incomingMediaDir, func(path string, info os.FileInfo, err error) error {
		if err != nil || info == nil || info.IsDir() {
			return nil
		}
		if info.ModTime().Before(cutoff) {
			if rmErr := os.Remove(path); rmErr != nil {
				slog.Warn("incoming-media cleanup: gagal hapus", "path", path, "err", rmErr)
			}
		}

		return nil
	})
}
