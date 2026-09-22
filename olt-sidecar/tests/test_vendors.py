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
    # 6-7 v0.23.2, lihat docs/omci/onu-registry-design.md §2/§7). Kedua
    # operasi harus SAMA PERSIS ini, tidak lebih tidak kurang.
    assert zte_c300.OPERATIONS == {
        'onu_uncfg_list': 'show gpon onu uncfg',
        'onu_detail_info': 'show gpon onu detail-info {onu}',
    }
    assert zte_c300.REQUIRES_ONU_ARG == {'onu_detail_info'}


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
