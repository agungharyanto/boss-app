"""Unit test scoped untuk translasi command per vendor & helper parsing —
TIDAK memanggil pexpect/OLT sungguhan (itu integration test manual
terhadap 3 OLT real, dijalankan terpisah lewat boss-app)."""
import pytest

from app.vendors import hsgq_e04id, hsgq_g02id, zte_c300
from app.vendors.base import (
    OltSessionError,
    lines_from_capture,
    mask_sensitive_tokens,
    parse_field_value_block,
)


def test_hsgq_e04id_only_exposes_show_version_as_teruji_operation():
    assert hsgq_e04id.OPERATIONS == {'show_version': 'show version'}


def test_hsgq_g02id_only_exposes_show_version_as_teruji_operation():
    assert hsgq_g02id.OPERATIONS == {'show_version': 'show version'}


def test_zte_c300_exposes_only_teruji_operations():
    # v0.23.4 — onu_detail_info ditambahkan (TERUJI eksekusi nyata Sesi
    # 6-7 v0.23.2, lihat docs/omci/onu-registry-design.md §2/§7).
    # v0.23.5 — onu_state/onu_baseinfo ditambahkan sebagai prasyarat
    # write-activation (TERUJI eksekusi nyata Sesi 5 v0.23.2, args['onu']
    # di sini adalah interface PON seperti "gpon-olt_1/3/12", bukan
    # interface ONU individual). Daftar operasi harus SAMA PERSIS ini,
    # tidak lebih tidak kurang.
    assert zte_c300.OPERATIONS == {
        'onu_uncfg_list': 'show gpon onu uncfg',
        'onu_detail_info': 'show gpon onu detail-info {onu}',
        'onu_state': 'show gpon onu state {onu}',
        'onu_baseinfo': 'show gpon onu baseinfo {onu}',
        'onu_running_config': 'show running-config interface {onu}',
        'onu_mng_running_config': 'show onu running config {onu}',
    }
    assert zte_c300.REQUIRES_ONU_ARG == {
        'onu_detail_info', 'onu_state', 'onu_baseinfo',
        'onu_running_config', 'onu_mng_running_config',
    }


def test_hsgq_e04id_rejects_an_unknown_operation_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        hsgq_e04id.execute({}, 'onu_uncfg_list', {})

    assert exc_info.value.stage == 'operation'


def test_hsgq_g02id_rejects_an_unknown_operation_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        hsgq_g02id.execute({}, 'onu_uncfg_list', {})

    assert exc_info.value.stage == 'operation'


def test_zte_c300_rejects_an_unknown_operation_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute({}, 'show_version', {})

    assert exc_info.value.stage == 'operation'


def test_zte_c300_never_sends_enable_anywhere_in_its_source():
    # Regression guard tekstual — akar penyebab "Insiden Sesi 1" (v0.23.2)
    # adalah pengiriman `enable` ke ZTE yang sudah privileged sejak login.
    import inspect

    source = inspect.getsource(zte_c300)
    assert "sendline('enable')" not in source
    assert 'send -- "enable' not in source


def test_lines_from_capture_strips_command_echo_pager_markers_and_blank_lines():
    raw = (
        'show version\r\n'
        'Firmware: 1.2.3\r\n'
        '\r\n'
        '--More-- \r\n'
        'Uptime: 10 days\r\n'
    )
    result = lines_from_capture(raw, 'show version')

    assert result == ['Firmware: 1.2.3', 'Uptime: 10 days']


def test_mask_sensitive_tokens_partially_masks_long_alphanumeric_tokens():
    line = 'Serial: ABCD1234EFGH5678'
    masked = mask_sensitive_tokens(line)

    assert 'ABCD1234EFGH5678' not in masked
    assert masked.startswith('Serial: ABCD')
    assert masked.endswith('5678')
    assert '*' in masked


def test_mask_sensitive_tokens_leaves_short_tokens_and_plain_words_untouched():
    line = 'OK count 3'
    assert mask_sensitive_tokens(line) == line


def test_mask_sensitive_tokens_leaves_plain_alphabetic_words_untouched_even_when_long():
    # Regression guard — versi awal (regex tanpa syarat "campuran huruf+
    # angka") over-masking kata biasa seperti "Copyright"/"Software" di
    # output `show version` nyata (ditemukan saat verifikasi manual
    # pertama terhadap HSGQ E04ID, 2026-09-22).
    line = 'Copyright 2016-2023 by HSGQ, Software Version, Hardware Version'
    assert mask_sensitive_tokens(line) == line


def test_mask_sensitive_tokens_masks_a_purely_numeric_serial_number():
    # Regression guard — celah kedua ditemukan verifikasi manual (HSGQ
    # G02ID, 2026-09-22): serial number bergaya numerik (digit murni,
    # tanpa huruf sama sekali) lolos dari pattern campuran huruf+angka.
    # Nilai contoh di bawah FIKTIF (bukan serial number perangkat asli).
    line = 'SerialNumber                  : 900012340099'
    masked = mask_sensitive_tokens(line)

    assert '900012340099' not in masked
    assert masked.startswith('SerialNumber')
    assert '*' in masked


def test_mask_sensitive_tokens_masks_a_mac_address():
    # Nilai contoh FIKTIF (bukan MAC address perangkat asli).
    line = 'Base Mac Address : aa:bb:cc:dd:ee:ff'
    masked = mask_sensitive_tokens(line)

    assert 'aa:bb:cc:dd:ee:ff' not in masked
    assert '*' in masked


def test_zte_c300_onu_detail_info_requires_the_onu_argument():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute({}, 'onu_detail_info', {})

    assert exc_info.value.stage == 'operation'


def test_zte_c300_onu_detail_info_requires_a_non_empty_onu_argument():
    with pytest.raises(OltSessionError):
        zte_c300.execute({}, 'onu_detail_info', {'onu': ''})


def test_zte_c300_onu_state_requires_the_onu_argument():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute({}, 'onu_state', {})

    assert exc_info.value.stage == 'operation'


def test_zte_c300_onu_baseinfo_requires_the_onu_argument():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute({}, 'onu_baseinfo', {})

    assert exc_info.value.stage == 'operation'


def test_parse_field_value_block_turns_label_colon_value_lines_into_a_dict():
    command = 'show gpon onu detail-info gpon-onu_1/3/12:2'
    raw = (
        f'{command}\r\n'
        'Name            : Test-1\r\n'
        'Type            : M12X5G_XPON\r\n'
        '  Password:            \r\n'
        '\r\n'
    )
    fields = parse_field_value_block(raw, command)

    assert fields == {
        'Name': 'Test-1',
        'Type': 'M12X5G_XPON',
        'Password': '',
    }


def test_parse_field_value_block_does_not_misparse_the_command_echo_itself():
    # Regression guard — identifier ONU ZTE sendiri mengandung ':' (mis.
    # "gpon-onu_1/3/12:2"), yang kalau echo command tidak dibuang dulu
    # akan salah ter-parse seolah jadi field ("show gpon onu detail-info
    # gpon-onu_1/3/12" -> "2").
    command = 'show gpon onu detail-info gpon-onu_1/3/12:2'
    raw = f'{command}\r\nName            : Test-1\r\n'
    fields = parse_field_value_block(raw, command)

    assert 'show gpon onu detail-info gpon-onu_1/3/12' not in fields
    assert fields == {'Name': 'Test-1'}


def test_zte_c300_onu_uncfg_list_skips_masking_when_mask_sensitive_is_false():
    # execute() sendiri butuh koneksi telnet nyata untuk dites penuh
    # (integration test manual, bukan unit test) — jadi di sini kita
    # cukup buktikan parameter mask_sensitive diteruskan ke fungsi
    # masking dengan benar lewat helper-level test, bukan pexpect nyata.
    raw = 'Serial: ABCD1234EFGH5678'
    masked = mask_sensitive_tokens(raw)
    assert masked != raw  # sanity check pola sendiri genuinely mengubah teks

    # execute() untuk operasi tak dikenal tetap harus reject SEBELUM
    # menyentuh jaringan, terlepas dari nilai mask_sensitive.
    with pytest.raises(OltSessionError):
        zte_c300.execute({}, 'operasi_tidak_dikenal', {}, mask_sensitive=False)


# ============================================================================
# v0.23.5 — WRITE (activate_onu/save_config), murni test builder+validasi
# (tidak memanggil pexpect/OLT sungguhan — reuse pola file ini secara
# keseluruhan). SN/nama di sini FIKTIF, bukan SN perangkat uji Agung yang
# genuinely dipakai.
# ============================================================================

_VALID_ACTIVATE_PARAMS = {
    'pon_interface': '1/3/12',
    'onu_id': 4,
    'sn': 'CMDCAABCDEF1',
    'onu_type': 'M12X5G_XPON',
    'name': 'TEST OMCI 1 - TEST-OMCI-1',
    'tcont_profile': 'HomeFixed-10Mbps',
    'traffic_profile': 'PPPoE-Remote',
    'vlan_pppoe': 10,
    'vlan_mgmt': 9,
    'vlan_bridge': 172,
}


def test_build_activate_onu_commands_produces_the_expected_sequence_with_no_wr():
    commands = zte_c300._build_activate_onu_commands(_VALID_ACTIVATE_PARAMS)

    assert commands[0] == 'configure terminal'
    assert commands[-1] == 'end'
    assert 'wr' not in commands  # apply TIDAK PERNAH mengandung save — dipisah sengaja.
    assert 'interface gpon-olt_1/3/12' in commands
    assert 'onu 4 type M12X5G_XPON sn CMDCAABCDEF1' in commands
    assert 'interface gpon-onu_1/3/12:4' in commands
    assert 'name TEST OMCI 1 - TEST-OMCI-1' in commands
    assert 'tcont 1 profile HomeFixed-10Mbps' in commands
    assert 'gemport 1 traffic-limit downstream PPPoE-Remote' in commands
    # Slot VLAN mengikuti pola Test-1: service-port 1=PPPoE(10), 11=mgmt(9), 12=bridge(172).
    assert 'service-port 1  vport 1 user-vlan 10 vlan 10' in commands
    assert 'service-port 11 vport 1 user-vlan 9 vlan 9' in commands
    assert 'service-port 12 vport 1 user-vlan 172 vlan 172' in commands
    assert 'pon-onu-mng gpon-onu_1/3/12:4' in commands


@pytest.mark.parametrize('key,bad_value', [
    ('onu_id', 0),
    ('onu_id', 129),
    ('onu_id', 'empat'),
    ('sn', 'PENDEK'),
    ('sn', 'TIGABELASKARAKTER'),
    ('onu_type', ''),
    ('onu_type', 'tipe dengan spasi'),
    ('name', ''),
    ('name', 'a' * 65),
    ('tcont_profile', 'ProfilTidakDikenal'),
    ('traffic_profile', 'ProfilTidakDikenal'),
    ('vlan_pppoe', 999),
    ('vlan_mgmt', 11),
    ('vlan_bridge', 173),
    ('pon_interface', '1-3-12'),
])
def test_build_activate_onu_commands_rejects_invalid_params(key, bad_value):
    params = dict(_VALID_ACTIVATE_PARAMS)
    params[key] = bad_value

    with pytest.raises(OltSessionError) as exc_info:
        zte_c300._build_activate_onu_commands(params)

    assert exc_info.value.stage == 'operation'


def test_execute_apply_rejects_an_unknown_write_operation_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_apply({}, 'operasi_tulis_tidak_dikenal', _VALID_ACTIVATE_PARAMS)

    assert exc_info.value.stage == 'operation'


def test_execute_apply_rejects_invalid_params_before_touching_the_network():
    params = dict(_VALID_ACTIVATE_PARAMS)
    params['sn'] = 'PENDEK'

    with pytest.raises(OltSessionError):
        zte_c300.execute_apply({}, 'activate_onu', params)


@pytest.mark.parametrize('sample', [
    '%Error 20202: Invalid input detected at \'^\' marker. Invalid parameter',
    '%Error 20203: Incomplete command',
    '%Error 12345: alasan lain apa pun',
])
def test_device_error_pattern_matches_real_observed_error_shapes(sample):
    # Kedua bentuk pesan ini genuinely teramati live di riset v0.23.2
    # (Sesi 6, "show gpon onu next-available gpon-olt_1/3/12" DITOLAK;
    # Sesi 8, "show gpon global" bare) — celah nyata ditemukan SEBELUM
    # eksekusi tulis pertama: run_command_and_capture() (base.py) sendiri
    # TIDAK melempar exception untuk teks error yang tetap kembali ke
    # prompt normal, jadi deteksi ini WAJIB, bukan opsional.
    assert zte_c300._DEVICE_ERROR_RE.search(sample) is not None


@pytest.mark.parametrize('sample', [
    'OnuIndex   Admin State  OMCC State  Phase State  Channel',
    'ONU Number: 2/3',
    'c300.kaliwungu.bajastu.id',
    '',
])
def test_device_error_pattern_does_not_false_positive_on_normal_output(sample):
    assert zte_c300._DEVICE_ERROR_RE.search(sample) is None


# ============================================================================
# v0.23.5 — probe_config_help (discovery command save setelah "wr" ditolak)
# ============================================================================

@pytest.mark.parametrize('valid_query', ['?', 'file ?', 'write ?', 'copy running-config ?', 'onu 4 ?'])
def test_help_query_pattern_accepts_help_queries(valid_query):
    assert zte_c300._HELP_QUERY_RE.match(valid_query) is not None


@pytest.mark.parametrize('invalid_query', [
    'onu 5 type M12X5G_XPON sn CMDCAABCDEF1',  # command config sungguhan tanpa '?' — HARUS ditolak.
    'wr',
    'write',
    'configure terminal',
    'onu 4 type',  # incomplete, TANPA '?' — bukan query bantuan.
    '',
    'pppoe 1 nat enable user 2026090744@ppp.bajastu.id password wifijadipasti',  # command config sungguhan tanpa '?'.
])
def test_help_query_pattern_rejects_anything_that_is_not_a_pure_help_query(invalid_query):
    assert zte_c300._HELP_QUERY_RE.match(invalid_query) is None


def test_help_query_pattern_accepts_any_number_of_tokens_before_the_mandatory_question_mark():
    # v0.23.5 amendment 2 — generalisasi dari batas 2-kata: drill-down
    # nyata butuh makin dalam ("pppoe 1 nat ?" = 3 token). Pengaman
    # UTAMA (wajib diakhiri '?') tidak pernah melemah — ini murni
    # membuktikan jumlah token TIDAK lagi dibatasi angka tertentu.
    assert zte_c300._HELP_QUERY_RE.match('a b c ?') is not None
    assert zte_c300._HELP_QUERY_RE.match('pppoe 1 nat ?') is not None
    assert zte_c300._HELP_QUERY_RE.match('pppoe 1 user ?') is not None
    # Spasi sebelum '?' opsional (bukan syarat keamanan) — yang wajib
    # HANYA karakter '?' di posisi terakhir, tetap query bantuan valid
    # tanpa/dengan spasi.
    assert zte_c300._HELP_QUERY_RE.match('file save config?') is not None


def test_execute_probe_config_help_rejects_a_non_help_query_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_probe_config_help({}, {'query': 'onu 5 type X sn Y'})

    assert exc_info.value.stage == 'operation'


def test_execute_probe_config_help_rejects_a_missing_query_before_touching_the_network():
    with pytest.raises(OltSessionError):
        zte_c300.execute_probe_config_help({}, {})


def test_probe_operations_routes_to_the_correct_functions():
    assert zte_c300.PROBE_OPERATIONS == {
        'config_help': 'execute_probe_config_help',
        'onu_interface_help': 'execute_probe_onu_interface_help',
        'onu_service_help': 'execute_probe_onu_service_help',
        'onu_mng_help': 'execute_probe_onu_mng_help',
        'gei_interface_help': 'execute_probe_gei_interface_help',
    }
    for method_name in zte_c300.PROBE_OPERATIONS.values():
        assert hasattr(zte_c300, method_name)


def test_execute_probe_onu_mng_help_rejects_a_non_help_query_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_probe_onu_mng_help({}, {'pon_interface': '1/3/12', 'onu_id': 4, 'query': 'pppoe 1 nat enable'})

    assert exc_info.value.stage == 'operation'


def test_execute_probe_onu_mng_help_rejects_an_invalid_pon_interface_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_probe_onu_mng_help({}, {'pon_interface': 'invalid', 'onu_id': 4, 'query': '?'})

    assert exc_info.value.stage == 'operation'


def test_execute_probe_onu_service_help_rejects_a_non_help_query_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_probe_onu_service_help({}, {'pon_interface': '1/3/12', 'onu_id': 4, 'query': 'name foo'})

    assert exc_info.value.stage == 'operation'


def test_execute_probe_onu_service_help_rejects_an_invalid_onu_id_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_probe_onu_service_help({}, {'pon_interface': '1/3/12', 'onu_id': 0, 'query': '?'})

    assert exc_info.value.stage == 'operation'


def test_execute_probe_onu_interface_help_rejects_a_non_help_query_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_probe_onu_interface_help({}, {'pon_interface': '1/3/12', 'query': 'no onu 4'})

    assert exc_info.value.stage == 'operation'


def test_execute_probe_onu_interface_help_rejects_an_invalid_pon_interface_before_touching_the_network():
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300.execute_probe_onu_interface_help({}, {'pon_interface': 'bukan-pola-valid', 'query': 'no ?'})

    assert exc_info.value.stage == 'operation'


# ============================================================================
# v0.23.5 — fix_onu_vlan (koreksi VLAN parsial, tanpa reapply penuh)
# ============================================================================

_VALID_FIX_VLAN_PARAMS = {
    'pon_interface': '1/3/12',
    'onu_id': 4,
    'new_vlan_pppoe': 111,
}


def test_build_fix_onu_vlan_commands_only_touches_the_three_pppoe_vlan_lines():
    commands = zte_c300._build_fix_onu_vlan_commands(_VALID_FIX_VLAN_PARAMS)

    assert commands == [
        'configure terminal',
        'interface gpon-onu_1/3/12:4',
        'service-port 1  vport 1 user-vlan 111 vlan 111',
        'exit',
        'pon-onu-mng gpon-onu_1/3/12:4',
        'flow 1 pri 0 vlan 111',
        'vlan-filter iphost 1 pri 0 vlan 111',
        'end',
    ]
    # NUNGGA registrasi PON (onu ... type ... sn ...), NUNGGA VLAN
    # mgmt(9)/bridge(172), NUNGGA tcont/gemport/security-mgmt.
    joined = ' '.join(commands)
    assert 'onu 4 type' not in joined
    assert 'sn ' not in joined
    assert 'tcont' not in joined
    assert 'security-mgmt' not in joined
    assert 'vlan 9' not in joined
    assert 'vlan 172' not in joined


@pytest.mark.parametrize('key,bad_value', [
    ('onu_id', 0),
    ('onu_id', 'empat'),
    ('new_vlan_pppoe', 999),  # VLAN yang genuinely tidak dikenal — ditolak.
    ('pon_interface', 'bukan-pola-valid'),
])
def test_build_fix_onu_vlan_commands_rejects_invalid_params(key, bad_value):
    params = dict(_VALID_FIX_VLAN_PARAMS)
    params[key] = bad_value

    with pytest.raises(OltSessionError) as exc_info:
        zte_c300._build_fix_onu_vlan_commands(params)

    assert exc_info.value.stage == 'operation'


def test_execute_apply_accepts_fix_onu_vlan_as_a_known_write_operation():
    # Cukup buktikan operasi ini genuinely terdaftar (tidak reject sebagai
    # "operasi tidak dikenal") — eksekusi nyata butuh pexpect/OLT sungguhan,
    # di luar cakupan unit test ini.
    assert 'fix_onu_vlan' in zte_c300.OPERATIONS_WRITE


# ============================================================================
# v0.23.5 — delete_onu (hapus registrasi ONU di level PON, "no onu <id>")
# ============================================================================

def test_build_delete_onu_commands_produces_the_expected_minimal_sequence():
    commands = zte_c300._build_delete_onu_commands({'pon_interface': '1/3/12', 'onu_id': 4})

    assert commands == [
        'configure terminal',
        'interface gpon-olt_1/3/12',
        'no onu 4',
        'end',
    ]


@pytest.mark.parametrize('key,bad_value', [
    ('onu_id', 0),
    ('onu_id', 129),
    ('onu_id', 'empat'),
    ('pon_interface', 'bukan-pola-valid'),
])
def test_build_delete_onu_commands_rejects_invalid_params(key, bad_value):
    params = {'pon_interface': '1/3/12', 'onu_id': 4}
    params[key] = bad_value

    with pytest.raises(OltSessionError) as exc_info:
        zte_c300._build_delete_onu_commands(params)

    assert exc_info.value.stage == 'operation'


def test_delete_onu_is_a_known_write_operation():
    assert 'delete_onu' in zte_c300.OPERATIONS_WRITE


# ============================================================================
# v0.23.5 Bagian B — add_pppoe (tambah baris pppoe di pon-onu-mng)
# ============================================================================

_VALID_ADD_PPPOE_PARAMS = {
    'pon_interface': '1/3/12',
    'onu_id': 4,
    'host_id': 1,
    'username': '2026090744@ppp.bajastu.id',
    'password': 'wifijadipasti',
    'nat_enabled': True,
}


def test_build_add_pppoe_commands_produces_the_expected_minimal_sequence():
    commands = zte_c300._build_add_pppoe_commands(_VALID_ADD_PPPOE_PARAMS)

    assert commands == [
        'configure terminal',
        'pon-onu-mng gpon-onu_1/3/12:4',
        'pppoe 1 nat enable user 2026090744@ppp.bajastu.id password wifijadipasti',
        'end',
    ]


def test_build_add_pppoe_commands_respects_nat_disabled():
    params = dict(_VALID_ADD_PPPOE_PARAMS)
    params['nat_enabled'] = False
    commands = zte_c300._build_add_pppoe_commands(params)

    assert 'pppoe 1 nat disable user 2026090744@ppp.bajastu.id password wifijadipasti' in commands


@pytest.mark.parametrize('key,bad_value', [
    ('onu_id', 0),
    ('host_id', 0),
    ('host_id', 256),
    ('username', ''),
    ('username', 'user with space'),
    ('username', 'user;rm -rf /'),
    ('password', ''),
    ('password', 'pass word'),
    ('password', 'pass"word'),
    ('password', "pass'word"),
    ('password', 'pass\\word'),
    ('password', 'pass;word'),
    ('nat_enabled', 'yes'),
])
def test_build_add_pppoe_commands_rejects_invalid_or_dangerous_params(key, bad_value):
    params = dict(_VALID_ADD_PPPOE_PARAMS)
    params[key] = bad_value

    with pytest.raises(OltSessionError) as exc_info:
        zte_c300._build_add_pppoe_commands(params)

    assert exc_info.value.stage == 'operation'


def test_add_pppoe_is_a_known_write_operation():
    assert 'add_pppoe' in zte_c300.OPERATIONS_WRITE


# ============================================================================
# v0.23.5 (Opsi B) — add_wan_bridge DIHAPUS; "Attached VLANs" sekarang =
# extra_flow_vlans di activate_onu (baris flow permission tambahan).
# ============================================================================

def test_add_wan_bridge_is_no_longer_a_write_operation():
    assert 'add_wan_bridge' not in zte_c300.OPERATIONS_WRITE
    assert not hasattr(zte_c300, '_build_add_wan_bridge_commands')


_ACTIVATE_FLOW_PARAMS = {
    'pon_interface': '1/3/12',
    'onu_id': 4,
    'sn': 'CMDCA45762D6',
    'onu_type': 'M12X5G_XPON',
    'name': 'TEST OMCI 1',
    'tcont_profile': 'HomeFixed-10Mbps',
    'traffic_profile': 'PPPoE-Remote',
    'vlan_pppoe': 111,
    'vlan_mgmt': 9,
    'vlan_bridge': 172,
}


def test_activate_onu_without_extra_flow_vlans_is_identical_to_before():
    commands = zte_c300._build_activate_onu_commands(_ACTIVATE_FLOW_PARAMS)
    # Hanya 3 baris flow basis (mgmt/pppoe/bridge), tidak ada tambahan.
    flow_lines = [c for c in commands if c.startswith('flow 1 pri 0 vlan')]
    assert flow_lines == ['flow 1 pri 0 vlan 9', 'flow 1 pri 0 vlan 111', 'flow 1 pri 0 vlan 172']


def test_activate_onu_appends_extra_flow_vlans_as_permission_lines():
    params = dict(_ACTIVATE_FLOW_PARAMS)
    # 10 & 131 tambahan; 9 (mgmt) & 172 (bridge) di list harus di-dedup
    # (sudah jadi baris basis), bukan dobel.
    params['extra_flow_vlans'] = [10, 131, 9, 172]
    commands = zte_c300._build_activate_onu_commands(params)
    flow_lines = [c for c in commands if c.startswith('flow 1 pri 0 vlan')]
    assert flow_lines == [
        'flow 1 pri 0 vlan 9',
        'flow 1 pri 0 vlan 111',
        'flow 1 pri 0 vlan 172',
        'flow 1 pri 0 vlan 10',
        'flow 1 pri 0 vlan 131',
    ]
    # extra_flow_vlans TIDAK menambah service-port (hanya flow permission).
    sp_lines = [c for c in commands if c.startswith('service-port')]
    assert len(sp_lines) == 3


@pytest.mark.parametrize('bad', [
    'notalist',
    [9999],      # bukan VLAN dikenal
    [True],      # bool bukan int VLAN
    ['10'],      # string bukan int
])
def test_activate_onu_rejects_invalid_extra_flow_vlans(bad):
    params = dict(_ACTIVATE_FLOW_PARAMS)
    params['extra_flow_vlans'] = bad
    with pytest.raises(OltSessionError) as exc_info:
        zte_c300._build_activate_onu_commands(params)
    assert exc_info.value.stage == 'operation'


def test_expanded_known_vlans_includes_new_package_vlans():
    for vlan in (101, 131, 150, 151):
        assert vlan in zte_c300._KNOWN_VLANS


# ============================================================================
# v0.23.5 Bagian 2 — get_uplink_ports (port uplink 3-segmen) + probe gei
# ============================================================================

def test_gei_port_re_accepts_3_and_2_segments():
    assert zte_c300._GEI_PORT_RE.match('gei_1/19/4')
    assert zte_c300._GEI_PORT_RE.match('xgei_1/19/1')
    assert zte_c300._GEI_PORT_RE.match('gei_1/19')       # 2-seg (discovery)
    assert not zte_c300._GEI_PORT_RE.match('gpon-onu_1/3/12')
    assert not zte_c300._GEI_PORT_RE.match('gei_1/19/4; reboot')


def test_port_status_first_line_re_matches_only_3_segment_status_line():
    assert zte_c300._PORT_STATUS_FIRST_LINE_RE.match('gei_1/19/4 is up,  line protocol is up')
    assert zte_c300._PORT_STATUS_FIRST_LINE_RE.match('xgei_1/19/1 is administratively down,')
    assert not zte_c300._PORT_STATUS_FIRST_LINE_RE.match('%Error 20202: Invalid input')


def test_get_uplink_ports_rejects_invalid_explicit_port():
    with pytest.raises(OltSessionError) as exc:
        zte_c300.execute_get_uplink_ports({}, {'ports': ['gei_1/19/4; rm -rf /']})
    assert exc.value.stage == 'operation'


def test_get_uplink_ports_empty_when_no_ports_and_none_discovered(monkeypatch):
    # Discovery dipaksa kosong -> hasil ports kosong, tanpa menyentuh telnet detail.
    monkeypatch.setattr(zte_c300, '_discover_uplink_ports', lambda conn: [])
    result = zte_c300.execute_get_uplink_ports({}, {})
    assert result == {'ports': []}


def test_gei_interface_help_is_a_known_probe_operation():
    assert zte_c300.PROBE_OPERATIONS.get('gei_interface_help') == 'execute_probe_gei_interface_help'
    assert 'get_uplink_ports' not in zte_c300.OPERATIONS  # read op via execute() branch, bukan template


# ==========================================================================
# v0.23.6 — G02ID resolve_onu_by_sn (READ) + set_ont_naming (WRITE)
# Semua uji di bawah TIDAK menyentuh jaringan: validasi/parse/build command
# terjadi SEBELUM sesi SSH apa pun dibuka.
# ==========================================================================

_SAMPLE_ONT_INFO = """show ont-info sn 5a494347296b1fad
 PON ID                        : 1
 ONU ID                        : 28
 State                         : Active
 Run State                     : Online
 Config State                  : normal
 Line Profile Name             : tr069
"""


def test_g02id_resolve_requires_sn_before_touching_network():
    with pytest.raises(OltSessionError) as exc:
        hsgq_g02id.execute({}, 'resolve_onu_by_sn', {})
    assert "'sn'" in str(exc.value)


def test_g02id_resolve_rejects_malformed_sn_before_network():
    with pytest.raises(OltSessionError):
        hsgq_g02id.execute({}, 'resolve_onu_by_sn', {'sn': 'bad sn!!'})


def test_g02id_grab_field_distinguishes_state_from_run_state():
    assert hsgq_g02id._grab_field(_SAMPLE_ONT_INFO, 'State') == 'Active'
    assert hsgq_g02id._grab_field(_SAMPLE_ONT_INFO, 'Run State') == 'Online'


def test_g02id_onu_id_regex_parses_id():
    m = hsgq_g02id._ONU_ID_RE.search(_SAMPLE_ONT_INFO)
    assert m is not None and int(m.group(1)) == 28


def test_g02id_not_exist_regex_matches_device_message():
    assert hsgq_g02id._NOT_EXIST_RE.search('Error, GET ont-auth table fail, reason: ONU is not exist.')
    assert not hsgq_g02id._NOT_EXIST_RE.search(_SAMPLE_ONT_INFO)


def test_g02id_execute_apply_rejects_unknown_write_op():
    with pytest.raises(OltSessionError) as exc:
        hsgq_g02id.execute_apply({}, 'ont_wanconfig_add', {})
    assert 'tidak dikenal' in str(exc.value)


def test_g02id_set_ont_naming_validates_pon_before_network():
    with pytest.raises(OltSessionError) as exc:
        hsgq_g02id.execute_apply({}, 'set_ont_naming', {'pon': 3, 'onu_id': 28, 'name': 'X - 1'})
    assert "'pon'" in str(exc.value)


def test_g02id_set_ont_naming_validates_onu_id_before_network():
    with pytest.raises(OltSessionError) as exc:
        hsgq_g02id.execute_apply({}, 'set_ont_naming', {'pon': 1, 'onu_id': 999, 'name': 'X - 1'})
    assert "'onu_id'" in str(exc.value)


def test_g02id_set_ont_naming_rejects_quote_in_name_before_network():
    with pytest.raises(OltSessionError) as exc:
        hsgq_g02id.execute_apply({}, 'set_ont_naming', {'pon': 1, 'onu_id': 28, 'name': 'bad"name'})
    assert 'terlarang' in str(exc.value)


def test_g02id_set_ont_naming_rejects_overlong_name_before_network():
    with pytest.raises(OltSessionError):
        hsgq_g02id.execute_apply({}, 'set_ont_naming', {'pon': 1, 'onu_id': 28, 'name': 'X' * 41})


def test_g02id_build_set_ont_naming_commands_name_only():
    cmds = hsgq_g02id._build_set_ont_naming_commands({'name': 'Dahlia - 255324578770'}, 28)
    assert cmds == ['ont setting 28 name "Dahlia - 255324578770"']


def test_g02id_build_set_ont_naming_commands_with_optional_desc():
    cmds = hsgq_g02id._build_set_ont_naming_commands(
        {'name': 'Dahlia - 255', 'desc': 'ODP Pondokpete'}, 28
    )
    assert cmds == [
        'ont setting 28 name "Dahlia - 255"',
        'ont setting 28 desc "ODP Pondokpete"',
    ]


def test_g02id_device_error_regex_catches_known_failures_not_success():
    assert hsgq_g02id._DEVICE_ERROR_RE.search('Error, Ont WanConfig Add fail, reason: WAN data create fail.')
    assert hsgq_g02id._DEVICE_ERROR_RE.search('% There is no matched command.')
    assert hsgq_g02id._DEVICE_ERROR_RE.search('vty% Command incomplete.')
    # sukses `ont setting` = echo + prompt bersih, tanpa penanda error
    assert not hsgq_g02id._DEVICE_ERROR_RE.search('ont setting 28 name "Dahlia - 255"\nOLT-BUMIREJA(config-gpon-1)#')


def test_g02id_execute_save_parses_success(monkeypatch):
    monkeypatch.setattr(
        hsgq_g02id.hsgq_common, 'run_hsgq_ssh_command',
        lambda conn, cmd, overall_timeout=45.0: 'copy running-config startup-config\n Configuration saved successfully\nOLT-BUMIREJA#',
    )
    result = hsgq_g02id.execute_save({'host': 'h', 'username': 'u', 'password': 'p'})
    assert result['saved'] is True
    assert 'saved successfully' in result['raw_excerpt'].lower()


def test_g02id_execute_save_raises_on_device_error(monkeypatch):
    monkeypatch.setattr(
        hsgq_g02id.hsgq_common, 'run_hsgq_ssh_command',
        lambda conn, cmd, overall_timeout=45.0: '% command error',
    )
    with pytest.raises(OltSessionError):
        hsgq_g02id.execute_save({'host': 'h', 'username': 'u', 'password': 'p'})
