package session

import (
	"errors"
	"fmt"
	"strings"
	"testing"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/types"
)

// v0.26.4 — parseGroupJID() diekstrak dari SendGroupMessage() supaya guard
// "ini genuinely JID grup, bukan individu" bisa dites independen dari
// *entry/koneksi whatsmeow sungguhan (tidak butuh device store — lihat
// docblock parseGroupJID() di manager.go).

// JID grup sah — hasil ParseJID harus Server=g.us, dan JID.String() harus
// round-trip (bisa diparse balik jadi bentuk yang sama), sama seperti nilai
// yang genuinely dikembalikan GetJoinedGroups() lalu disimpan Laravel.
func TestParseGroupJID_ValidGroupJID_ReturnsGroupJID(t *testing.T) {
	jid, err := parseGroupJID("120363012345678901@g.us")

	if err != nil {
		t.Fatalf("expected no error untuk JID grup sah, got %v", err)
	}
	if jid.Server != types.GroupServer {
		t.Fatalf("expected Server=%q, got %q", types.GroupServer, jid.Server)
	}
	if jid.User != "120363012345678901" {
		t.Fatalf("expected User=120363012345678901, got %q", jid.User)
	}
}

// KASUS UTAMA yang jadi alasan guard ini ada sama sekali: JID individu
// (bentuk yang genuinely dipakai nomor telepon biasa) dikirim ke jalur
// kirim-grup — HARUS ditolak dengan error jelas, BUKAN diam-diam diterima
// dan mengirim pesan "grup" ke satu nomor individu.
func TestParseGroupJID_IndividualJID_IsRejected(t *testing.T) {
	_, err := parseGroupJID("6281234567890@s.whatsapp.net")

	if err == nil {
		t.Fatalf("expected error — JID individu tidak boleh lolos sebagai JID grup")
	}
	if !strings.Contains(err.Error(), "not a group JID") {
		t.Fatalf("expected error message menyebut \"not a group JID\", got %q", err.Error())
	}
}

// String yang sama sekali bukan bentuk JID (mis. salah ketik/salah paste
// admin di masa depan, sebelum dropdown v0.26.4 menggantikan input manual)
// tetap harus ditolak — TAPI temuan nyata: types.ParseJID() TIDAK error
// untuk string tanpa "@" sama sekali (dikonfirmasi via go test langsung,
// bukan diasumsikan dari dokumentasi) — string itu diparse sebagai JID
// dengan Server=string itu sendiri, lalu ditangkap oleh guard KEDUA
// (Server != g.us) di parseGroupJID(), bukan oleh types.ParseJID() itu
// sendiri. Hasil akhirnya tetap benar (ditolak), cuma lewat jalur guard
// yang berbeda dari dugaan awal — test ini membuktikan itu, bukan
// mengasumsikan pesan error mana yang muncul.
func TestParseGroupJID_MalformedString_IsRejected(t *testing.T) {
	_, err := parseGroupJID("bukan-jid-sama-sekali")

	if err == nil {
		t.Fatalf("expected error untuk string yang bukan JID sama sekali")
	}
	// Diterima lewat SALAH SATU dari 2 guard — yang penting hasilnya
	// ditolak, bukan lolos diam-diam.
	if !strings.Contains(err.Error(), "invalid group JID") && !strings.Contains(err.Error(), "not a group JID") {
		t.Fatalf("expected error dari salah satu guard (parse atau server check), got %q", err.Error())
	}
}

// String kosong — kasus paling sering terjadi kalau request Laravel gagal
// mengisi group_jid (bug di sisi sana) — harus ditolak juga, bukan
// diam-diam resolve ke JID kosong.
func TestParseGroupJID_EmptyString_IsRejected(t *testing.T) {
	_, err := parseGroupJID("")

	if err == nil {
		t.Fatalf("expected error untuk string kosong")
	}
}

// Server lain yang bukan individu maupun grup (mis. broadcast list,
// newsletter) juga harus ditolak — guard-nya "harus g.us", bukan "asal
// bukan s.whatsapp.net".
func TestParseGroupJID_BroadcastServer_IsRejected(t *testing.T) {
	_, err := parseGroupJID("123456789@broadcast")

	if err == nil {
		t.Fatalf("expected error — JID broadcast list bukan JID grup")
	}
	if !strings.Contains(err.Error(), "not a group JID") {
		t.Fatalf("expected error message menyebut \"not a group JID\", got %q", err.Error())
	}
}

// v0.26.4 — IsPermanentGroupSendError(). Dikonfirmasi lewat pembacaan
// langsung source whatsmeow (send.go): client.SendMessage() ke JID grup
// membungkus whatsmeow.ErrNotInGroup lewat "failed to get group members:
// %w" — errors.Is() harus tetap menembus wrapping itu (Go stdlib, %w
// mempertahankan rantai), bukan cuma cocok kalau error itu di posisi
// paling luar.
func TestIsPermanentGroupSendError_WrappedErrNotInGroup_ReturnsTrue(t *testing.T) {
	wrapped := fmt.Errorf("failed to get group members: %w", whatsmeow.ErrNotInGroup)

	if !IsPermanentGroupSendError(wrapped) {
		t.Fatalf("expected true untuk error yang membungkus whatsmeow.ErrNotInGroup")
	}
}

func TestIsPermanentGroupSendError_UnwrappedErrNotInGroup_ReturnsTrue(t *testing.T) {
	if !IsPermanentGroupSendError(whatsmeow.ErrNotInGroup) {
		t.Fatalf("expected true untuk whatsmeow.ErrNotInGroup langsung, tanpa wrapping")
	}
}

// Error TRANSIEN (timeout/koneksi) — HARUS false, supaya jalur retry di
// Laravel tetap jalan normal untuk kelas kegagalan yang genuinely layak
// dicoba ulang.
func TestIsPermanentGroupSendError_TransientError_ReturnsFalse(t *testing.T) {
	transient := errors.New("send timeout after 20s")

	if IsPermanentGroupSendError(transient) {
		t.Fatalf("expected false untuk error transien (timeout), bukan kegagalan permanen")
	}
}

func TestIsPermanentGroupSendError_NilError_ReturnsFalse(t *testing.T) {
	if IsPermanentGroupSendError(nil) {
		t.Fatalf("expected false untuk err=nil")
	}
}
