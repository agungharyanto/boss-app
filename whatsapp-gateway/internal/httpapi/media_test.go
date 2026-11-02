package httpapi

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"whatsapp-gateway/internal/hmacsig"
)

const testSecret = "test-hmac-secret"

func signedMediaRequest(t *testing.T, path string, payload map[string]any) *http.Request {
	t.Helper()
	body, _ := json.Marshal(payload)
	ts := time.Now().Unix()
	sig := hmacsig.Sign(testSecret, string(body), ts)

	req := httptest.NewRequest(http.MethodPost, path, bytes.NewReader(body))
	req.Header.Set("X-Whatsapp-Timestamp", fmt.Sprintf("%d", ts))
	req.Header.Set("X-Whatsapp-Signature", sig)

	return req
}

// --- validateMedia (fungsi murni) ---

func TestValidateMedia_Valid_ReturnsSanitizedFilename(t *testing.T) {
	data, name, errMsg := validateMedia(sendMediaRequest{
		Mime:     "image/jpeg",
		Filename: "foto.jpg",
		Base64:   base64.StdEncoding.EncodeToString([]byte("hello")),
		Caption:  "caption ok",
	})
	if errMsg != "" {
		t.Fatalf("unexpected errMsg: %s", errMsg)
	}
	if name != "foto.jpg" || string(data) != "hello" {
		t.Fatalf("name=%q data=%q", name, data)
	}
}

func TestValidateMedia_MimeNotAllowed(t *testing.T) {
	_, _, errMsg := validateMedia(sendMediaRequest{Mime: "image/svg+xml", Base64: base64.StdEncoding.EncodeToString([]byte("x"))})
	if errMsg == "" {
		t.Fatal("svg must be rejected")
	}
}

func TestValidateMedia_InvalidBase64(t *testing.T) {
	_, _, errMsg := validateMedia(sendMediaRequest{Mime: "image/png", Base64: "!!!not-base64!!!"})
	if errMsg == "" {
		t.Fatal("invalid base64 must be rejected")
	}
}

func TestValidateMedia_OverSizeRejected(t *testing.T) {
	big := base64.StdEncoding.EncodeToString(bytes.Repeat([]byte("a"), mediaMaxDecodedBytes+1))
	_, _, errMsg := validateMedia(sendMediaRequest{Mime: "application/pdf", Base64: big, Filename: "x.pdf"})
	if errMsg == "" {
		t.Fatal("over-size must be rejected")
	}
}

func TestValidateMedia_CaptionTooLong(t *testing.T) {
	_, _, errMsg := validateMedia(sendMediaRequest{
		Mime:    "image/png",
		Base64:  base64.StdEncoding.EncodeToString([]byte("x")),
		Caption: strings.Repeat("a", mediaMaxCaptionChars+1),
	})
	if errMsg == "" {
		t.Fatal("over-long caption must be rejected")
	}
}

func TestValidateMedia_FilenameWithPathRejected(t *testing.T) {
	for _, bad := range []string{"../etc/passwd", "a/b.jpg", `c\d.png`} {
		_, _, errMsg := validateMedia(sendMediaRequest{
			Mime:     "image/jpeg",
			Base64:   base64.StdEncoding.EncodeToString([]byte("x")),
			Filename: bad,
		})
		if errMsg == "" {
			t.Fatalf("filename %q must be rejected", bad)
		}
	}
}

// --- handler (httptest) ---

func TestHandleSendMedia_BadHMAC_401(t *testing.T) {
	srv := NewServer(nil, testSecret)
	req := httptest.NewRequest(http.MethodPost, "/sessions/direct/send-media", strings.NewReader(`{"phone_number":"628","mime":"image/png","base64":"eA=="}`))
	// tanpa header signature
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401, got %d", rec.Code)
	}
}

func TestHandleSendMedia_MimeRejected_422(t *testing.T) {
	srv := NewServer(nil, testSecret) // manager nil aman: validasi gagal sebelum dipanggil
	req := signedMediaRequest(t, "/sessions/direct/send-media", map[string]any{
		"phone_number": "628123",
		"mime":         "image/svg+xml",
		"filename":     "x.svg",
		"base64":       base64.StdEncoding.EncodeToString([]byte("x")),
	})
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d (body=%s)", rec.Code, rec.Body.String())
	}
}

func TestHandleSendGroupMedia_MissingGroupJID_422(t *testing.T) {
	srv := NewServer(nil, testSecret)
	req := signedMediaRequest(t, "/sessions/direct/send-group-media", map[string]any{
		"mime":   "image/png",
		"base64": base64.StdEncoding.EncodeToString([]byte("x")),
	})
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", rec.Code)
	}
}

func TestHandleSendMedia_BodyTooLarge_413(t *testing.T) {
	srv := NewServer(nil, testSecret)
	// Body > mediaMaxBodyBytes → MaxBytesReader memicu 413 sebelum HMAC.
	huge := bytes.Repeat([]byte("a"), mediaMaxBodyBytes+1)
	req := httptest.NewRequest(http.MethodPost, "/sessions/direct/send-media", bytes.NewReader(huge))
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)
	if rec.Code != http.StatusRequestEntityTooLarge {
		t.Fatalf("expected 413, got %d", rec.Code)
	}
}
