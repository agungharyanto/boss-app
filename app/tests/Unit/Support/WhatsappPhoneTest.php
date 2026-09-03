<?php

namespace Tests\Unit\Support;

use App\Support\WhatsappPhone;
use PHPUnit\Framework\TestCase;

class WhatsappPhoneTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, WhatsappPhone::normalize($input));
    }

    public static function cases(): array
    {
        return [
            'lokal 0-prefix' => ['087884374939', '6287884374939'],
            'sudah 62' => ['6287884374939', '6287884374939'],
            'plus 62' => ['+6287884374939', '6287884374939'],
            'spasi & strip' => ['0878-8437-4939', '6287884374939'],
            'bare 8-prefix' => ['87884374939', '6287884374939'],
            'kosong' => ['', ''],
            'sudah 0 dobel dari input aneh' => ['0087884374939', '62087884374939'],
        ];
    }

    public function test_null_is_safe(): void
    {
        $this->assertSame('', WhatsappPhone::normalize(null));
    }
}
