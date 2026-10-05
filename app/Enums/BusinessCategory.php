<?php

namespace App\Enums;

/**
 * Provider-independent business categories.
 *
 * Each discovery provider is responsible for translating these categories
 * into its own query vocabulary (e.g. OpenStreetMap tags).
 */
enum BusinessCategory: string
{
    case Restaurant = 'restaurant';
    case Cafe = 'cafe';
    case Bakery = 'bakery';
    case Hotel = 'hotel';
    case Clinic = 'clinic';
    case Dentist = 'dentist';
    case Pharmacy = 'pharmacy';
    case Beauty = 'beauty';
    case Fitness = 'fitness';
    case RealEstate = 'real_estate';
    case CarDealer = 'car_dealer';
    case CarServices = 'car_services';
    case CarRental = 'car_rental';
    case Education = 'education';
    case Kindergarten = 'kindergarten';
    case Legal = 'legal';
    case Accounting = 'accounting';
    case Travel = 'travel';
    case Furniture = 'furniture';
    case Fashion = 'fashion';
    case Jewelry = 'jewelry';
    case Electronics = 'electronics';
    case Grocery = 'grocery';
    case Florist = 'florist';
    case Laundry = 'laundry';
    case Optician = 'optician';
    case Veterinary = 'veterinary';
    case Events = 'events';
    case Contractors = 'contractors';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => 'Restaurants & fast food',
            self::Cafe => 'Cafés & coffee shops',
            self::Bakery => 'Bakeries & sweets',
            self::Hotel => 'Hotels & accommodation',
            self::Clinic => 'Clinics & medical centres',
            self::Dentist => 'Dentists',
            self::Pharmacy => 'Pharmacies',
            self::Beauty => 'Beauty salons & barbers',
            self::Fitness => 'Gyms & fitness centres',
            self::RealEstate => 'Real estate agencies',
            self::CarDealer => 'Car dealers',
            self::CarServices => 'Car repair & services',
            self::CarRental => 'Car rental',
            self::Education => 'Schools & training centres',
            self::Kindergarten => 'Kindergartens & childcare',
            self::Legal => 'Lawyers & legal services',
            self::Accounting => 'Accountants & tax advisors',
            self::Travel => 'Travel agencies',
            self::Furniture => 'Furniture & home décor',
            self::Fashion => 'Clothing & fashion',
            self::Jewelry => 'Jewellery & watches',
            self::Electronics => 'Electronics & mobile shops',
            self::Grocery => 'Supermarkets & groceries',
            self::Florist => 'Florists & gift shops',
            self::Laundry => 'Laundry & dry cleaning',
            self::Optician => 'Opticians',
            self::Veterinary => 'Veterinary & pet shops',
            self::Events => 'Event venues & halls',
            self::Contractors => 'Contractors & trades',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            $options[$category->value] = $category->label();
        }

        return $options;
    }
}
