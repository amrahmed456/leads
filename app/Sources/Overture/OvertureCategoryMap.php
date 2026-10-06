<?php

namespace App\Sources\Overture;

use App\Enums\BusinessCategory;

/**
 * Maps Overture Places categories (primary, basic_category, taxonomy
 * hierarchy) to the application's business categories.
 *
 * @see https://docs.overturemaps.org/guides/places/
 */
final class OvertureCategoryMap
{
    /** @var array<string, string> Overture category => BusinessCategory value */
    private const EXACT = [
        'restaurant' => 'restaurant', 'fast_food_restaurant' => 'restaurant', 'food_court' => 'restaurant',
        'eat_and_drink' => 'restaurant', 'food_and_drink' => 'restaurant', 'burger_restaurant' => 'restaurant',
        'pizza_restaurant' => 'restaurant', 'sandwich_shop' => 'restaurant', 'shawarma_place' => 'restaurant',
        'cafe' => 'cafe', 'coffee_shop' => 'cafe', 'tea_room' => 'cafe', 'coffee_roastery' => 'cafe', 'ice_cream_shop' => 'cafe',
        'juice_bar' => 'cafe', 'bubble_tea' => 'cafe',
        'bakery' => 'bakery', 'patisserie' => 'bakery', 'dessert_shop' => 'bakery', 'candy_store' => 'bakery',
        'chocolatier' => 'bakery', 'cupcake_shop' => 'bakery', 'donut_shop' => 'bakery',
        'hotel' => 'hotel', 'motel' => 'hotel', 'resort' => 'hotel', 'hostel' => 'hotel', 'guest_house' => 'hotel',
        'accommodation' => 'hotel', 'serviced_apartment' => 'hotel', 'bed_and_breakfast' => 'hotel',
        'dentist' => 'dentist', 'dental_clinic' => 'dentist', 'orthodontist' => 'dentist', 'cosmetic_dentist' => 'dentist',
        'doctor' => 'clinic', 'medical_center' => 'clinic', 'clinic' => 'clinic', 'health_and_medical' => 'clinic',
        'physical_therapy' => 'clinic', 'dermatologist' => 'clinic', 'pediatrician' => 'clinic', 'medical_laboratory' => 'clinic',
        'family_practice' => 'clinic', 'diagnostic_services' => 'clinic', 'plastic_surgeon' => 'clinic',
        'pharmacy' => 'pharmacy', 'drugstore' => 'pharmacy',
        'beauty_salon' => 'beauty', 'hair_salon' => 'beauty', 'barber' => 'beauty', 'nail_salon' => 'beauty',
        'spa' => 'beauty', 'beauty_and_spa' => 'beauty', 'cosmetics_and_beauty_supply' => 'beauty', 'massage' => 'beauty',
        'gym' => 'fitness', 'fitness_trainer' => 'fitness', 'yoga_studio' => 'fitness', 'martial_arts_club' => 'fitness',
        'sports_club_and_league' => 'fitness',
        'real_estate' => 'real_estate', 'real_estate_agent' => 'real_estate', 'property_management' => 'real_estate',
        'real_estate_service' => 'real_estate',
        'car_dealer' => 'car_dealer', 'used_car_dealer' => 'car_dealer', 'motorcycle_dealer' => 'car_dealer',
        'automotive_repair' => 'car_services', 'auto_body_shop' => 'car_services', 'car_wash' => 'car_services',
        'tire_dealer_and_repair' => 'car_services', 'auto_parts_and_supply_store' => 'car_services', 'automotive_services_and_repair' => 'car_services',
        'oil_change_station' => 'car_services', 'automotive' => 'car_services',
        'car_rental_agency' => 'car_rental', 'car_rental' => 'car_rental',
        'school' => 'education', 'education' => 'education', 'language_school' => 'education', 'driving_school' => 'education',
        'tutoring_center' => 'education', 'college_university' => 'education', 'specialty_school' => 'education',
        'educational_services' => 'education', 'training_center' => 'education',
        'preschool' => 'kindergarten', 'child_care_and_day_care' => 'kindergarten', 'kindergarten' => 'kindergarten',
        'lawyer' => 'legal', 'legal_services' => 'legal', 'notary_public' => 'legal', 'law_firm' => 'legal',
        'accountant' => 'accounting', 'accounting' => 'accounting', 'tax_services' => 'accounting', 'bookkeeper' => 'accounting',
        'travel_agency' => 'travel', 'travel_services' => 'travel', 'tour_operator' => 'travel',
        'furniture_store' => 'furniture', 'home_decor' => 'furniture', 'interior_design' => 'furniture', 'kitchen_and_bath' => 'furniture',
        'mattress_store' => 'furniture', 'carpet_store' => 'furniture',
        'clothing_store' => 'fashion', 'fashion' => 'fashion', 'shoe_store' => 'fashion', 'womens_clothing_store' => 'fashion',
        'mens_clothing_store' => 'fashion', 'boutique' => 'fashion', 'tailor' => 'fashion', 'abaya_store' => 'fashion',
        'fashion_accessories_store' => 'fashion',
        'jewelry_store' => 'jewelry', 'watch_store' => 'jewelry',
        'electronics' => 'electronics', 'electronics_store' => 'electronics', 'mobile_phone_store' => 'electronics',
        'computer_store' => 'electronics', 'appliance_store' => 'electronics',
        'supermarket' => 'grocery', 'grocery_store' => 'grocery', 'convenience_store' => 'grocery', 'butcher_shop' => 'grocery',
        'fruits_and_vegetables' => 'grocery', 'seafood_market' => 'grocery',
        'florist' => 'florist', 'flowers_and_gifts_shop' => 'florist', 'gift_shop' => 'florist',
        'laundromat' => 'laundry', 'dry_cleaning' => 'laundry', 'laundry_services' => 'laundry',
        'optometrist' => 'optician', 'eyewear_and_optician' => 'optician', 'optician' => 'optician',
        'veterinarian' => 'veterinary', 'pet_store' => 'veterinary', 'pet_services' => 'veterinary',
        'event_venue' => 'events', 'wedding_venue' => 'events', 'banquet_hall' => 'events', 'event_planning' => 'events',
        'contractor' => 'contractors', 'general_contractor' => 'contractors', 'construction_services' => 'contractors',
        'plumbing' => 'contractors', 'electrician' => 'contractors', 'hvac_services' => 'contractors', 'carpenter' => 'contractors',
        'painting' => 'contractors', 'home_service' => 'contractors',
        // Names used by the current Overture taxonomy.
        'doctors_office' => 'clinic', 'outpatient_care_facility' => 'clinic', 'laboratory_testing' => 'clinic',
        'attorney_or_law_firm' => 'legal', 'eyewear_store' => 'optician', 'fashion_boutique' => 'fashion',
        'flowers_and_gifts_store' => 'florist', 'beauty_supply_store' => 'beauty', 'cosmetics_and_fragrance_store' => 'beauty',
        'party_and_event_planning' => 'events', 'travel_service' => 'travel', 'building_or_construction_service' => 'contractors',
        'dental_office' => 'dentist', 'medical_clinic' => 'clinic', 'coffee_and_tea' => 'cafe',
    ];

    /** @var array<string, string> Suffix => BusinessCategory value, for the many specific variants */
    private const SUFFIXES = [
        '_restaurant' => 'restaurant', '_cafe' => 'cafe', '_bakery' => 'bakery', '_dentist' => 'dentist',
        '_doctor' => 'clinic', '_clinic' => 'clinic', '_salon' => 'beauty', '_school' => 'education',
        '_clothing_store' => 'fashion', '_dealer' => 'car_dealer', '_contractor' => 'contractors',
    ];

    /**
     * @param  list<string>  $categories  Most specific first
     */
    public function categoryFor(array $categories): ?BusinessCategory
    {
        foreach ($categories as $category) {
            $category = strtolower(trim($category));

            if (isset(self::EXACT[$category])) {
                return BusinessCategory::from(self::EXACT[$category]);
            }
        }

        foreach ($categories as $category) {
            foreach (self::SUFFIXES as $suffix => $value) {
                if (str_ends_with(strtolower($category), $suffix)) {
                    return BusinessCategory::from($value);
                }
            }
        }

        return null;
    }
}
