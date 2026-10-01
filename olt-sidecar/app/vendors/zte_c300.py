"""ZTE C300 — Telnet. Login mendarat LANGSUNG di privileged `#` — JANGAN
PERNAH kirim `enable` (akar penyebab "Insiden Sesi 1" v0.23.2 — device
memperlakukan `exit`/`logout` sebagai 3x percobaan password salah kalau
dikirim ke prompt password `enable` yang tidak diharapkan). Logout:
KIRIM `exit` SATU KALI SAJA, dengan teks EKSAK
"confirm to logout without saving? [yes/no]" sebagai SATU-SATUNYA
konfirmasi yang boleh dijawab "yes" — ini mekanika terminal ZTE yang
NORMAL (muncul di setiap logout, bahkan sesi baca-saja), BUKAN tanda ada
perubahan belum tersimpan. Port langsung dari `olt_telnet_session.exp`
(v0.23.2), termasuk fix "Insiden Sesi 6" (deteksi password-reprompt
indentation-aware — lihat base.py).

v0.23.5 — WRITE (activate_onu/save_config) ditambahkan di bagian bawah
file ini, terpisah total dari OPERATIONS baca di atas. Desain terkunci
(keputusan Agung 2026-09-22): pemanggil (Laravel) TIDAK PERNAH mengirim
command CLI mentah — hanya `params` dict terstruktur (onu_id, sn,
onu_type, dst); sidecar sendiri yang merakit + memvalidasi urutan CLI
dari template internal (lihat _build_activate_onu_commands()). `apply`
(rangkaian command sampai `end`, TANPA `wr`) dan `save` (HANYA `wr`)
adalah DUA aksi terpisah di endpoint terpisah — lihat
docs/omci/zte-c300-cli-reference.md untuk asal template command persis
(Sesi 7) dan catatan jujur bagian mana yang benar-benar TERUJI vs
sekadar "dari referensi publik, belum pernah dieksekusi".
"""
from __future__ import annotations

import re
import time

import pexpect

from .base import (
    OltSessionError,
    PROMPT_RE,
    lines_from_capture,
    mask_sensitive_tokens,
    parse_field_value_block,
    run_command_and_capture,
)

# Value = command TEMPLATE — operasi yang butuh argumen memakai '{onu}'
# sebagai placeholder, diisi dari args['onu'] saat execute() (lihat
# REQUIRES_ONU_ARG di bawah). onu_uncfg_list tidak butuh argumen apa pun.
OPERATIONS = {
    'onu_uncfg_list': 'show gpon onu uncfg',
    # v0.23.4 — registry ONU. `show gpon onu detail-info <onu>` TERUJI
    # eksekusi nyata (Sesi 6-7 v0.23.2, thd ONU_UJI_1/2) — lihat
    # docs/omci/onu-registry-design.md §7.
    'onu_detail_info': 'show gpon onu detail-info {onu}',
    # v0.23.5 — prasyarat Langkah 0 write-activation. Meski placeholder-nya
    # bernama sama ('onu'), nilai args['onu'] di sini adalah interface PON
    # (mis. "gpon-olt_1/3/12"), BUKAN interface ONU individual seperti
    # onu_detail_info di atas ("gpon-onu_1/3/12:2") — kedua command ini
    # menerima level argumen yang berbeda, sudah TERUJI eksekusi nyata
    # Sesi 5 v0.23.2 (docs/omci/zte-c300-cli-reference.md). Output tabular
    # multi-baris (bukan blok field:value), sehingga jatuh ke cabang
    # `lines_from_capture()` default di execute() di bawah, sama seperti
    # onu_uncfg_list — TIDAK melalui parse_field_value_block().
    'onu_state': 'show gpon onu state {onu}',
    'onu_baseinfo': 'show gpon onu baseinfo {onu}',
    # v0.23.5 — verifikasi hasil fix_onu_vlan/activate_onu (baca VLAN per
    # service-port sungguhan, tidak sekadar status online). args['onu'] di
    # sini adalah interface ONU level (mis. "gpon-onu_1/3/12:4"), sama
    # seperti onu_detail_info — TERUJI eksekusi nyata Sesi 7 v0.23.2
    # (docs/omci/zte-c300-cli-reference.md, "SINTAKS LENGKAP KONFIGURASI
    # PER-ONU"). Output berbentuk blok config (interface/name/tcont/
    # gemport/service-port), BUKAN "Label: Value" konsisten (baris
    # "service-port 1  vport 1 user-vlan X vlan X" tidak punya ':'), jadi
    # jatuh ke cabang lines_from_capture() default, sama seperti
    # onu_uncfg_list/onu_state/onu_baseinfo.
    'onu_running_config': 'show running-config interface {onu}',
    # v0.23.5 — BEDA dari onu_running_config: perintah ini menampilkan
    # blok `pon-onu-mng <onu>` (flow/vlan-filter/switchport-bind/
    # security-mgmt), bukan blok `interface gpon-onu_...` (name/tcont/
    # gemport/service-port). TERUJI eksekusi nyata Sesi 7 v0.23.2, lihat
    # docs/omci/zte-c300-cli-reference.md "sintaks Agung TERKONFIRMASI
    # ADA".
    'onu_mng_running_config': 'show onu running config {onu}',
}

REQUIRES_ONU_ARG = {
    'onu_detail_info', 'onu_state', 'onu_baseinfo',
    'onu_running_config', 'onu_mng_running_config',
}

USERNAME_PROMPT_RE = r'(?i)(username|login)\s*:\s*'
INITIAL_PASSWORD_RE = r'(?i)password:'
LOGIN_FAILURE_RE = r'(?i)incorrect|invalid|denied|failed|refused'
LOGOUT_EXACT_CONFIRM_RE = r'(?i)confirm to logout without saving\? \[yes/no\]'
LOGOUT_GENERIC_CONFIRM_RE = r'\[yes/no\]|\(y/n\)|\[y/n\]|\(yes/no\)'


def execute(connection: dict, operation: str, args: dict, mask_sensitive: bool = True):
    if operation == 'get_uplink_ports':
        return execute_get_uplink_ports(connection, args or {}), None

    template = OPERATIONS.get(operation)
    if template is None:
        raise OltSessionError(f"Operasi '{operation}' belum didukung untuk zte_c300", 'operation')

    if operation in REQUIRES_ONU_ARG:
        onu = (args or {}).get('onu')
        if not onu:
            raise OltSessionError(f"Operasi '{operation}' butuh argumen 'onu'", 'operation')
        command = template.format(onu=onu)
    else:
        command = template

    raw = _run_zte_telnet_command(connection, command)

    if operation == 'onu_detail_info':
        fields = parse_field_value_block(raw, command)
        if mask_sensitive:
            fields = {key: mask_sensitive_tokens(value) for key, value in fields.items()}
        if not fields:
            excerpt = raw
            if mask_sensitive:
                excerpt = mask_sensitive_tokens(excerpt)
            return None, excerpt[:2000]
        return fields, None

    lines = lines_from_capture(raw, command)
    if mask_sensitive:
        lines = [mask_sensitive_tokens(line) for line in lines]

    if not lines:
        excerpt = raw
        if mask_sensitive:
            excerpt = mask_sensitive_tokens(excerpt)
        return None, excerpt[:2000]

    return {'lines': lines}, None


def _zte_login(child: 'pexpect.spawn', username: str, password: str) -> None:
    """Extract PERSIS dari `_run_zte_telnet_command()` (tidak ada
    perubahan perilaku, murni method-extraction v0.23.5) supaya bisa
    dipakai ulang oleh jalur WRITE (`_run_apply_sequence()`/
    `execute_save()`) TANPA menduplikasi logic login — satu-satunya
    tempat expect/sendline login ZTE didefinisikan."""
    try:
        index = child.expect(
            [USERNAME_PROMPT_RE, INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF],
            timeout=15,
        )
        if index == 0:
            child.sendline(username)
            index2 = child.expect(
                [INITIAL_PASSWORD_RE, PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF],
                timeout=15,
            )
            if index2 == 0:
                child.sendline(password)
            elif index2 in (2, 3):
                raise OltSessionError('Tidak ada prompt password setelah kirim username', 'login')
            # index2 == 1 -> langsung masuk prompt CLI tanpa password (jarang, tapi ditangani).
        elif index == 1:
            # Beberapa device langsung ke prompt password (banner
            # sudah membawa username) — kirim CR kosong dulu lalu
            # password, persis skrip Tcl asli.
            child.sendline('')
            child.sendline(password)
        else:
            raise OltSessionError(
                'Tidak ada prompt username/login dalam batas waktu (kemungkinan masalah konektivitas/banner)',
                'login',
            )
    finally:
        password = None  # noqa: F841 — defense-in-depth

    index = child.expect(
        [PROMPT_RE, LOGIN_FAILURE_RE, INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF],
        timeout=20,
    )
    if index == 1:
        raise OltSessionError('Device menolak kredensial (login incorrect/denied)', 'login')
    if index == 2:
        raise OltSessionError('Prompt password KEDUA muncul — menolak menjawabnya', 'login')
    if index in (3, 4):
        raise OltSessionError('Tidak ada prompt CLI awal setelah login', 'login')
    # JANGAN PERNAH kirim `enable` — ZTE sudah privileged (`#`) sejak login.


def _run_zte_telnet_command(connection: dict, command: str, overall_timeout: float = 60.0) -> str:
    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + overall_timeout

    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        output = run_command_and_capture(child, command, deadline)
        return output
    finally:
        _logout_zte(child)


# Slot uplink 2-segmen (rack/slot) dari `show interface port-status ?`.
_UPLINK_SLOT_RE = re.compile(r'\b(x?gei_\d+/\d+)\b')
# Baris pertama `show interface <port>` port valid: "<port> is up/down, ...".
_PORT_STATUS_FIRST_LINE_RE = re.compile(r'^\s*x?gei_\d+/\d+/\d+\s+is\s+', re.IGNORECASE)
# Maksimum sub-port fisik per slot yang di-enumerasi saat discovery.
_MAX_SUBPORTS_PER_SLOT = 4


def execute_get_uplink_ports(connection: dict, args: dict) -> dict:
    """v0.23.5 Bagian 2 — BACA status port uplink 3-segmen (gei_/xgei_
    R/S/P), READ-ONLY. Tiga command TERUJI per port: `show interface
    <port>`, `show interface optical-module-info <port>`, `show vlan port
    <port>`. Raw per port (parsing kolom di sisi PHP).

    `args['ports']` (list 3-segmen) opsional — kalau diisi, query itu saja
    (dipakai test/refresh terfokus). Kalau kosong: DISCOVERY otomatis —
    `show interface port-status ?` memberi slot 2-segmen (gei_1/19 dst),
    lalu enumerasi sub-port /1.._MAX per slot, `show interface <slot>/<n>`,
    simpan hanya yang baris pertamanya "<port> is ..." (port fisik nyata),
    buang yang `%Error`. Prefix gei_/xgei_ sama-sama dicoba karena satu
    slot fisik bisa campur (mis. slot 1/19: xgei /1-2 + gei /3-4)."""
    ports = args.get('ports')
    if isinstance(ports, list) and ports:
        for p in ports:
            if not isinstance(p, str) or not _GEI_PORT_RE.match(p):
                raise OltSessionError(f"Port tidak valid: {p!r}", 'operation')
        valid_ports = [p for p in ports if p.count('/') == 2]  # hanya 3-segmen
    else:
        valid_ports = _discover_uplink_ports(connection)

    if not valid_ports:
        return {'ports': []}

    per_port = 3  # show interface / optical-module-info / vlan port
    commands: list[str] = []
    for p in valid_ports:
        commands.append(f'show interface {p}')
        commands.append(f'show interface optical-module-info {p}')
        commands.append(f'show vlan port {p}')

    outputs = _run_zte_telnet_session(connection, commands, overall_timeout=240.0)
    result = []
    for idx, p in enumerate(valid_ports):
        base = idx * per_port
        status_raw = outputs[base] if base < len(outputs) else ''
        optical_raw = outputs[base + 1] if base + 1 < len(outputs) else ''
        vlan_raw = outputs[base + 2] if base + 2 < len(outputs) else ''
        result.append({
            'name': p,
            'is_10g': p.startswith('xgei_'),
            'port_status_lines': [mask_sensitive_tokens(x) for x in lines_from_capture(status_raw, f'show interface {p}')],
            'optical_lines': [mask_sensitive_tokens(x) for x in lines_from_capture(optical_raw, f'show interface optical-module-info {p}')],
            'vlan_lines': [mask_sensitive_tokens(x) for x in lines_from_capture(vlan_raw, f'show vlan port {p}')],
        })
    return {'ports': result}


def _discover_uplink_ports(connection: dict) -> list[str]:
    """Discovery port uplink 3-segmen. `show interface port-status ?`
    memberi slot 2-segmen; untuk tiap slot enumerasi /1.._MAX dan cek mana
    yang port fisik nyata (baris pertama `show interface <port>` = "<port>
    is ..."). READ-ONLY (semua `show`; `?` di command discovery adalah
    `show` read-only, error Incomplete-nya tak berbahaya)."""
    disc = _run_zte_telnet_command(connection, 'show interface port-status ?')
    slots: list[str] = []
    for m in _UPLINK_SLOT_RE.findall(disc):
        if m not in slots:
            slots.append(m)

    candidates = [f'{slot}/{n}' for slot in slots for n in range(1, _MAX_SUBPORTS_PER_SLOT + 1)]
    if not candidates:
        return []

    outputs = _run_zte_telnet_session(
        connection,
        [f'show interface {c}' for c in candidates],
        overall_timeout=240.0,
    )
    valid: list[str] = []
    for cand, out in zip(candidates, outputs):
        for line in out.splitlines():
            if _PORT_STATUS_FIRST_LINE_RE.match(line):
                valid.append(cand)
                break
    return valid


def _run_zte_telnet_session(connection: dict, commands: list[str], overall_timeout: float = 120.0) -> list[str]:
    """v0.23.5 Bagian 2 — login SEKALI, jalankan beberapa command BACA
    berurutan (read-only, tab Uplink). Tidak untuk command yang mengubah
    apa pun."""
    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + overall_timeout

    outputs: list[str] = []
    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth
        for command in commands:
            outputs.append(run_command_and_capture(child, command, deadline))
        return outputs
    finally:
        _logout_zte(child)


def _logout_zte(child: 'pexpect.spawn') -> None:
    # Ctrl-C dulu, unconditional — membatalkan sub-dialog macet apa pun
    # (mis. prompt password `enable` yang tidak diharapkan) SEBELUM
    # mengirim teks exit/logout apa pun ke dalamnya (root-cause fix
    # "Insiden Sesi 1").
    try:
        child.send('\x03')
        child.expect([PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=2)
    except Exception:
        pass

    try:
        child.sendline('exit')
        index = child.expect(
            [LOGOUT_EXACT_CONFIRM_RE, LOGOUT_GENERIC_CONFIRM_RE, PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF],
            timeout=8,
        )
        if index == 0:
            # SATU-SATUNYA konfirmasi yang boleh dijawab — mekanika
            # terminal ZTE normal, bukan tanda perubahan belum tersimpan.
            child.sendline('yes')
            child.expect([pexpect.EOF, pexpect.TIMEOUT], timeout=8)
        # index == 1 (konfirmasi lain yang TIDAK dikenal) -> sengaja TIDAK
        # dijawab, langsung tutup koneksi dari sisi client.
        # index 2/3/4 -> baik-baik saja, lanjut tutup.
    except Exception:
        pass
    finally:
        try:
            child.close(force=True)
        except Exception:
            pass


# ============================================================================
# WRITE (v0.23.5) — activate_onu / save_config
# ============================================================================
#
# Params SELALU dict terstruktur dari pemanggil (Laravel) — sidecar sendiri
# yang merakit + memvalidasi urutan CLI, TIDAK PERNAH menerima/menjalankan
# command mentah dari luar. Setiap field divalidasi KETAT (whitelist untuk
# yang punya himpunan nilai dikenal — VLAN, nama profil tcont/traffic;
# pola/rentang untuk yang lain) SEBELUM satu pun command dirakit — reject
# keras kalau tidak masuk akal, tidak pernah diam-diam melanjutkan dengan
# nilai yang meragukan.

# 9 profil tcont/traffic yang GENUINELY ada di device (dikonfirmasi live,
# "show gpon profile tcont"/"show gpon profile traffic", Sesi 8 v0.23.2 —
# lihat docs/omci/zte-c300-cli-reference.md "Tabel Profil Paket"). Dipakai
# untuk memvalidasi tcont_profile MAUPUN traffic_profile.
_KNOWN_PROFILES = {
    'default', 'HomeFixed-10Mbps', 'HomeFixed-20Mbps', 'HomeFixed-30Mbps',
    'HomeFixed-40Mbps', 'HomeFixed-50Mbps', 'HomeFixed-100Mbps',
    'HomeFixed-150Mbps',
    'SMARTOLT-VOIPMNG-10M', 'PPPoE-Remote',
}

# VLAN yang perannya sudah dikonfirmasi (Sesi 7 v0.23.2 + keputusan
# eksplisit Agung v0.23.5: 9=mgmt/TR069, 10=PPPoE lewat test-x86-bajastu,
# 172=bridge/hotspot, 111=PPPoE lewat ro-hotspot — VLAN pelanggan ONU ini
# di-trunk ke DUA router, VLAN 111 dikonfirmasi valid sampai ro-hotspot
# dengan PPPoE Server/PPP Profile/IP Pool BOSS App sendiri, Network
# Profile Group #29) — satu-satunya nilai yang boleh dipakai
# activate_onu/fix_onu_vlan untuk NAS ini. VLAN lain yang belum pernah
# dikonfirmasi perannya DITOLAK, bukan ditebak.
#
# v0.23.5 (Opsi B) — diperluas ke seluruh VLAN pelanggan yang BENAR-BENAR
# terkonfigurasi di NetworkProfileGroup NAS ro-hotspot (dikonfirmasi dari
# DB: 101/110/131/150/151 untuk paket ppp 100/10/30/150/50 Mbps). Tetap
# allowlist tertutup (bukan rentang bebas 1-4094) — VLAN datang dari grup
# profil terkontrol, bukan input bebas.
_KNOWN_VLANS = {9, 10, 101, 110, 111, 131, 150, 151, 172}

_PON_INTERFACE_RE = re.compile(r'^\d+/\d+/\d+$')
_SN_RE = re.compile(r'^[A-Za-z0-9]{12}$')  # "must be 12 character(s)" — konfirmasi bantuan resmi Sesi 8.
_ONU_TYPE_RE = re.compile(r'^[A-Za-z0-9_]{2,32}$')
_NAME_RE = re.compile(r'^[A-Za-z0-9 _.\-]{1,64}$')

# CELAH NYATA ditemukan sebelum eksekusi pertama (2026-09-22, sebelum
# menyentuh OLT sungguhan): run_command_and_capture() (base.py) HANYA
# melempar exception untuk prompt konfirmasi/password tak dikenal/
# timeout/EOF — kalau device menolak sebuah command dengan PESAN ERROR
# TEKS (format "%Error <kode>: <pesan>", sudah 2x teramati nyata di riset
# v0.23.2 — "%Error 20202: Invalid input detected...",
# "%Error 20203: Incomplete command") TAPI tetap kembali ke prompt normal
# (pola umum CLI Cisco-like — error dicetak sebagai teks, bukan prompt
# interaktif berbeda), run_command_and_capture() akan menganggapnya
# SUKSES dan _run_apply_sequence() akan lanjut ke command berikutnya
# tanpa sadar command sebelumnya genuinely gagal (mis. `onu <id> type
# ... sn ...` ditolak karena tipe tidak cocok SN, tapi config service/
# pon-onu-mng berikutnya tetap diterapkan ke ID yang tidak pernah
# ter-registrasi dengan benar). Instruksi eksplisit Agung ("STOP kalau
# onu type ditolak, jangan coba tipe lain") butuh deteksi kegagalan yang
# BENAR, bukan cuma "STOP" yang tidak pernah ter-trigger karena tidak
# pernah dianggap gagal sama sekali.
_DEVICE_ERROR_RE = re.compile(r'%Error\s+\d+', re.IGNORECASE)


def _run_command_or_raise(child: 'pexpect.spawn', command: str, deadline: float) -> str:
    """Wrapper run_command_and_capture() (base.py) + pemeriksaan pola
    error device eksplisit (_DEVICE_ERROR_RE) — lihat komentar di atas
    untuk kenapa base.py sendiri TIDAK cukup untuk jalur WRITE. Melempar
    OltSessionError berisi TEKS ERROR DEVICE PERSIS (bukan pesan generik)
    kalau ditemukan, supaya pemanggil (Laravel/pelapor) bisa menampilkan
    apa adanya, sesuai instruksi eksplisit "laporkan pesan error persis"."""
    output = run_command_and_capture(child, command, deadline)
    match = _DEVICE_ERROR_RE.search(output)
    if match:
        raise OltSessionError(
            f"Device menolak command '{command}': {output.strip()[:500]}",
            'command',
        )
    return output


def _require_int_in_range(params: dict, key: str, lo: int, hi: int) -> int:
    value = params.get(key)
    if not isinstance(value, int) or isinstance(value, bool) or not (lo <= value <= hi):
        raise OltSessionError(f"Parameter '{key}' harus berupa integer {lo}-{hi}", 'operation')
    return value


def _require_pattern(params: dict, key: str, pattern: 're.Pattern[str]', description: str) -> str:
    value = params.get(key)
    if not isinstance(value, str) or not pattern.match(value):
        raise OltSessionError(f"Parameter '{key}' tidak valid ({description})", 'operation')
    return value


def _require_known_profile(params: dict, key: str) -> str:
    value = params.get(key)
    if value not in _KNOWN_PROFILES:
        raise OltSessionError(
            f"Parameter '{key}'={value!r} bukan profil tcont/traffic yang dikenal (harus salah satu dari {sorted(_KNOWN_PROFILES)})",
            'operation',
        )
    return value


def _require_known_vlan(params: dict, key: str) -> int:
    value = _require_int_in_range(params, key, 1, 4094)
    if value not in _KNOWN_VLANS:
        raise OltSessionError(
            f"Parameter '{key}'={value} bukan VLAN yang dikenal untuk NAS ini (harus salah satu dari {sorted(_KNOWN_VLANS)})",
            'operation',
        )
    return value


def _build_activate_onu_commands(params: dict) -> list[str]:
    """Bangun urutan command CLI aktivasi ONU baru — level PON (registrasi
    `onu <id> type ... sn ...`) -> level ONU (`interface gpon-onu_...`,
    service tcont/gemport/service-port) -> pon-onu-mng (flow/vlan-filter/
    switchport-bind/security-mgmt) -> `end`. TANPA `wr` di akhir — dipisah
    sengaja, lihat execute_save().

    Struktur & nilai TETAP (security-mgmt, switchport-bind, flow mode,
    vlan-filter-mode) di-copy PERSIS dari `show onu running config` kedua
    ONU uji (Sesi 7 v0.23.2) — identik di keduanya, kandidat kuat
    template baku, bukan per-pelanggan.

    Slot VLAN per service-port mengikuti pola PERSIS ONU_UJI_1 ("Test-1"):
    service-port 1 = VLAN PPPoE, service-port 11 = VLAN mgmt/TR069,
    service-port 12 = VLAN bridge — dipilih (bukan pola Test-2, yang
    variabelnya tertukar A/B) karena inilah SATU-SATUNYA dari 2 sampel
    yang urutannya cocok dengan definisi peran eksplisit Agung
    (v0.23.5: 9=mgmt, 10=PPPoE, 172=bridge). Ini BUKAN urutan baru yang
    ditebak — ini salah satu dari 2 template yang genuinely teramati.

    JUJUR, belum TERUJI eksekusi nyata (ditandai eksplisit karena ini
    tulis pertama ke OLT produksi): navigasi antar-blok (`exit` untuk
    naik satu level config, `end` untuk kembali ke privileged) memakai
    konvensi Cisco-like standar — TIDAK ADA satu pun referensi (bahkan
    "dari referensi publik, belum dieksekusi") di riset v0.23.1/v0.23.2
    untuk command navigasi ini secara spesifik. Setiap command dalam
    urutan ini dijalankan SATU PER SATU lewat run_command_and_capture(),
    yang melempar OltSessionError untuk prompt/response apa pun di luar
    dugaan — proses berhenti SEKETIKA di command yang gagal, tidak pernah
    melanjutkan buta ke command berikutnya.
    """
    pon = _require_pattern(params, 'pon_interface', _PON_INTERFACE_RE, 'format R/S/P, mis. "1/3/12"')
    onu_id = _require_int_in_range(params, 'onu_id', 1, 128)
    sn = _require_pattern(params, 'sn', _SN_RE, 'harus 12 karakter alfanumerik')
    onu_type = _require_pattern(params, 'onu_type', _ONU_TYPE_RE, 'alfanumerik/underscore, 2-32 karakter')
    name = _require_pattern(params, 'name', _NAME_RE, 'maks 64 karakter, huruf/angka/spasi/._- saja')
    tcont_profile = _require_known_profile(params, 'tcont_profile')
    traffic_profile = _require_known_profile(params, 'traffic_profile')
    vlan_pppoe = _require_known_vlan(params, 'vlan_pppoe')
    vlan_mgmt = _require_known_vlan(params, 'vlan_mgmt')
    vlan_bridge = _require_known_vlan(params, 'vlan_bridge')

    # v0.23.5 (Opsi B) — "Attached VLANs" tambahan sebagai baris flow
    # PERMISSION saja (izin lewat), BUKAN service-port/WAN baru. OPSIONAL:
    # default [] -> urutan IDENTIK perilaku lama (updatePackage path tidak
    # berubah). Tiap nilai divalidasi _KNOWN_VLANS dan di-dedup terhadap 3
    # VLAN basis (mgmt/pppoe/bridge) yang sudah punya baris flow sendiri.
    extra_flow_vlans_raw = params.get('extra_flow_vlans', [])
    if not isinstance(extra_flow_vlans_raw, list):
        raise OltSessionError("Parameter 'extra_flow_vlans' harus berupa list", 'operation')
    base_vlans = {vlan_mgmt, vlan_pppoe, vlan_bridge}
    extra_flow_vlans: list[int] = []
    for v in extra_flow_vlans_raw:
        if not isinstance(v, int) or isinstance(v, bool) or v not in _KNOWN_VLANS:
            raise OltSessionError(
                f"Nilai 'extra_flow_vlans' tidak valid ({v!r}) — harus VLAN dikenal {sorted(_KNOWN_VLANS)}",
                'operation',
            )
        if v not in base_vlans and v not in extra_flow_vlans:
            extra_flow_vlans.append(v)

    onu_olt_if = f'gpon-olt_{pon}'
    onu_if = f'gpon-onu_{pon}:{onu_id}'

    commands = [
        'configure terminal',
        f'interface {onu_olt_if}',
        f'onu {onu_id} type {onu_type} sn {sn}',
        'exit',
        f'interface {onu_if}',
        f'name {name}',
        'description none',
        f'tcont 1 profile {tcont_profile}',
        'gemport 1 tcont 1',
        f'gemport 1 traffic-limit downstream {traffic_profile}',
        f'service-port 1  vport 1 user-vlan {vlan_pppoe} vlan {vlan_pppoe}',
        f'service-port 11 vport 1 user-vlan {vlan_mgmt} vlan {vlan_mgmt}',
        f'service-port 12 vport 1 user-vlan {vlan_bridge} vlan {vlan_bridge}',
        'exit',
        f'pon-onu-mng {onu_if}',
        'flow mode 1 tag-filter vlan-filter untag-filter discard',
        f'flow 1 pri 0 vlan {vlan_mgmt}',
        f'flow 1 pri 0 vlan {vlan_pppoe}',
        f'flow 1 pri 0 vlan {vlan_bridge}',
    ]
    commands += [f'flow 1 pri 0 vlan {v}' for v in extra_flow_vlans]
    commands += [
        'gemport 1 flow 1',
        'switchport-bind switch_0/1 iphost 1',
        'switchport-bind switch_0/1 veip 1',
        'vlan-filter-mode iphost 1 tag-filter vlan-filter untag-filter discard',
        f'vlan-filter iphost 1 pri 0 vlan {vlan_pppoe}',
        'security-mgmt 998 state enable mode forward ingress-type lan protocol web https',
        'security-mgmt 999 state enable ingress-type lan protocol ftp telnet ssh snmp tr069',
        'end',
    ]
    return commands


def _build_fix_onu_vlan_commands(params: dict) -> list[str]:
    """v0.23.5 — koreksi VLAN PPPoE pada ONU yang SUDAH ter-apply (RAM),
    TANPA reapply seluruh blok dari nol — TIDAK menyentuh registrasi PON
    (`onu <id> type ... sn ...`), TIDAK menyentuh VLAN mgmt (9)/bridge
    (172) atau baris `security-mgmt`/`switchport-bind`/`gemport`/`tcont`
    apa pun. Hanya 3 baris yang menyebut VLAN PPPoE: `service-port 1`
    (level interface ONU), `flow 1 pri 0` dan `vlan-filter iphost 1
    pri 0` (level pon-onu-mng).

    Dipakai pertama kali untuk ONU_1 (koreksi VLAN 10 -> 111 setelah
    klarifikasi Agung bahwa VLAN pelanggan ONU ini di-trunk ke DUA router,
    dan skema RADIUS yang dimaksud ada di ro-hotspot, bukan test-x86-bajastu)
    — BELUM PERNAH diuji apakah device benar-benar MENIMPA (replace)
    assignment lama pada index port/flow yang sama, atau menolaknya.
    Sama seperti seluruh urutan tulis v0.23.5 lain, setiap command
    dijalankan satu per satu lewat _run_apply_sequence() (deteksi
    _DEVICE_ERROR_RE aktif) — berhenti seketika di command pertama yang
    gagal/merespons di luar dugaan."""
    pon = _require_pattern(params, 'pon_interface', _PON_INTERFACE_RE, 'format R/S/P, mis. "1/3/12"')
    onu_id = _require_int_in_range(params, 'onu_id', 1, 128)
    new_vlan_pppoe = _require_known_vlan(params, 'new_vlan_pppoe')

    onu_if = f'gpon-onu_{pon}:{onu_id}'

    return [
        'configure terminal',
        f'interface {onu_if}',
        f'service-port 1  vport 1 user-vlan {new_vlan_pppoe} vlan {new_vlan_pppoe}',
        'exit',
        f'pon-onu-mng {onu_if}',
        f'flow 1 pri 0 vlan {new_vlan_pppoe}',
        f'vlan-filter iphost 1 pri 0 vlan {new_vlan_pppoe}',
        'end',
    ]


def _build_delete_onu_commands(params: dict) -> list[str]:
    """v0.23.5 — hapus registrasi ONU di level PON, sintaks TERUJI lewat
    bantuan CLI (`no ?` -> `no onu ?` di context `interface gpon-olt_...`,
    ditemukan setelah fix_onu_vlan terbukti menghasilkan config campur
    aduk — service-port tidak bisa di-override in-place, flow/vlan-filter
    justru APPEND bukan REPLACE). Opsi paling bersih: hapus total lalu
    registrasi ulang dari nol dengan VLAN yang benar sejak baris pertama,
    bukan partial fix. `no onu <1-128>` — HANYA butuh ID, tidak ada
    argumen lain (dikonfirmasi lewat `no onu ?`)."""
    pon = _require_pattern(params, 'pon_interface', _PON_INTERFACE_RE, 'format R/S/P, mis. "1/3/12"')
    onu_id = _require_int_in_range(params, 'onu_id', 1, 128)

    return [
        'configure terminal',
        f'interface gpon-olt_{pon}',
        f'no onu {onu_id}',
        'end',
    ]


# Username: format skema RADIUS terkunci "{cid}@ppp.bajastu.id" (alfanumerik
# + @/./-/_), dikonfirmasi bantuan CLI "WORD (1-128 character(s))". Password:
# printable, TIDAK PERNAH boleh mengandung spasi/kutip/backslash/titik-koma/
# newline — pengaman KERAS terhadap command-injection (baris ini dikirim
# APA ADANYA ke sesi telnet; password dari form UI Bagian D nanti berasal
# dari input manusia, bukan literal yang sudah dipastikan aman seperti di
# sini — validasi ini HARUS ketat sejak awal, bukan dipercaya begitu saja).
_PPPOE_USERNAME_RE = re.compile(r'^[A-Za-z0-9@._-]{1,128}$')
_PPPOE_PASSWORD_RE = re.compile(r'^[A-Za-z0-9!#%^*()_+=,.-]{1,128}$')


def _build_add_pppoe_commands(params: dict) -> list[str]:
    """v0.23.5 (Bagian B) — tambah baris `pppoe <host_id>` di
    `pon-onu-mng gpon-onu_{pon}:{onu_id}`, sintaks ditemukan lewat bantuan
    CLI bertahap (`pppoe ?` -> `pppoe 1 ?` -> `pppoe 1 user ?` ->
    `pppoe 1 user <X> ?`) — TIDAK PERNAH ditebak. Perilaku "override vs
    append vs ditolak" pada host_id yang SUDAH terisi TIDAK diketahui dari
    bantuan CLI (baru bisa dipastikan lewat eksekusi nyata, sama seperti
    service-port/flow sebelumnya) — builder ini HANYA dipakai untuk
    penambahan baru pada host_id yang genuinely masih kosong (ONU_1 belum
    pernah punya baris pppoe sama sekali sejak registrasi ulang), bukan
    untuk update host_id yang sudah terisi (itu kasus Bagian D, keputusan
    override/hapus-tulis-ulang menyusul setelah perilaku nyata diuji)."""
    pon = _require_pattern(params, 'pon_interface', _PON_INTERFACE_RE, 'format R/S/P, mis. "1/3/12"')
    onu_id = _require_int_in_range(params, 'onu_id', 1, 128)
    host_id = _require_int_in_range(params, 'host_id', 1, 255)
    username = _require_pattern(params, 'username', _PPPOE_USERNAME_RE, '1-128 karakter alfanumerik/@/./_/-')
    password = _require_pattern(params, 'password', _PPPOE_PASSWORD_RE, '1-128 karakter printable tanpa spasi/kutip/backslash/titik-koma')
    nat_enabled = params.get('nat_enabled', True)
    if not isinstance(nat_enabled, bool):
        raise OltSessionError("Parameter 'nat_enabled' harus boolean", 'operation')

    nat_word = 'enable' if nat_enabled else 'disable'
    onu_if = f'gpon-onu_{pon}:{onu_id}'

    return [
        'configure terminal',
        f'pon-onu-mng {onu_if}',
        f'pppoe {host_id} nat {nat_word} user {username} password {password}',
        'end',
    ]


# v0.23.5 (Opsi B) — _build_add_wan_bridge_commands() DIHAPUS. Pivot lama
# "WAN node terpisah" (`wan <n> service other/internet` sebagai WAN sendiri)
# ditinggalkan: `service other` wajib `mvlan` (= multicast/IPTV, bukan
# bridge), dan probe `?` sempat tak sengaja meng-commit `wan 2 service
# internet`. Model baru: "Attached VLANs" = baris flow permission tambahan
# di dalam template activate_onu (parameter `extra_flow_vlans`), BUKAN node
# `wan` terpisah. Lihat docs/omci/onu-test-ui-design.md.


OPERATIONS_WRITE = {
    'activate_onu': _build_activate_onu_commands,
    'fix_onu_vlan': _build_fix_onu_vlan_commands,
    'delete_onu': _build_delete_onu_commands,
    'add_pppoe': _build_add_pppoe_commands,
}


def execute_apply(connection: dict, operation: str, params: dict):
    """Jalankan operasi TULIS terstruktur. `params` SELALU dict field-value
    (tidak pernah command mentah) — sidecar merakit + memvalidasi urutan
    CLI dari template internal (lihat OPERATIONS_WRITE).

    Return `(result_dict, None)` kalau SEMUA command sukses. Melempar
    OltSessionError kalau validasi params gagal, ATAU command mana pun di
    tengah rangkaian gagal/merespons di luar dugaan — lihat
    _run_apply_sequence() untuk penanganan kegagalan di tengah rangkaian.
    """
    builder = OPERATIONS_WRITE.get(operation)
    if builder is None:
        raise OltSessionError(f"Operasi tulis '{operation}' tidak dikenal untuk zte_c300", 'operation')

    commands = builder(params)
    executed = _run_apply_sequence(connection, commands)

    return {
        'onu_interface': f"gpon-onu_{params.get('pon_interface')}:{params.get('onu_id')}",
        'commands_applied': len(executed),
    }, None


def _run_apply_sequence(connection: dict, commands: list[str], overall_timeout: float = 90.0) -> list[str]:
    """Buka SATU sesi telnet, kirim `commands` berurutan satu per satu lewat
    run_command_and_capture() yang sudah ada (base.py) — fungsi itu SENDIRI
    yang melempar OltSessionError untuk prompt konfirmasi/password tak
    dikenal, timeout, atau EOF; TIDAK ADA logic "coba lagi otomatis" apa
    pun ditambahkan di sini. TIDAK PERNAH mengirim `wr` (lihat
    execute_save()) — apply dan save adalah dua aksi terpisah sengaja
    (pengaman keputusan Agung v0.23.5: config yang di-apply tanpa save
    aman ditinggalkan/hilang kalau device reboot).

    SEMUA command sukses -> logout NORMAL (_logout_zte(), yang menjawab
    "yes" HANYA untuk prompt persis "confirm to logout without saving?").

    ADA command yang gagal di tengah -> TUTUP KONEKSI PAKSA tanpa
    mengirim command logout apa pun lagi (device mungkin dalam mode
    config nested yang perilaku exit/logout persisnya tidak diketahui
    pasti untuk firmware ini) — sesi vty dibersihkan device sendiri lewat
    idle-timeout/connection-reset, aman karena `wr` tidak pernah terkirim.
    """
    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + overall_timeout

    executed: list[str] = []
    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        for command in commands:
            _run_command_or_raise(child, command, deadline)
            executed.append(command)
    except Exception:
        try:
            child.close(force=True)
        except Exception:
            pass
        raise
    else:
        _logout_zte(child)

    return executed


def execute_save(connection: dict) -> dict:
    """Kirim HANYA `wr` (write/save config berjalan ke NVRAM), di sesi
    TERPISAH dari apply — dipanggil hanya setelah verifikasi online sukses
    (kontrak level Laravel, bukan dipaksakan di sini). `wr` BELUM PERNAH
    divalidasi eksekusi nyata sebelum v0.23.5 (tercatat "DILARANG, tidak
    dieksekusi" di seluruh riset v0.23.1/v0.23.2) — response APA PUN
    selain kembalinya prompt normal ditangani identik dengan command lain
    manapun oleh _run_command_or_raise() (OltSessionError, tidak pernah
    ditebak/dijawab otomatis — termasuk pola pesan error teks device,
    lihat _DEVICE_ERROR_RE)."""
    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + 30.0

    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        output = _run_command_or_raise(child, 'wr', deadline)
    except Exception:
        try:
            child.close(force=True)
        except Exception:
            pass
        raise
    else:
        _logout_zte(child)

    return {'raw_excerpt': mask_sensitive_tokens(output)[:500]}


# ============================================================================
# DISCOVERY (v0.23.5, sesi save-command tidak dikenal) — probe_config_help
# ============================================================================
#
# `wr` ditolak device ("%Error 20200: Invalid command") — genuinely belum
# ada command save yang terverifikasi untuk firmware ini. Operasi ini
# HANYA untuk mencari nama command yang benar lewat bantuan CLI `?` di
# dalam mode config — TIDAK PERNAH mengeksekusi command config sungguhan.
# Pengaman STRUKTURAL (bukan cuma disiplin pemanggil): `query` WAJIB
# match _HELP_QUERY_RE — string yang TIDAK diakhiri `?` ditolak SEBELUM
# menyentuh jaringan, sehingga operasi ini secara struktural tidak bisa
# disalahgunakan untuk mengirim command config sungguhan.

# v0.23.5 amendment 2 — generalisasi: JUMLAH token sebelum `?` tidak lagi
# dibatasi 2 (drill-down nyata butuh makin dalam — "pppoe 1 nat ?" adalah
# 3 token, dan investigasi berikutnya mungkin butuh lebih; daripada
# mengedit angka hardcode berulang kali, pattern sekarang menerima token
# SEBANYAK APAPUN yang dipisah spasi tunggal). Token boleh diawali huruf
# ATAU angka, dan boleh mengandung '.'/'@' (untuk kandidat drill-down
# semacam username email-shaped di masa depan). PENGAMAN UTAMA TIDAK
# PERNAH melemah: string yang TIDAK diakhiri `?` SELALU ditolak — ini
# murni memperluas token yang diterima SEBELUM `?`, tidak pernah
# melonggarkan syarat `?` wajib di akhir, sehingga tetap TIDAK PERNAH bisa
# dipakai mengirim command config sungguhan (yang tidak pernah diakhiri
# `?`).
_HELP_QUERY_RE = re.compile(
    r'^([A-Za-z0-9][A-Za-z0-9_.@-]{0,63}(\s[A-Za-z0-9][A-Za-z0-9_.@-]{0,63})*)?\s?\?$'
)


def execute_probe_config_help(connection: dict, params: dict) -> dict:
    """Masuk `configure terminal`, kirim SATU query bantuan (`params['query']`,
    divalidasi _HELP_QUERY_RE — selalu diakhiri `?`, maks 2 kata sebelum
    itu), lalu `end` untuk kembali ke privileged (BUKAN `exit` berulang —
    menghindari ambiguitas keluar dari node interface yang tidak pernah
    dimasuki operasi ini sama sekali). TIDAK PERNAH menjalankan command
    config ONU_1 atau config lain apa pun — hanya `configure terminal`,
    query bantuan, `end`."""
    query = params.get('query')
    if not isinstance(query, str) or not _HELP_QUERY_RE.match(query):
        raise OltSessionError(
            f"Parameter 'query' tidak valid (harus diakhiri '?', maks 2 kata command sebelumnya): {query!r}",
            'operation',
        )

    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + 30.0

    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        _run_command_or_raise(child, 'configure terminal', deadline)
        help_output = run_command_and_capture(child, query, deadline)  # bantuan CLI, bukan config sungguhan — lihat catatan di bawah.
        _run_command_or_raise(child, 'end', deadline)
    except Exception:
        try:
            child.close(force=True)
        except Exception:
            pass
        raise
    else:
        _logout_zte(child)

    # Daftar bantuan config-mode device ini SANGAT panjang (chassis
    # multi-service, ratusan sub-command — konsisten dengan `show ?`
    # level top yang juga ~230 entri, lihat docs/omci/
    # zte-c300-cli-reference.md). 2000 karakter (limit awal) memotong
    # daftar sebelum mencapai command yang relevan — dinaikkan supaya
    # discovery command save tidak kehilangan kandidat.
    return {'raw_excerpt': mask_sensitive_tokens(help_output)[:20000]}


def execute_probe_onu_interface_help(connection: dict, params: dict) -> dict:
    """v0.23.5 — sama seperti execute_probe_config_help(), TAPI masuk SATU
    langkah navigasi lebih dalam: `interface gpon-olt_{pon_interface}`
    SEBELUM query bantuan — dipakai untuk mencari sintaks command yang
    hanya ada di context interface PON (mis. `no onu <id>`, sesudah
    `service-port`/`flow` di ONU_1 terbukti tidak bisa di-override
    in-place, opsi paling bersih adalah hapus+registrasi ulang ONU, tapi
    sintaks `no onu` belum pernah dikonfirmasi).

    `pon_interface` divalidasi _PON_INTERFACE_RE (sama seperti
    activate_onu/fix_onu_vlan). `query` WAJIB diakhiri `?` (_HELP_QUERY_RE
    — sama pengaman struktural seperti execute_probe_config_help), TIDAK
    PERNAH bisa dipakai eksekusi config sungguhan. Navigasi masuk
    (`configure terminal`, `interface gpon-olt_...`) dan keluar (`end`)
    memakai _run_command_or_raise() (deteksi _DEVICE_ERROR_RE aktif) —
    kalau salah satu GAGAL (mis. `interface gpon-olt_...` ditolak), STOP
    seketika, TIDAK melanjutkan ke query help. Query bantuan itu sendiri
    (`run_command_and_capture()` biasa, BUKAN _run_command_or_raise())
    boleh menghasilkan `%Error` untuk kandidat yang salah — itu HASIL
    YANG BERGUNA (bukti kandidat invalid), bukan kegagalan sesi."""
    pon = _require_pattern(params, 'pon_interface', _PON_INTERFACE_RE, 'format R/S/P, mis. "1/3/12"')
    query = params.get('query')
    if not isinstance(query, str) or not _HELP_QUERY_RE.match(query):
        raise OltSessionError(
            f"Parameter 'query' tidak valid (harus diakhiri '?', maks 2 kata command sebelumnya): {query!r}",
            'operation',
        )

    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + 30.0

    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        _run_command_or_raise(child, 'configure terminal', deadline)
        _run_command_or_raise(child, f'interface gpon-olt_{pon}', deadline)
        help_output = run_command_and_capture(child, query, deadline)  # bantuan CLI, sama alasan seperti di atas.
        _run_command_or_raise(child, 'end', deadline)
    except Exception:
        try:
            child.close(force=True)
        except Exception:
            pass
        raise
    else:
        _logout_zte(child)

    return {'raw_excerpt': mask_sensitive_tokens(help_output)[:20000]}


def execute_probe_onu_service_help(connection: dict, params: dict) -> dict:
    """v0.23.5 — sama pola seperti execute_probe_onu_interface_help(), TAPI
    masuk ke `interface gpon-onu_{pon}:{onu_id}` (level SERVICE per-ONU:
    name/tcont/gemport/service-port), BUKAN `interface gpon-olt_{pon}`
    (level registrasi PON). Dipakai untuk mencari parameter "WAN VLAN"
    tersembunyi yang mungkin ada di level ini (investigasi kontradiksi
    SmartOLT: OLT CLI menunjukkan VLAN 111, SmartOLT UI menunjukkan
    "WAN vlan: 10" — SmartOLT mungkin membaca parameter OMCI terpisah
    dari service-port biasa, bukan sekadar cache stale).

    Sama pengaman struktural seperti fungsi probe lain: `query` WAJIB
    diakhiri `?` (_HELP_QUERY_RE), navigasi masuk/keluar
    (`configure terminal`, `interface gpon-onu_...`, `end`) memakai
    _run_command_or_raise() (STOP seketika kalau gagal/di luar dugaan),
    query itu sendiri boleh menghasilkan `%Error` untuk kandidat salah
    (hasil berguna, bukan kegagalan sesi)."""
    pon = _require_pattern(params, 'pon_interface', _PON_INTERFACE_RE, 'format R/S/P, mis. "1/3/12"')
    onu_id = _require_int_in_range(params, 'onu_id', 1, 128)
    query = params.get('query')
    if not isinstance(query, str) or not _HELP_QUERY_RE.match(query):
        raise OltSessionError(
            f"Parameter 'query' tidak valid (harus diakhiri '?', maks 2 kata command sebelumnya): {query!r}",
            'operation',
        )

    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + 30.0

    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        _run_command_or_raise(child, 'configure terminal', deadline)
        _run_command_or_raise(child, f'interface gpon-onu_{pon}:{onu_id}', deadline)
        help_output = run_command_and_capture(child, query, deadline)  # bantuan CLI, sama alasan seperti di atas.
        _run_command_or_raise(child, 'end', deadline)
    except Exception:
        try:
            child.close(force=True)
        except Exception:
            pass
        raise
    else:
        _logout_zte(child)

    return {'raw_excerpt': mask_sensitive_tokens(help_output)[:20000]}


def execute_probe_onu_mng_help(connection: dict, params: dict) -> dict:
    """v0.23.5 — sama pola seperti execute_probe_onu_service_help(), TAPI
    masuk ke `pon-onu-mng gpon-onu_{pon}:{onu_id}` (BUKAN
    `interface gpon-onu_...` — node TERPISAH, ditemukan sejak riset v0.23.2
    Sesi 7: `show onu running config` vs `show running-config interface`
    adalah 2 command berbeda untuk 2 node config berbeda). Dipakai
    investigasi sintaks `pppoe <n> nat enable user ... password ...`
    (Bagian B v0.23.5) — cari lewat `pppoe ?`/`pppoe 1 ?`, JANGAN pernah
    menebak sintaks tanpa bantuan CLI.

    Sama pengaman struktural seperti fungsi probe lain: `query` WAJIB
    diakhiri `?`, navigasi masuk/keluar pakai _run_command_or_raise()
    (STOP seketika kalau gagal), query itu sendiri pakai
    run_command_and_capture() biasa (%Error untuk kandidat salah = hasil
    berguna, bukan kegagalan sesi)."""
    pon = _require_pattern(params, 'pon_interface', _PON_INTERFACE_RE, 'format R/S/P, mis. "1/3/12"')
    onu_id = _require_int_in_range(params, 'onu_id', 1, 128)
    query = params.get('query')
    if not isinstance(query, str) or not _HELP_QUERY_RE.match(query):
        raise OltSessionError(
            f"Parameter 'query' tidak valid (harus diakhiri '?', maks 2 kata command sebelumnya): {query!r}",
            'operation',
        )

    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + 30.0

    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        _run_command_or_raise(child, 'configure terminal', deadline)
        _run_command_or_raise(child, f'pon-onu-mng gpon-onu_{pon}:{onu_id}', deadline)
        help_output = run_command_and_capture(child, query, deadline)  # bantuan CLI, sama alasan seperti di atas.
        _run_command_or_raise(child, 'end', deadline)
    except Exception:
        try:
            child.close(force=True)
        except Exception:
            pass
        raise
    else:
        _logout_zte(child)

    return {'raw_excerpt': mask_sensitive_tokens(help_output)[:20000]}


# Port fisik uplink ZTE C300: gei_ (GE) / xgei_ (10GE). Format R/S/P
# (3 segmen, mis. gei_1/19/1) — segmen ketiga adalah port fisik; 2-segmen
# (gei_1/19) hanya rack/slot (ditemukan v0.23.5 Bagian 2: token 2-segmen
# ditolak command meski muncul di help). 2-segmen tetap diterima regex
# untuk fleksibilitas discovery.
_GEI_PORT_RE = re.compile(r'^x?gei_\d+/\d+(?:/\d+)?$')


def execute_probe_gei_interface_help(connection: dict, params: dict) -> dict:
    """v0.23.5 Bagian 2 — DISCOVERY sub-command yang tersedia di context
    port fisik uplink (`interface gei_{port}`/`interface xgei_{port}`),
    untuk menemukan command status/optical/VLAN-trunk port uplink yang
    sintaksnya belum ketemu (`show interface <port>` ditolak di exec mode).

    Masuk `configure terminal` -> `interface {port}` SEBELUM query bantuan.
    `port` divalidasi _GEI_PORT_RE (gei_/xgei_ saja — TIDAK bisa dipakai
    masuk context gpon-onu/gpon-olt). `query` WAJIB diakhiri `?`
    (_HELP_QUERY_RE) — pengaman struktural identik probe lain, tidak pernah
    bisa eksekusi config sungguhan. Navigasi masuk/keluar pakai
    _run_command_or_raise() (STOP kalau `interface {port}` ditolak); query
    bantuan sendiri boleh `%Error` (hasil berguna). HANYA untuk melihat
    `show ?`/bantuan di context ini — pemanggil TIDAK boleh mengubah apa
    pun (query `?`-only menjamin itu secara struktural)."""
    port = params.get('port')
    if not isinstance(port, str) or not _GEI_PORT_RE.match(port):
        raise OltSessionError("Parameter 'port' tidak valid (format gei_R/S atau xgei_R/S)", 'operation')
    query = params.get('query')
    if not isinstance(query, str) or not _HELP_QUERY_RE.match(query):
        raise OltSessionError(f"Parameter 'query' tidak valid (harus diakhiri '?'): {query!r}", 'operation')

    host = connection['host']
    tport = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(tport)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + 30.0

    try:
        _zte_login(child, username, password)
        password = None  # noqa: F841 — defense-in-depth

        _run_command_or_raise(child, 'configure terminal', deadline)
        _run_command_or_raise(child, f'interface {port}', deadline)
        help_output = run_command_and_capture(child, query, deadline)
        _run_command_or_raise(child, 'end', deadline)
    except Exception:
        try:
            child.close(force=True)
        except Exception:
            pass
        raise
    else:
        _logout_zte(child)

    return {'raw_excerpt': mask_sensitive_tokens(help_output)[:20000]}


# v0.23.5 — routing nama operation (payload dari Laravel) -> nama fungsi
# Python di modul ini. Dipakai main.py's /probe endpoint supaya endpoint
# itu tetap generic (tidak hardcode 1 fungsi) sambil TETAP TIDAK menerima
# command CLI bebas dari luar sidecar sama sekali — hanya nama operation
# yang sudah dikenal di sini yang bisa dipanggil.
PROBE_OPERATIONS = {
    'config_help': 'execute_probe_config_help',
    'onu_interface_help': 'execute_probe_onu_interface_help',
    'onu_service_help': 'execute_probe_onu_service_help',
    'onu_mng_help': 'execute_probe_onu_mng_help',
    'gei_interface_help': 'execute_probe_gei_interface_help',
}
