"""Unit test HMAC verify — bukan integration test (tidak menyentuh OLT
apa pun). Skema mirror App\\Support\\WhatsappHmac (PHP) — lihat
app/hmac_verify.py.
"""
import time

from app.hmac_verify import sign, verify

SECRET = b'test-secret-value-cukup-panjang'


def test_verify_accepts_a_genuinely_valid_signature():
    body = b'{"vendor":"zte_c300","operation":"onu_uncfg_list"}'
    timestamp = int(time.time())
    signature = sign(body, timestamp, SECRET)

    assert verify(body, signature, timestamp, SECRET) is True


def test_verify_rejects_a_tampered_body():
    body = b'{"vendor":"zte_c300"}'
    timestamp = int(time.time())
    signature = sign(body, timestamp, SECRET)

    assert verify(b'{"vendor":"hsgq_e04id"}', signature, timestamp, SECRET) is False


def test_verify_rejects_wrong_secret():
    body = b'{"a":1}'
    timestamp = int(time.time())
    signature = sign(body, timestamp, SECRET)

    assert verify(body, signature, timestamp, b'secret-yang-salah') is False


def test_verify_rejects_expired_timestamp_even_with_correct_signature():
    body = b'{"a":1}'
    old_timestamp = int(time.time()) - 301  # 1 detik di luar toleransi 300s
    signature = sign(body, old_timestamp, SECRET)

    assert verify(body, signature, old_timestamp, SECRET) is False


def test_verify_accepts_timestamp_just_inside_tolerance():
    body = b'{"a":1}'
    timestamp = int(time.time()) - 299
    signature = sign(body, timestamp, SECRET)

    assert verify(body, signature, timestamp, SECRET) is True


def test_verify_rejects_empty_signature():
    body = b'{"a":1}'
    timestamp = int(time.time())

    assert verify(body, '', timestamp, SECRET) is False


def test_sign_matches_the_php_go_scheme_format_directly():
    # HMAC-SHA256(secret, "{timestamp}.{body}") — verifikasi manual
    # terhadap implementasi hashlib langsung, memastikan tidak ada
    # penyimpangan format dari App\Support\WhatsappHmac/hmacsig.go.
    import hashlib
    import hmac as hmac_module

    body = b'raw-body-contoh'
    timestamp = 1790000000
    expected = hmac_module.new(SECRET, f'{timestamp}.'.encode() + body, hashlib.sha256).hexdigest()

    assert sign(body, timestamp, SECRET) == expected
