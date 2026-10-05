<?php

namespace App\Enums;

/**
 * Deterministic signals detected on a business homepage.
 */
enum WebsiteSignal: string
{
    case Phone = 'phone';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case ContactForm = 'contact_form';
    case SocialProfiles = 'social_profiles';
    case Booking = 'booking';
    case OnlineOrdering = 'online_ordering';
    case DeliveryApps = 'delivery_apps';
    case Ecommerce = 'ecommerce';
    case Cart = 'cart';
    case MobileViewport = 'mobile_viewport';
    case ResponsiveDesign = 'responsive_design';
    case Analytics = 'analytics';
    case MobileApp = 'mobile_app';
    case OutdatedContent = 'outdated_content';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone number',
            self::Email => 'E-mail address',
            self::Whatsapp => 'WhatsApp',
            self::ContactForm => 'Contact form',
            self::SocialProfiles => 'Social profiles',
            self::Booking => 'Online booking',
            self::OnlineOrdering => 'Online ordering',
            self::DeliveryApps => 'Delivery apps',
            self::Ecommerce => 'E-commerce',
            self::Cart => 'Cart / checkout',
            self::MobileViewport => 'Mobile viewport',
            self::ResponsiveDesign => 'Responsive design',
            self::Analytics => 'Analytics / tracking',
            self::MobileApp => 'Own mobile app',
            self::OutdatedContent => 'Signs of an outdated site',
        };
    }
}
