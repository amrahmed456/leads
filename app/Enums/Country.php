<?php

namespace App\Enums;

/**
 * Countries supported for discovery campaigns (ISO 3166-1 alpha-2 codes).
 */
enum Country: string
{
    case SaudiArabia = 'SA';
    case UnitedArabEmirates = 'AE';
    case Qatar = 'QA';
    case Kuwait = 'KW';
    case Bahrain = 'BH';
    case Oman = 'OM';

    public function label(): string
    {
        return match ($this) {
            self::SaudiArabia => 'Saudi Arabia',
            self::UnitedArabEmirates => 'United Arab Emirates',
            self::Qatar => 'Qatar',
            self::Kuwait => 'Kuwait',
            self::Bahrain => 'Bahrain',
            self::Oman => 'Oman',
        };
    }

    /**
     * International calling code, without the leading "+".
     */
    public function callingCode(): string
    {
        return match ($this) {
            self::SaudiArabia => '966',
            self::UnitedArabEmirates => '971',
            self::Qatar => '974',
            self::Kuwait => '965',
            self::Bahrain => '973',
            self::Oman => '968',
        };
    }

    /**
     * Digit dialled before a national number inside the country, if any.
     */
    public function trunkPrefix(): ?string
    {
        return match ($this) {
            self::SaudiArabia, self::UnitedArabEmirates => '0',
            default => null,
        };
    }

    /**
     * Pattern that a national significant number (no trunk prefix) must match.
     */
    public function nationalNumberPattern(): string
    {
        return match ($this) {
            // Mobiles 5XXXXXXXX, landlines 1XXXXXXXX, unified 92XXXXXXX, toll-free 800XXXXXXX.
            self::SaudiArabia => '/^(?:[1-9]\d{8}|800\d{7})$/',
            // Mobiles 5XXXXXXXX, landlines [2-9]XXXXXXX, 600XXXXXX, toll-free 800X...
            self::UnitedArabEmirates => '/^(?:[2-9]\d{7,8}|800\d{2,9})$/',
            self::Qatar => '/^(?:[2-7]\d{7}|800\d{4})$/',
            self::Kuwait, self::Bahrain, self::Oman => '/^[1-9]\d{7}$/',
        };
    }

    /**
     * Common cities offered as suggestions in the campaign form. Any other
     * city or area name can be typed; it is geocoded at discovery time.
     *
     * @return list<string>
     */
    public function suggestedCities(): array
    {
        return match ($this) {
            self::SaudiArabia => [
                'Riyadh', 'Jeddah', 'Mecca', 'Medina', 'Dammam', 'Khobar', 'Dhahran', 'Jubail',
                'Al Ahsa', 'Taif', 'Tabuk', 'Abha', 'Khamis Mushait', 'Buraydah', 'Hail',
                'Najran', 'Jazan', 'Yanbu',
            ],
            self::UnitedArabEmirates => [
                'Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Al Ain', 'Ras Al Khaimah',
                'Fujairah', 'Umm Al Quwain',
            ],
            self::Qatar => ['Doha', 'Al Rayyan', 'Al Wakrah', 'Lusail', 'Al Khor'],
            self::Kuwait => ['Kuwait City', 'Hawalli', 'Salmiya', 'Farwaniya', 'Jahra', 'Ahmadi'],
            self::Bahrain => ['Manama', 'Muharraq', 'Riffa', 'Hamad Town', 'Isa Town'],
            self::Oman => ['Muscat', 'Seeb', 'Salalah', 'Sohar', 'Nizwa', 'Sur'],
        };
    }

    /**
     * @return array<string, string> code => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $country) {
            $options[$country->value] = $country->label();
        }

        return $options;
    }
}
