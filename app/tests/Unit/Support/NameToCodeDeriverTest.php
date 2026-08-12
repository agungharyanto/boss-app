<?php

namespace Tests\Unit\Support;

use App\Support\NameToCodeDeriver;
use Tests\TestCase;

class NameToCodeDeriverTest extends TestCase
{
    public function test_derives_initials_of_each_word(): void
    {
        $this->assertSame('BTW', NameToCodeDeriver::derive('Bajastu Teknologi Waringin'));
    }

    public function test_single_word_yields_a_single_letter(): void
    {
        $this->assertSame('W', NameToCodeDeriver::derive('Walker'));
    }

    public function test_three_word_name(): void
    {
        $this->assertSame('IDS', NameToCodeDeriver::derive('ISP Demo Solo'));
    }

    public function test_extra_whitespace_is_ignored(): void
    {
        $this->assertSame('ID', NameToCodeDeriver::derive('  ISP   Demo  '));
    }

    public function test_empty_name_yields_an_empty_string(): void
    {
        $this->assertSame('', NameToCodeDeriver::derive(''));
        $this->assertSame('', NameToCodeDeriver::derive('   '));
    }

    public function test_no_stopword_filtering(): void
    {
        // Deliberately not smart about this — every word counts, including
        // short/common ones.
        $this->assertSame('TDR', NameToCodeDeriver::derive('The Dan Reseller'));
    }

    public function test_derive_unique_returns_the_base_code_when_free(): void
    {
        $code = NameToCodeDeriver::deriveUnique('Bajastu Teknologi Waringin', fn () => false);

        $this->assertSame('BTW', $code);
    }

    public function test_derive_unique_appends_a_numeric_suffix_on_collision(): void
    {
        $taken = ['BTW', 'BTW2', 'BTW3'];

        $code = NameToCodeDeriver::deriveUnique(
            'Bajastu Teknologi Waringin',
            fn (string $candidate) => in_array($candidate, $taken, true)
        );

        $this->assertSame('BTW4', $code);
    }

    public function test_derive_unique_returns_null_for_a_blank_name(): void
    {
        $this->assertNull(NameToCodeDeriver::deriveUnique('   ', fn () => false));
    }
}
