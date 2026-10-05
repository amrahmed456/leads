<?php

namespace Tests\Unit\Normalization;

use App\Enums\Country;
use App\Support\Normalization\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?Country, 2: ?string}>
     */
    public static function phoneNumbers(): array
    {
        return [
            'saudi mobile, local format' => ['050 123 4567', Country::SaudiArabia, '+966501234567'],
            'saudi landline, local format' => ['011-234-5678', Country::SaudiArabia, '+966112345678'],
            'saudi, international' => ['+966 50 123 4567', Country::SaudiArabia, '+966501234567'],
            'saudi, 00 prefix' => ['00966501234567', Country::SaudiArabia, '+966501234567'],
            'saudi, code without plus' => ['966501234567', Country::SaudiArabia, '+966501234567'],
            'saudi, stray trunk prefix' => ['+966 (0) 50 123 4567', Country::SaudiArabia, '+966501234567'],
            'saudi unified number' => ['920012345', Country::SaudiArabia, '+966920012345'],
            'arabic-indic digits' => ['٠٥٠١٢٣٤٥٦٧', Country::SaudiArabia, '+966501234567'],
            'uae mobile' => ['050 123 4567', Country::UnitedArabEmirates, '+971501234567'],
            'uae dubai landline' => ['04 234 5678', Country::UnitedArabEmirates, '+97142345678'],
            'kuwait (no trunk prefix)' => ['2234 5678', Country::Kuwait, '+96522345678'],
            'several numbers: first valid wins' => ['call us; 050 123 4567; 011 234 5678', Country::SaudiArabia, '+966501234567'],
            'extension removed' => ['011 234 5678 ext. 12', Country::SaudiArabia, '+966112345678'],
            'foreign international number kept' => ['+20 2 2345 6789', Country::SaudiArabia, '+20223456789'],
            'unparseable but phone-like keeps digits' => ['1234567', Country::SaudiArabia, '1234567'],
            'garbage' => ['n/a', Country::SaudiArabia, null],
            'empty' => ['', Country::SaudiArabia, null],
            'null' => [null, Country::SaudiArabia, null],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function test_it_normalizes_phone_numbers(?string $input, ?Country $country, ?string $expected): void
    {
        $this->assertSame($expected, (new PhoneNormalizer)->normalize($input, $country));
    }

    public function test_local_numbers_need_a_country_to_become_international(): void
    {
        $this->assertSame('0501234567', (new PhoneNormalizer)->normalize('050 123 4567'));
    }
}
