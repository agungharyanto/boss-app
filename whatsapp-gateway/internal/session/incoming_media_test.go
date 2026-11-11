package session

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"go.mau.fi/whatsmeow/proto/waE2E"
	"google.golang.org/protobuf/proto"
)

func TestBaseMime(t *testing.T) {
	cases := map[string]string{
		"image/jpeg":             "image/jpeg",
		"audio/ogg; codecs=opus": "audio/ogg",
		"  Application/PDF  ":    "application/pdf",
		"video/mp4;foo=bar":      "video/mp4",
		"image/webp":             "image/webp",
	}
	for in, want := range cases {
		if got := baseMime(in); got != want {
			t.Errorf("baseMime(%q)=%q want %q", in, got, want)
		}
	}
}

func TestSanitizePathPart(t *testing.T) {
	cases := map[string]string{
		"direct":        "direct",
		"123":           "123",
		"../etc/passwd": ".._etc_passwd",
		"a/b\\c":        "a_b_c",
		"":              "_",
		"..":            "_",
	}
	for in, want := range cases {
		if got := sanitizePathPart(in); got != want {
			t.Errorf("sanitizePathPart(%q)=%q want %q", in, got, want)
		}
	}
}

func TestDetectIncomingMedia(t *testing.T) {
	img := &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
		Mimetype:   proto.String("image/jpeg; codecs=x"),
		Caption:    proto.String("halo"),
		FileLength: proto.Uint64(123),
	}}
	m, ok := detectIncomingMedia(img)
	if !ok || m.mediaType != "image" || m.mime != "image/jpeg" || m.caption != "halo" || m.fileLength != 123 {
		t.Fatalf("image: %+v ok=%v", m, ok)
	}

	doc := &waE2E.Message{DocumentMessage: &waE2E.DocumentMessage{
		Mimetype:   proto.String("application/pdf"),
		FileName:   proto.String("tagihan.pdf"),
		FileLength: proto.Uint64(456),
	}}
	m, ok = detectIncomingMedia(doc)
	if !ok || m.mediaType != "document" || m.mime != "application/pdf" || m.fileName != "tagihan.pdf" {
		t.Fatalf("document: %+v ok=%v", m, ok)
	}

	aud := &waE2E.Message{AudioMessage: &waE2E.AudioMessage{Mimetype: proto.String("audio/ogg; codecs=opus")}}
	m, ok = detectIncomingMedia(aud)
	if !ok || m.mediaType != "audio" || m.mime != "audio/ogg" {
		t.Fatalf("audio: %+v ok=%v", m, ok)
	}

	vid := &waE2E.Message{VideoMessage: &waE2E.VideoMessage{Mimetype: proto.String("video/mp4"), Caption: proto.String("v")}}
	m, ok = detectIncomingMedia(vid)
	if !ok || m.mediaType != "video" || m.caption != "v" {
		t.Fatalf("video: %+v ok=%v", m, ok)
	}

	stk := &waE2E.Message{StickerMessage: &waE2E.StickerMessage{Mimetype: proto.String("image/webp")}}
	m, ok = detectIncomingMedia(stk)
	if !ok || m.mediaType != "sticker" || m.mime != "image/webp" {
		t.Fatalf("sticker: %+v ok=%v", m, ok)
	}

	// Teks murni → bukan media.
	txt := &waE2E.Message{Conversation: proto.String("hai")}
	if _, ok := detectIncomingMedia(txt); ok {
		t.Fatalf("teks seharusnya bukan media")
	}
}

func TestAllowedMimesAndCaps(t *testing.T) {
	// Dokumen HANYA pdf (SVG/HTML/exe otomatis ditolak karena tak ada di map).
	if _, ok := incomingAllowedMimes["document"]["image/svg+xml"]; ok {
		t.Fatal("SVG tidak boleh di-whitelist dokumen")
	}
	if ext := incomingAllowedMimes["document"]["application/pdf"]; ext != "pdf" {
		t.Fatalf("pdf ext=%q", ext)
	}
	if incomingCaps["sticker"] != 1*1024*1024 || incomingCaps["video"] != 32*1024*1024 {
		t.Fatal("cap tidak sesuai keputusan")
	}
}

func TestStoreIncomingMediaWritesFileAndSidecarReadable(t *testing.T) {
	dir := t.TempDir()
	old := incomingMediaDir
	incomingMediaDir = dir
	defer func() { incomingMediaDir = old }()

	data := []byte("%PDF-1.4 dummy")
	meta := incomingMediaSidecar{
		MessageID:  "ABC123",
		SessionKey: "direct",
		MediaType:  "document",
		Mime:       "application/pdf",
		FileName:   "x.pdf",
		FileSize:   int64(len(data)),
		Sha256:     sha256Hex(data),
	}
	rel, err := storeIncomingMedia("direct", "ABC123", "pdf", data, meta)
	if err != nil {
		t.Fatal(err)
	}
	if rel != filepath.Join("direct", "ABC123.pdf") {
		t.Fatalf("rel=%q", rel)
	}

	full := filepath.Join(dir, rel)
	info, err := os.Stat(full)
	if err != nil {
		t.Fatal(err)
	}
	// Mode file harus world-readable (0644) agar www-data bisa baca.
	if info.Mode().Perm() != 0o644 {
		t.Fatalf("mode file=%v, harus 0644", info.Mode().Perm())
	}
	got, _ := os.ReadFile(full)
	if string(got) != string(data) {
		t.Fatal("isi file tidak cocok")
	}

	// Sidecar JSON ada + ter-parse + MediaPath terisi.
	sc, err := os.ReadFile(filepath.Join(dir, "direct", "ABC123.json"))
	if err != nil {
		t.Fatal(err)
	}
	var parsed incomingMediaSidecar
	if err := json.Unmarshal(sc, &parsed); err != nil {
		t.Fatal(err)
	}
	if parsed.MessageID != "ABC123" || parsed.MediaPath != rel || parsed.Mime != "application/pdf" {
		t.Fatalf("sidecar: %+v", parsed)
	}

	// Dir juga harus world-readable+executable (0755) agar bisa ditembus.
	dinfo, _ := os.Stat(filepath.Join(dir, "direct"))
	if dinfo.Mode().Perm() != 0o755 {
		t.Fatalf("mode dir=%v, harus 0755", dinfo.Mode().Perm())
	}
}
