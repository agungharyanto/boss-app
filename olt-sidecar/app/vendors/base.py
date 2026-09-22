"""Helper pexpect bersama untuk sesi CLI OLT (v0.23.3 sidecar).

Port LANGSUNG dari logika expect/Tcl yang sudah terbukti bekerja di
v0.23.1 (HSGQ SSH, olt_session.exp) dan v0.23.2 (ZTE Telnet,
olt_telnet_session.exp) — pattern regex dan urutan langkah SENGAJA
direplikasi apa adanya (bukan ditulis ulang dari nol), termasuk fix bug
yang sudah ditemukan nyata di riset manual:
  - deteksi password-reprompt yang indentation-aware ("Insiden Sesi 6"
    ZTE — bare "password:" false-trigger pada field label device sendiri
    yang berindentasi 2 spasi, mis. output `detail-info`).
  - jangan pernah menjawab prompt konfirmasi/password yang TIDAK dikenal
    — selalu berhenti (OltSessionError), tidak pernah menebak jawaban.

Lihat docs/omci/zte-c300-cli-reference.md "Insiden Sesi 1-6" dan
docs/omci/hsgq-e04id-cli-reference.md / hsgq-g02id-cli-reference.md untuk
riwayat lengkap dari mana pola-pola ini berasal.
"""
from __future__ import annotations

import re
import time

import pexpect

# Pager ("--More--") — dijawab dengan satu spasi, berulang selama pager
# masih muncul (sama seperti kedua skrip Tcl aslinya).
MORE_RE = re.compile(r'--[Mm]ore-- ?')

# Prompt konfirmasi interaktif APA PUN (y/n, "are you sure", dll) — TIDAK
# PERNAH dijawab, selalu berarti STOP dan laporkan sebagai gagal.
CONFIRM_RE = re.compile(
    r'\(y/n\)|\[y/n\]|\[Y/N\]|are you sure|confirm\?|overwrite',
    re.IGNORECASE,
)

# Prompt shell OLT (VIEW `>` atau PRIVILEGED `#`), dengan spasi opsional
# di akhir — sama seperti kedua skrip Tcl aslinya.
PROMPT_RE = re.compile(r'[>#]\s*$')

# Deteksi password-reprompt yang INDENTATION-AWARE (v0.23.2 "Insiden Sesi
# 6", fix final, diverifikasi lewat unit test Tcl terpisah sebelum
# dipercaya lagi) — match TIDAK BOLEH didahului oleh spasi literal, karena
# device (khususnya ZTE) konsisten mengindentasi label field-nya sendiri
# 2 spasi ("  Password:            ") sedangkan prompt interaktif asli
# TIDAK PERNAH berindentasi. Dipakai untuk SEMUA vendor di sini (superset
# proteksi — tidak pernah salah untuk HSGQ yang belum pernah menemukan
# bug ini, dan menutup kelas bug yang sudah 2x terjadi nyata untuk ZTE).
PASSWORD_REPROMPT_RE = re.compile(r'(?:^|[^ ])password:\s*$', re.IGNORECASE)

# Deteksi password prompt SEDERHANA (tanpa syarat indentasi) — hanya
# dipakai untuk expect PERTAMA setelah spawn (login), sebelum ada output
# device lain yang berpotensi mengandung field label serupa.
INITIAL_PASSWORD_RE = re.compile(r'password:', re.IGNORECASE)


class OltSessionError(Exception):
    """Sesi CLI gagal dengan cara yang HARUS dilaporkan ke pemanggil, tidak
    pernah didiamkan. `stage` menandai di langkah mana (login/enable/
    command/logout) untuk keperluan log operasional (§9 desain — TANPA
    payload sensitif)."""

    def __init__(self, message: str, stage: str):
        super().__init__(message)
        self.stage = stage


def run_command_and_capture(child: 'pexpect.spawn', command: str, overall_deadline: float) -> str:
    """Kirim SATU command (diakhiri newline) dan tangkap outputnya sampai
    prompt kembali — menangani pager, dan menolak menjawab konfirmasi/
    password-reprompt yang tidak diharapkan (mirror PERSIS main CMD
    handling loop di kedua skrip Tcl asli)."""
    child.sendline(command)
    captured = ''
    while True:
        remaining = overall_deadline - time.monotonic()
        if remaining < 1:
            remaining = 1
        index = child.expect(
            [MORE_RE, CONFIRM_RE, PASSWORD_REPROMPT_RE, PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF],
            timeout=remaining,
        )
        captured += child.before or ''
        if index == 0:  # pager — jawab satu spasi, lanjut menangkap
            child.send(' ')
            continue
        if index == 1:
            raise OltSessionError(f"Prompt konfirmasi tak terduga setelah '{command}'", 'command')
        if index == 2:
            raise OltSessionError(f"Prompt password tak terduga (re-prompt) setelah '{command}'", 'command')
        if index == 3:  # prompt kembali — selesai
            return captured
        if index == 4:
            raise OltSessionError(f"Timeout menunggu prompt setelah '{command}'", 'command')
        raise OltSessionError(f"Koneksi tertutup tak terduga saat menjalankan '{command}'", 'command')


# MAC address (XX:XX:XX:XX:XX:XX atau XX-XX-XX-...) — dicocokkan
# TERPISAH dari token alfanumerik di bawah, karena pemisah ':'/'-' berarti
# regex token panjang biasa TIDAK PERNAH match keseluruhan alamat MAC
# (tiap segmen cuma 2 karakter) — celah nyata yang ditemukan & ditutup
# saat verifikasi manual pertama terhadap OLT sungguhan (HSGQ E04ID,
# 2026-09-22): baris "Base Mac Address : <mac address perangkat>" lolos
# tanpa masking sama sekali sampai pattern ini ditambahkan.
_MAC_ADDRESS_RE = re.compile(r'(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}')

# Token alfanumerik CAMPURAN (mengandung minimal satu huruf DAN satu
# angka) panjang >=8 — kandidat serial number/ID device. Sengaja TIDAK
# match kata murni alfabet ("Copyright"/"Software"/"Hardware") — versi
# awal (`[A-Za-z0-9]{8,}` tanpa syarat campuran) over-masking kata biasa
# di output `show version`, ditemukan pada verifikasi manual yang sama.
#
# Alternatif kedua: DIGIT MURNI panjang >=8 — kandidat serial number
# bergaya numerik (mis. HSGQ G02ID menampilkan SerialNumber sebagai
# deretan digit tanpa huruf sama sekali) — celah nyata KEDUA yang
# ditemukan pada verifikasi manual yang sama (G02ID, 2026-09-22): pattern
# campuran di atas SAJA tidak match serial number bergaya ini.
_SENSITIVE_TOKEN_RE = re.compile(
    r'(?:(?=[A-Za-z0-9]*[A-Za-z])(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{8,})|(?:\d{8,})',
)


def _mask_token(token: str) -> str:
    if len(token) <= 8:
        return token[:2] + '*' * (len(token) - 4) + token[-2:]
    return token[:4] + '*' * (len(token) - 8) + token[-4:]


def mask_sensitive_tokens(text: str) -> str:
    """Masking defense-in-depth (desain §4) — kandidat serial number/MAC
    address di-mask sebagian SEBELUM respons meninggalkan sidecar, tidak
    pernah diasumsikan dibersihkan di sisi Laravel. Kata biasa (murni
    alfabet, apa pun panjangnya) TIDAK di-mask — hanya token yang
    genuinely terlihat seperti identifier (MAC, atau campuran huruf+angka
    panjang)."""
    text = _MAC_ADDRESS_RE.sub(lambda m: _mask_token(m.group(0).replace(':', '').replace('-', '')), text)
    text = _SENSITIVE_TOKEN_RE.sub(lambda m: _mask_token(m.group(0)), text)
    return text


def lines_from_capture(raw: str, command: str) -> list[str]:
    """Ubah blok hasil capture mentah jadi daftar baris bersih: buang echo
    command device sendiri (kalau ada), buang penanda pager, buang baris
    kosong."""
    lines = raw.replace('\r', '').split('\n')
    cleaned: list[str] = []
    for line in lines:
        stripped = line.strip()
        if not stripped:
            continue
        if stripped == command.strip():
            continue
        if MORE_RE.search(stripped):
            continue
        cleaned.append(stripped)
    return cleaned


def parse_field_value_block(raw: str, command: str) -> dict[str, str]:
    """Ubah blok output 'Label            : Value' (satu field per baris —
    pola umum CLI OLT untuk perintah detail-info/setting, dikonfirmasi
    lewat output nyata `show gpon onu detail-info` v0.23.2) jadi dict.

    Dipanggil di ATAS `lines_from_capture()` (bukan split baris mentah
    langsung) supaya echo command device sendiri sudah dibuang duluan —
    penting karena identifier ONU ZTE sendiri mengandung ':' (mis.
    'gpon-onu_1/3/12:2'), yang kalau tidak dibuang dulu akan salah
    ter-parse seolah jadi sebuah field. Baris tanpa ':' (header/separator)
    diabaikan; baris dengan label kosong sebelum ':' juga diabaikan
    (defense-in-depth, bukan pola yang teramati nyata)."""
    fields: dict[str, str] = {}
    for line in lines_from_capture(raw, command):
        if ':' not in line:
            continue
        label, _, value = line.partition(':')
        label = label.strip()
        value = value.strip()
        if not label:
            continue
        fields[label] = value
    return fields
