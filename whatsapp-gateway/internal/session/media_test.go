package session

import (
	"bytes"
	"context"
	"errors"
	"image"
	"image/color"
	"image/png"
	"testing"
	"time"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
)

// fakeMediaClient — stub mediaClient untuk menguji sendMediaToJID tanpa
// koneksi WhatsApp nyata.
type fakeMediaClient struct {
	uploadErr   error
	sendErr     error
	uploadCalls int
	sendCalls   int
	lastMsg     *waE2E.Message
	lastMedia   whatsmeow.MediaType
}

func (f *fakeMediaClient) Upload(_ context.Context, _ []byte, appInfo whatsmeow.MediaType) (whatsmeow.UploadResponse, error) {
	f.uploadCalls++
	f.lastMedia = appInfo
	if f.uploadErr != nil {
		return whatsmeow.UploadResponse{}, f.uploadErr
	}

	return whatsmeow.UploadResponse{
		URL:           "https://mmg.whatsapp.net/fake",
		DirectPath:    "/fake/path",
		MediaKey:      []byte("mediakey-16bytes"),
		FileEncSHA256: []byte("encsha"),
		FileSHA256:    []byte("sha"),
		FileLength:    123,
	}, nil
}

func (f *fakeMediaClient) SendMessage(_ context.Context, _ types.JID, message *waE2E.Message, _ ...whatsmeow.SendRequestExtra) (whatsmeow.SendResponse, error) {
	f.sendCalls++
	f.lastMsg = message
	if f.sendErr != nil {
		return whatsmeow.SendResponse{}, f.sendErr
	}

	return whatsmeow.SendResponse{}, nil
}

func pngBytes(w, h int) []byte {
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			img.Set(x, y, color.RGBA{R: uint8(x), G: uint8(y), B: 100, A: 255})
		}
	}
	var buf bytes.Buffer
	_ = png.Encode(&buf, img)

	return buf.Bytes()
}

func testJID() types.JID {
	return types.JID{User: "6281234567890", Server: types.DefaultUserServer}
}

func TestBuildMediaMessage_Image_SetsImageMessageWithCaptionAndUploadFields(t *testing.T) {
	up := whatsmeow.UploadResponse{URL: "u", DirectPath: "d", MediaKey: []byte("k")}
	msg := buildMediaMessage(true, "halo caption", "image/jpeg", "foto.jpg", up, []byte("1234567890"))

	if msg.ImageMessage == nil {
		t.Fatal("expected ImageMessage")
	}
	if msg.DocumentMessage != nil {
		t.Fatal("document message must be nil for image")
	}
	if msg.ImageMessage.GetCaption() != "halo caption" {
		t.Fatalf("caption = %q", msg.ImageMessage.GetCaption())
	}
	if msg.ImageMessage.GetURL() != "u" || msg.ImageMessage.GetMimetype() != "image/jpeg" {
		t.Fatal("upload fields/mimetype not mapped")
	}
	if msg.ImageMessage.GetFileLength() != 10 {
		t.Fatalf("file length = %d", msg.ImageMessage.GetFileLength())
	}
}

func TestBuildMediaMessage_Document_SetsDocumentMessageWithFilename(t *testing.T) {
	up := whatsmeow.UploadResponse{URL: "u", DirectPath: "d"}
	msg := buildMediaMessage(false, "", "application/pdf", "invoice.pdf", up, []byte("%PDF-1.4"))

	if msg.DocumentMessage == nil {
		t.Fatal("expected DocumentMessage")
	}
	if msg.ImageMessage != nil {
		t.Fatal("image message must be nil for document")
	}
	if msg.DocumentMessage.GetFileName() != "invoice.pdf" || msg.DocumentMessage.GetTitle() != "invoice.pdf" {
		t.Fatal("filename/title not set")
	}
	if msg.DocumentMessage.GetCaption() != "" {
		t.Fatal("empty caption must not be set")
	}
}

func TestGenerateJPEGThumbnail_ValidImage_ReturnsBytes(t *testing.T) {
	thumb := generateJPEGThumbnail(pngBytes(200, 120))
	if len(thumb) == 0 {
		t.Fatal("expected a non-empty thumbnail")
	}
}

func TestGenerateJPEGThumbnail_Garbage_ReturnsNil(t *testing.T) {
	if generateJPEGThumbnail([]byte("this is not an image")) != nil {
		t.Fatal("expected nil thumbnail for non-image data")
	}
}

func TestSendMediaToJID_Success_UploadsThenSends(t *testing.T) {
	fc := &fakeMediaClient{}
	err := sendMediaToJID(context.Background(), fc, testJID(), 5*time.Second, true, "cap", "image/jpeg", "a.jpg", pngBytes(50, 50))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if fc.uploadCalls != 1 || fc.sendCalls != 1 {
		t.Fatalf("upload=%d send=%d", fc.uploadCalls, fc.sendCalls)
	}
	if fc.lastMedia != whatsmeow.MediaImage {
		t.Fatal("expected MediaImage for image mime")
	}
}

func TestSendMediaToJID_DocumentUsesMediaDocument(t *testing.T) {
	fc := &fakeMediaClient{}
	_ = sendMediaToJID(context.Background(), fc, testJID(), 5*time.Second, false, "", "application/pdf", "x.pdf", []byte("%PDF"))
	if fc.lastMedia != whatsmeow.MediaDocument {
		t.Fatal("expected MediaDocument for pdf")
	}
}

func TestSendMediaToJID_UploadError_IsReturnedAndSendNotCalled(t *testing.T) {
	fc := &fakeMediaClient{uploadErr: errors.New("boom upload")}
	err := sendMediaToJID(context.Background(), fc, testJID(), 5*time.Second, true, "", "image/png", "a.png", pngBytes(10, 10))
	if err == nil {
		t.Fatal("expected error")
	}
	if fc.sendCalls != 0 {
		t.Fatal("send must not be called when upload fails")
	}
}

func TestSendMediaToJID_SendError_IsReturned(t *testing.T) {
	fc := &fakeMediaClient{sendErr: errors.New("boom send")}
	err := sendMediaToJID(context.Background(), fc, testJID(), 5*time.Second, true, "", "image/png", "a.png", pngBytes(10, 10))
	if err == nil {
		t.Fatal("expected error")
	}
}

func TestSendMediaToJID_ThumbnailFailureDoesNotBlockSend(t *testing.T) {
	// Data bukan gambar valid → generateJPEGThumbnail nil, TAPI pengiriman
	// tetap jalan (thumbnail best-effort). Mime image dipakai hanya untuk
	// memilih MediaImage; builder tetap menghasilkan ImageMessage.
	fc := &fakeMediaClient{}
	err := sendMediaToJID(context.Background(), fc, testJID(), 5*time.Second, true, "", "image/jpeg", "a.jpg", []byte("not-an-image"))
	if err != nil {
		t.Fatalf("thumbnail failure must not fail send: %v", err)
	}
	if fc.sendCalls != 1 {
		t.Fatal("send should still be called")
	}
	if msg := fc.lastMsg; msg == nil || msg.ImageMessage == nil || len(msg.ImageMessage.GetJPEGThumbnail()) != 0 {
		t.Fatal("expected ImageMessage with no thumbnail")
	}
}
