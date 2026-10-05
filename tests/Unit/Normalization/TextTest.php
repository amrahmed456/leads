<?php

namespace Tests\Unit\Normalization;

use App\Support\Normalization\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    public function test_match_key_ignores_case_punctuation_and_accents(): void
    {
        $this->assertSame('cafe de paris', Text::matchKey('  Café  de PARIS! '));
        $this->assertSame(Text::matchKey('Kudu - Olaya'), Text::matchKey('KUDU (Olaya)'));
    }

    public function test_match_key_unifies_arabic_spelling_variants(): void
    {
        // Hamza forms, ta marbuta, alef maqsura, diacritics and tatweel.
        $this->assertSame(Text::matchKey('مطعم الريف'), Text::matchKey('مَطْعَم الرّيف'));
        $this->assertSame(Text::matchKey('أسواق الدانة'), Text::matchKey('اسواق الدانه'));
        $this->assertSame(Text::matchKey('مقهى'), Text::matchKey('مقهي'));
        $this->assertSame(Text::matchKey('شاورما'), Text::matchKey('شـــاورما'));
    }

    public function test_clean_collapses_whitespace_and_drops_invisible_characters(): void
    {
        $this->assertSame('Al Reef Restaurant', Text::clean("  Al\u{200F}  Reef\tRestaurant \n"));
        $this->assertNull(Text::clean("  \u{200B} "));
        $this->assertSame('abc', Text::clean('abcdef', 3));
    }

    public function test_ascii_digits_converts_arabic_indic_and_persian_digits(): void
    {
        $this->assertSame('0123456789', Text::asciiDigits('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('0123456789', Text::asciiDigits('۰۱۲۳۴۵۶۷۸۹'));
    }
}
