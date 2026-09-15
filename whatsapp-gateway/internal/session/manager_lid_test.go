package session

import (
	"context"
	"testing"

	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/types"
)

// fakeLIDStore — implementasi minimal store.LIDStore untuk test, TIDAK
// bicara ke SQL apa pun. Hanya GetPNForLID yang genuinely dipakai
// resolveSenderPhone(); method lain ada murni supaya struct ini memenuhi
// interface store.LIDStore.
type fakeLIDStore struct {
	mapping map[string]types.JID // key: LID user string -> PN JID
	err     error                // kalau non-nil, GetPNForLID selalu gagal (simulasi error DB)
	calls   int
}

var _ store.LIDStore = (*fakeLIDStore)(nil)

func (f *fakeLIDStore) GetPNForLID(_ context.Context, lid types.JID) (types.JID, error) {
	f.calls++
	if f.err != nil {
		return types.JID{}, f.err
	}
	if pn, ok := f.mapping[lid.User]; ok {
		return pn, nil
	}
	return types.JID{}, nil
}

func (f *fakeLIDStore) GetLIDForPN(_ context.Context, _ types.JID) (types.JID, error) {
	return types.JID{}, nil
}

func (f *fakeLIDStore) GetManyLIDsForPNs(_ context.Context, _ []types.JID) (map[types.JID]types.JID, error) {
	return nil, nil
}

func (f *fakeLIDStore) PutLIDMapping(_ context.Context, _, _ types.JID) error { return nil }

func (f *fakeLIDStore) PutManyLIDMappings(_ context.Context, _ []store.LIDMapping) error {
	return nil
}

func pnJID(user string) types.JID {
	return types.JID{User: user, Server: types.DefaultUserServer}
}

func lidJID(user string) types.JID {
	return types.JID{User: user, Server: types.HiddenUserServer}
}

// Cabang (b): Sender.Server bukan LID sama sekali — dipakai apa adanya,
// dikonversi ToLocalIndonesian, is_lid=false. LIDStore tidak boleh
// dikonsultasikan sama sekali (nil aman di cabang ini).
func TestResolveSenderPhone_NotLid_UsesSenderDirectly(t *testing.T) {
	sender := pnJID("6281234567890")
	senderAlt := types.JID{} // kosong — tidak relevan di cabang ini

	phone, isLid := resolveSenderPhone(context.Background(), sender, senderAlt, nil)

	if isLid {
		t.Fatalf("expected is_lid=false untuk sender non-LID, got true")
	}
	if phone != "081234567890" {
		t.Fatalf("expected phone format lokal 081234567890, got %q", phone)
	}
}

// Cabang (c): Sender adalah LID TAPI SenderAlt (PN asli, dari attribute
// node XML pesan yang sama) terisi — dipakai SenderAlt, is_lid=false.
// LIDStore TIDAK PERNAH perlu dikonsultasikan di cabang ini (nil aman,
// dan kalaupun diisi harus tidak pernah dipanggil).
func TestResolveSenderPhone_LidWithSenderAlt_UsesSenderAlt(t *testing.T) {
	sender := lidJID("44435932971043")
	senderAlt := pnJID("6281234567890")
	fake := &fakeLIDStore{}

	phone, isLid := resolveSenderPhone(context.Background(), sender, senderAlt, fake)

	if isLid {
		t.Fatalf("expected is_lid=false — SenderAlt berhasil dipakai, bukan kegagalan")
	}
	if phone != "081234567890" {
		t.Fatalf("expected phone dari SenderAlt (format lokal), got %q", phone)
	}
	if fake.calls != 0 {
		t.Fatalf("GetPNForLID tidak boleh dipanggil sama sekali kalau SenderAlt sudah terisi, got %d calls", fake.calls)
	}
}

// Cabang (d): Sender LID, SenderAlt KOSONG, tapi LIDStore SUDAH punya
// mapping tersimpan dari histori — dipakai hasil GetPNForLID, is_lid=false.
func TestResolveSenderPhone_LidWithoutSenderAlt_FallsBackToLIDStore(t *testing.T) {
	sender := lidJID("44435932971043")
	senderAlt := types.JID{} // kosong
	fake := &fakeLIDStore{
		mapping: map[string]types.JID{
			"44435932971043": pnJID("6281234567890"),
		},
	}

	phone, isLid := resolveSenderPhone(context.Background(), sender, senderAlt, fake)

	if isLid {
		t.Fatalf("expected is_lid=false — LIDStore lookup berhasil, bukan kegagalan")
	}
	if phone != "081234567890" {
		t.Fatalf("expected phone dari LIDStore lookup (format lokal), got %q", phone)
	}
	if fake.calls != 1 {
		t.Fatalf("expected GetPNForLID dipanggil tepat 1x, got %d calls", fake.calls)
	}
}

// Cabang (e), skenario 1: Sender LID, SenderAlt kosong, LIDStore TIDAK
// punya mapping (kontak baru pertama kali, belum ada histori) — PN
// genuinely tidak tersedia, simpan raw LID apa adanya (TIDAK dikonversi
// ToLocalIndonesian), is_lid=true.
func TestResolveSenderPhone_LidWithNoMappingAnywhere_FallsBackToRawLid(t *testing.T) {
	sender := lidJID("44435932971043")
	senderAlt := types.JID{}
	fake := &fakeLIDStore{mapping: map[string]types.JID{}} // kosong, tidak ada mapping

	phone, isLid := resolveSenderPhone(context.Background(), sender, senderAlt, fake)

	if !isLid {
		t.Fatalf("expected is_lid=true — PN genuinely tidak tersedia dari sisi manapun")
	}
	if phone != "44435932971043" {
		t.Fatalf("expected raw LID APA ADANYA (tidak dikonversi ToLocalIndonesian), got %q", phone)
	}
}

// Cabang (e), skenario 2: LIDStore mengembalikan error (mis. error DB) —
// diperlakukan SAMA seperti "tidak ada mapping", bukan panic/error
// menjalar — fallback ke raw LID, is_lid=true. Fire-and-forget gateway ini
// tidak boleh gagal keras hanya karena lookup lokal opsional bermasalah.
func TestResolveSenderPhone_LIDStoreError_StillFallsBackGracefully(t *testing.T) {
	sender := lidJID("44435932971043")
	senderAlt := types.JID{}
	fake := &fakeLIDStore{err: context.DeadlineExceeded}

	phone, isLid := resolveSenderPhone(context.Background(), sender, senderAlt, fake)

	if !isLid {
		t.Fatalf("expected is_lid=true saat LIDStore error")
	}
	if phone != "44435932971043" {
		t.Fatalf("expected raw LID apa adanya saat LIDStore error, got %q", phone)
	}
}

// lidStore nil (mis. e.client.Store belum siap) — tidak boleh panic,
// diperlakukan sama seperti "tidak ada mapping".
func TestResolveSenderPhone_NilLIDStore_DoesNotPanic(t *testing.T) {
	sender := lidJID("44435932971043")
	senderAlt := types.JID{}

	phone, isLid := resolveSenderPhone(context.Background(), sender, senderAlt, nil)

	if !isLid {
		t.Fatalf("expected is_lid=true saat lidStore nil")
	}
	if phone != "44435932971043" {
		t.Fatalf("expected raw LID apa adanya saat lidStore nil, got %q", phone)
	}
}

// AddressingMode SENGAJA tidak pernah dipakai sebagai parameter fungsi ini
// sama sekali (lihat docblock resolveSenderPhone) — deteksi LID murni dari
// sender.Server, dites implisit oleh semua kasus di atas (tidak satu pun
// membangun/membaca AddressingMode).
