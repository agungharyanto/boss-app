<?php

namespace Tests\Feature\Network;

use App\Services\Network\OltUplinkPortParser;
use PHPUnit\Framework\TestCase;

/**
 * v0.23.5 Bagian 2 — parser murni, sampel = output NYATA dari
 * test-x86-bajastu C300 (gei_1/19/4 up + optik fiber, xgei down).
 */
class OltUplinkPortParserTest extends TestCase
{
    public function test_parses_an_up_fiber_port_with_optical_and_vlan(): void
    {
        $port = [
            'name' => 'gei_1/19/4',
            'is_10g' => false,
            'port_status_lines' => [
                'gei_1/19/4 is up,  line protocol is up,  detect status is OK',
                'Description is none',
                'The port negotiation is enable',
                'The port is optical',
                'Duplex full',
            ],
            'optical_lines' => [
                'Optical module information:gei_1/19/4',
                'Wavelength     : 1310      (nm)           Connector      : LC',
                'Module-Type    : 1000BASE-LX',
                'RxPower        : -4.812                   TxPower	  : -3.100',
                'Temperature    : 42.50                    Supply-Vol   : 3.3',
            ],
            'vlan_lines' => [
                'PortMode      Pvid  CPvid Tpid/mode   TLSStatus TLSVlan  ProtEn   PrioEn',
                'hybrid>=0     1     0     0x8100/PORT disable   0        disable  disable',
                'UntaggedVlan:',
                '1',
                'TaggedVlan:',
                '9-10,69,101,110-111,120,130-131,140,151,172,251',
                'c300.kaliwungu.bajastu.id',
            ],
        ];

        $r = OltUplinkPortParser::parse($port);

        $this->assertSame('gei_1/19/4', $r['name']);
        $this->assertSame('up', $r['admin_state']);
        $this->assertSame('up', $r['oper_status']);
        $this->assertSame('up', $r['line_protocol']);
        $this->assertNull($r['description']);          // "none" -> null
        $this->assertSame('enable', $r['negotiation']);
        $this->assertSame('optical', $r['port_type']);
        $this->assertSame('full', $r['duplex']);
        $this->assertSame(1310, $r['wavelength_nm']);
        $this->assertSame(-4.812, $r['rx_power_dbm']);
        $this->assertSame(-3.1, $r['tx_power_dbm']);
        $this->assertSame(42.5, $r['temperature_c']);
        $this->assertSame('1000BASE-LX', $r['module_type']);
        $this->assertSame('hybrid', $r['port_mode']);
        $this->assertSame(1, $r['pvid']);
        $this->assertSame('1', $r['untagged_vlans']);
        $this->assertSame('9-10,69,101,110-111,120,130-131,140,151,172,251', $r['tagged_vlans']);
    }

    public function test_admin_down_port_detected_and_no_vlan_value_captures_prompt(): void
    {
        $port = [
            'name' => 'gei_1/10/1',
            'is_10g' => false,
            'port_status_lines' => [
                'gei_1/10/1 is administratively down,  line protocol is down,  detect status is OK',
                'The port negotiation is disable',
                'The port is optical',
            ],
            'optical_lines' => [],
            'vlan_lines' => [
                'hybrid>=0     1     0     0x8100/PORT disable   0',
                'UntaggedVlan:',
                '1',
                'TaggedVlan:',
                'c300.kaliwungu.bajastu.id', // prompt — BUKAN daftar VLAN, harus diabaikan
            ],
        ];

        $r = OltUplinkPortParser::parse($port);

        $this->assertSame('down', $r['admin_state']);   // "administratively down"
        $this->assertSame('down', $r['oper_status']);
        $this->assertSame('1', $r['untagged_vlans']);
        $this->assertNull($r['tagged_vlans']);          // baris prompt tidak tertangkap
        $this->assertNull($r['rx_power_dbm']);          // optik kosong
    }

    public function test_handles_completely_empty_port(): void
    {
        $r = OltUplinkPortParser::parse(['name' => 'xgei_1/20/2', 'is_10g' => true]);

        $this->assertSame('xgei_1/20/2', $r['name']);
        $this->assertTrue($r['is_10g']);
        $this->assertNull($r['admin_state']);
        $this->assertNull($r['tagged_vlans']);
    }
}
