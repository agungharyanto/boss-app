// Package hmacsig mengimplementasikan skema HMAC yang PERSIS sama dengan
// App\Support\WhatsappHmac.php (Laravel) dan whatsapp-gateway/src/hmac.js
// (Node, versi lama) — signing string "{timestamp}.{raw_body}",
// HMAC-SHA256 hex, toleransi replay 300 detik, perbandingan timing-safe.
// Lihat docs/whatsapp-gateway-api-surface.md bagian 1 untuk kontrak lengkap.
package hmacsig

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"strconv"
	"time"
)

// ToleranceSeconds — jendela replay, identik di ketiga sisi (PHP, Node,
// Go). JANGAN diubah sepihak di satu sisi saja.
const ToleranceSeconds = 300

// Sign menghasilkan hex HMAC-SHA256 atas "{timestamp}.{body}".
func Sign(secret string, body string, timestamp int64) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(fmt.Sprintf("%d.%s", timestamp, body)))

	return hex.EncodeToString(mac.Sum(nil))
}

// Verify mengecek signature DAN jendela waktu replay sekaligus — kedua-duanya
// wajib lolos, sama seperti WhatsappHmac::verify()/hmac.js verify().
func Verify(secret, body, signature, timestampHeader string) bool {
	if secret == "" || signature == "" || timestampHeader == "" {
		return false
	}

	timestamp, err := strconv.ParseInt(timestampHeader, 10, 64)
	if err != nil {
		return false
	}

	now := time.Now().Unix()

	diff := now - timestamp
	if diff < 0 {
		diff = -diff
	}

	if diff > ToleranceSeconds {
		return false
	}

	expected := Sign(secret, body, timestamp)

	// hmac.Equal membandingkan panjang dulu lalu byte demi byte constant-time
	// — padanan Go dari crypto.timingSafeEqual (Node) / hash_equals (PHP).
	return hmac.Equal([]byte(expected), []byte(signature))
}
