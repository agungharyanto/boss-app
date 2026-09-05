// Package jidnorm mem-port PERSIS logic toJid() lama di
// whatsapp-gateway/src/sessionManager.js — fix bug NYATA v0.9.6 (root cause
// OTP timeout berbulan-bulan, lihat CLAUDE.md bagian "AKAR MASALAH OTP
// timeout = FORMAT NOMOR"). JANGAN sederhanakan tanpa membaca catatan itu
// dulu — nomor lokal Indonesia yang tidak dinormalisasi membuat JID tidak
// sah, dan sock.sendMessage()/client.SendMessage() menggantung sampai
// timeout, bukan gagal cepat dengan error jelas.
package jidnorm

import (
	"regexp"
	"strings"

	"go.mau.fi/whatsmeow/types"
)

var nonDigit = regexp.MustCompile(`[^0-9]`)

// NormalizeIndonesian: 0xxx -> 62xxx, 62xxx -> tetap (tanda + sudah hilang
// lewat strip non-digit sebelum sampai di sini, jadi "+62xxx" otomatis jadi
// "62xxx"), 8xxx (tanpa 0/62 di depan) -> 62 + 8xxx. Input lain (tidak
// dikenali polanya) dikembalikan apa adanya setelah strip non-digit —
// perilaku sama seperti toJid() lama, tidak melempar error di titik ini.
func NormalizeIndonesian(raw string) string {
	digits := nonDigit.ReplaceAllString(raw, "")

	switch {
	case strings.HasPrefix(digits, "62"):
		return digits
	case strings.HasPrefix(digits, "0"):
		return "62" + digits[1:]
	case strings.HasPrefix(digits, "8"):
		return "62" + digits
	default:
		return digits
	}
}

// BuildJID membentuk JID standar user@s.whatsapp.net dari nomor yang SUDAH
// dinormalisasi lewat NormalizeIndonesian — jangan panggil langsung dengan
// input mentah dari luar.
func BuildJID(normalizedNumber string) types.JID {
	return types.NewJID(normalizedNumber, types.DefaultUserServer)
}
