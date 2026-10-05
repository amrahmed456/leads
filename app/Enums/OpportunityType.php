<?php

namespace App\Enums;

/**
 * Services a business could be offered.
 *
 * Phase 2 detects the first eight deterministically; custom business systems
 * and mobile apps need richer signals and are reserved for later phases.
 */
enum OpportunityType: string
{
    case WebsiteMissing = 'website_missing';
    case WebsiteRedesign = 'website_redesign';
    case MobileImprovement = 'mobile_improvement';
    case OnlineBooking = 'online_booking';
    case OnlineOrdering = 'online_ordering';
    case Ecommerce = 'ecommerce';
    case WhatsappIntegration = 'whatsapp_integration';
    case MultilingualWebsite = 'multilingual_website';
    case CustomBusinessSystem = 'custom_business_system';
    case MobileApp = 'mobile_app';

    public function label(): string
    {
        return match ($this) {
            self::WebsiteMissing => 'Website development',
            self::WebsiteRedesign => 'Website redesign',
            self::MobileImprovement => 'Mobile improvement',
            self::OnlineBooking => 'Online booking',
            self::OnlineOrdering => 'Online ordering',
            self::Ecommerce => 'E-commerce',
            self::WhatsappIntegration => 'WhatsApp integration',
            self::MultilingualWebsite => 'Multilingual website',
            self::CustomBusinessSystem => 'Custom business system',
            self::MobileApp => 'Mobile app',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
