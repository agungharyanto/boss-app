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

// ToLocalIndonesian — KEBALIKAN NormalizeIndonesian, v0.13.1. Nomor pengirim
// pesan masuk dari JID whatsmeow (Info.Sender.User) NATIVE-nya sudah format
// "62xxx" (kode negara, tanpa 0 di depan) — tapi `customers.phone_number`/
// `technicians.phone` di boss_db SELALU format lokal "0xxx" (dikonfirmasi
// 553/553 baris customers saat investigasi kick-off v0.13.1, NOL baris
// "62xxx"). Fungsi ini HANYA dipakai untuk mengisi field `sender_phone` di
// payload webhook incoming-message — supaya v0.13.3 (auth teknisi via match
// phone) dan modul lain yang membaca tabel `whatsapp_incoming_messages` tidak
// perlu konversi tambahan sendiri-sendiri. `NormalizeIndonesian`/`BuildJID`
// TIDAK disentuh — tetap dipakai apa adanya oleh jalur kirim pesan (`/send`).
//
// Input diasumsikan SUDAH melalui bentuk JID number (hasil `Sender.User`,
// selalu digit polos) — tetap strip non-digit dulu sebagai jaring pengaman,
// konsisten dengan gaya defensif NormalizeIndonesian. "62xxx" -> "0xxx";
// input yang tidak diawali "62" dikembalikan apa adanya setelah strip
// non-digit (tidak menebak-nebak format lain, sama filosofi
// NormalizeIndonesian's default branch).
func ToLocalIndonesian(raw string) string {
	digits := nonDigit.ReplaceAllString(raw, "")

	if strings.HasPrefix(digits, "62") {
		return "0" + digits[2:]
	}

	return digits
}
