package session

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"image"
	"image/jpeg"
	_ "image/png" // daftarkan decoder PNG untuk image.Decode (thumbnail)
	"os"
	"strconv"
	"time"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"google.golang.org/protobuf/proto"
)

// Konfigurasi jalur media — konfigurabel lewat env (default aman). mediaTimeout
// SENGAJA lebih panjang dari sendTimeout teks (20s) karena ada Upload ke server
// WhatsApp. mediaConcurrency membatasi jumlah Upload+Send media bersamaan
// (semafor) agar memori tidak meledak kalau banyak media di-antre sekaligus.
var (
	mediaTimeout     = durationSecondsEnv("WA_MEDIA_TIMEOUT_SECONDS", 75*time.Second)
	mediaConcurrency = intEnv("WA_MEDIA_CONCURRENCY", 3)
)

func durationSecondsEnv(key string, def time.Duration) time.Duration {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			return time.Duration(n) * time.Second
		}
	}

	return def
}

func intEnv(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			return n
		}
	}

	return def
}

// mediaClient — abstraksi TIPIS atas *whatsmeow.Client, HANYA dua method
// yang dibutuhkan jalur media (Upload + SendMessage), supaya buildAndSendMedia
// bisa di-stub di test tanpa koneksi WhatsApp nyata. *whatsmeow.Client
// memenuhi interface ini apa adanya (signature identik) — lihat SendMedia/
// SendGroupMedia yang meneruskan e.client.
type mediaClient interface {
	Upload(ctx context.Context, plaintext []byte, appInfo whatsmeow.MediaType) (whatsmeow.UploadResponse, error)
	SendMessage(ctx context.Context, to types.JID, message *waE2E.Message, extra ...whatsmeow.SendRequestExtra) (whatsmeow.SendResponse, error)
}

// MIME → apakah gambar (vs dokumen). Hanya 3 tipe di whitelist (divalidasi
// lebih dulu di httpapi): image/jpeg, image/png → gambar; application/pdf →
// dokumen.
func isImageMime(mime string) bool {
	return mime == "image/jpeg" || mime == "image/png"
}

// buildMediaMessage — bangun waE2E.Message (Image/Document) dari hasil
// Upload. Fungsi MURNI (tanpa koneksi) supaya bisa dites langsung.
// Thumbnail HANYA untuk gambar dan bersifat best-effort (nil kalau gagal) —
// JANGAN pernah menggagalkan pengiriman karena thumbnail.
func buildMediaMessage(isImage bool, caption, mimetype, filename string, up whatsmeow.UploadResponse, data []byte) *waE2E.Message {
	fileLength := uint64(len(data))

	if isImage {
		img := &waE2E.ImageMessage{
			URL:           proto.String(up.URL),
			DirectPath:    proto.String(up.DirectPath),
			MediaKey:      up.MediaKey,
			Mimetype:      proto.String(mimetype),
			FileEncSHA256: up.FileEncSHA256,
			FileSHA256:    up.FileSHA256,
			FileLength:    proto.Uint64(fileLength),
			JPEGThumbnail: generateJPEGThumbnail(data), // nil kalau gagal — aman
		}
		if caption != "" {
			img.Caption = proto.String(caption)
		}

		return &waE2E.Message{ImageMessage: img}
	}

	doc := &waE2E.DocumentMessage{
		URL:           proto.String(up.URL),
		DirectPath:    proto.String(up.DirectPath),
		MediaKey:      up.MediaKey,
		Mimetype:      proto.String(mimetype),
		FileEncSHA256: up.FileEncSHA256,
		FileSHA256:    up.FileSHA256,
		FileLength:    proto.Uint64(fileLength),
		FileName:      proto.String(filename),
		Title:         proto.String(filename),
	}
	if caption != "" {
		doc.Caption = proto.String(caption)
	}

	return &waE2E.Message{DocumentMessage: doc}
}

// generateJPEGThumbnail — thumbnail JPEG kecil (maks sisi 72px) dengan
// pustaka STANDAR saja (image + image/jpeg + image/png). Downscale
// nearest-neighbor sederhana (tanpa dependensi baru; thumbnail WhatsApp toh
// sangat kecil). Kembalikan nil pada error APA PUN — pemanggil kirim tanpa
// thumbnail, tidak menggagalkan pengiriman.
func generateJPEGThumbnail(data []byte) []byte {
	src, _, err := image.Decode(bytes.NewReader(data))
	if err != nil {
		return nil
	}

	b := src.Bounds()
	srcW, srcH := b.Dx(), b.Dy()
	if srcW <= 0 || srcH <= 0 {
		return nil
	}

	const maxSide = 72
	dstW, dstH := srcW, srcH
	if srcW > maxSide || srcH > maxSide {
		if srcW >= srcH {
			dstW = maxSide
			dstH = srcH * maxSide / srcW
		} else {
			dstH = maxSide
			dstW = srcW * maxSide / srcH
		}
	}
	if dstW < 1 {
		dstW = 1
	}
	if dstH < 1 {
		dstH = 1
	}

	dst := image.NewRGBA(image.Rect(0, 0, dstW, dstH))
	for y := 0; y < dstH; y++ {
		sy := b.Min.Y + y*srcH/dstH
		for x := 0; x < dstW; x++ {
			sx := b.Min.X + x*srcW/dstW
			dst.Set(x, y, src.At(sx, sy))
		}
	}

	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, dst, &jpeg.Options{Quality: 50}); err != nil {
		return nil
	}

	return buf.Bytes()
}

// sendMediaToJID — inti kirim media generik (individu maupun grup; whatsmeow
// SendMessage menerima types.JID apa pun). Upload lalu SendMessage dengan
// timeout khusus media (lebih panjang dari sendTimeout teks). Pemetaan error
// IDENTIK jalur teks (DeadlineExceeded → "send timeout").
func sendMediaToJID(ctx context.Context, cli mediaClient, jid types.JID, timeout time.Duration, isImage bool, caption, mimetype, filename string, data []byte) error {
	sendCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	mediaType := whatsmeow.MediaDocument
	if isImage {
		mediaType = whatsmeow.MediaImage
	}

	up, err := cli.Upload(sendCtx, data, mediaType)
	if err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return fmt.Errorf("media upload timeout after %s", timeout)
		}

		return fmt.Errorf("media upload failed: %w", err)
	}

	msg := buildMediaMessage(isImage, caption, mimetype, filename, up, data)

	if _, err := cli.SendMessage(sendCtx, jid, msg); err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return fmt.Errorf("send timeout after %s", timeout)
		}

		return err
	}

	return nil
}
