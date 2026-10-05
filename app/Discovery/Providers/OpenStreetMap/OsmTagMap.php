<?php

namespace App\Discovery\Providers\OpenStreetMap;

use App\Discovery\Exceptions\DiscoveryException;
use App\Enums\BusinessCategory;

/**
 * Translates between application business categories and OpenStreetMap tags.
 *
 * @see https://wiki.openstreetmap.org/wiki/Map_features
 */
final class OsmTagMap
{
    /** Tag keys describing what kind of business an element is, in order of preference. */
    public const BUSINESS_KEYS = ['amenity', 'shop', 'office', 'tourism', 'leisure', 'healthcare', 'craft'];

    /** @var array<string, array<string, list<string>>> category => [tag key => tag values] */
    private const CATEGORIES = [
        'restaurant' => ['amenity' => ['restaurant', 'fast_food', 'food_court']],
        'cafe' => ['amenity' => ['cafe', 'ice_cream'], 'shop' => ['coffee', 'tea']],
        'bakery' => ['shop' => ['bakery', 'pastry', 'confectionery', 'chocolate']],
        'hotel' => ['tourism' => ['hotel', 'motel', 'guest_house', 'hostel', 'apartment', 'chalet']],
        'clinic' => [
            'amenity' => ['clinic', 'doctors'],
            'healthcare' => ['clinic', 'doctor', 'centre', 'physiotherapist', 'laboratory'],
        ],
        'dentist' => ['amenity' => ['dentist'], 'healthcare' => ['dentist']],
        'pharmacy' => ['amenity' => ['pharmacy'], 'healthcare' => ['pharmacy']],
        'beauty' => ['shop' => ['beauty', 'hairdresser', 'cosmetics', 'perfumery', 'massage']],
        'fitness' => ['leisure' => ['fitness_centre', 'sports_centre']],
        'real_estate' => ['office' => ['estate_agent', 'property_management']],
        'car_dealer' => ['shop' => ['car', 'motorcycle']],
        'car_services' => ['shop' => ['car_repair', 'tyres', 'car_parts'], 'amenity' => ['car_wash']],
        'car_rental' => ['amenity' => ['car_rental']],
        'education' => [
            'amenity' => ['school', 'college', 'language_school', 'driving_school', 'music_school', 'training'],
            'office' => ['educational_institution'],
        ],
        'kindergarten' => ['amenity' => ['kindergarten', 'childcare']],
        'legal' => ['office' => ['lawyer', 'notary']],
        'accounting' => ['office' => ['accountant', 'tax_advisor']],
        'travel' => ['shop' => ['travel_agency'], 'office' => ['travel_agent']],
        'furniture' => ['shop' => ['furniture', 'interior_decoration', 'kitchen', 'bed', 'curtain', 'carpet', 'lighting']],
        'fashion' => [
            'shop' => ['clothes', 'boutique', 'shoes', 'fashion_accessories', 'bag', 'fabric', 'tailor'],
            'craft' => ['tailor', 'dressmaker'],
        ],
        'jewelry' => ['shop' => ['jewelry', 'watches']],
        'electronics' => ['shop' => ['electronics', 'mobile_phone', 'computer', 'appliance']],
        'grocery' => ['shop' => ['supermarket', 'convenience', 'greengrocer', 'butcher', 'seafood', 'deli']],
        'florist' => ['shop' => ['florist', 'gift']],
        'laundry' => ['shop' => ['laundry', 'dry_cleaning']],
        'optician' => ['shop' => ['optician']],
        'veterinary' => ['amenity' => ['veterinary'], 'shop' => ['pet']],
        'events' => ['amenity' => ['events_venue', 'conference_centre']],
        'contractors' => [
            'craft' => ['builder', 'electrician', 'plumber', 'hvac', 'carpenter', 'painter', 'tiler', 'glaziery', 'metal_construction'],
            'office' => ['construction_company'],
        ],
    ];

    /** @var array<string, list<string>> Public infrastructure that matches business keys but is never a lead. */
    private const NON_BUSINESS = [
        'amenity' => [
            'parking', 'parking_entrance', 'parking_space', 'bicycle_parking', 'motorcycle_parking',
            'place_of_worship', 'toilets', 'bench', 'waste_basket', 'waste_disposal', 'recycling',
            'drinking_water', 'water_point', 'fountain', 'shelter', 'bus_station', 'taxi', 'grave_yard',
            'post_box', 'letter_box', 'telephone', 'vending_machine', 'atm', 'clock', 'police',
            'fire_station', 'townhall', 'courthouse', 'prison', 'public_bookcase', 'bbq',
            'charging_station', 'loading_dock', 'compressed_air', 'shower',
        ],
        'leisure' => [
            'park', 'playground', 'pitch', 'garden', 'track', 'nature_reserve', 'common', 'dog_park',
            'picnic_table', 'slipway', 'swimming_area', 'outdoor_seating', 'fishing', 'bleachers', 'firepit',
        ],
        'tourism' => ['attraction', 'viewpoint', 'information', 'artwork', 'picnic_site', 'camp_pitch'],
        'office' => ['government', 'diplomatic'],
    ];

    /**
     * Tag filters (key => accepted values) that select a category.
     *
     * @return array<string, list<string>>
     */
    public function filtersFor(BusinessCategory $category): array
    {
        return self::CATEGORIES[$category->value]
            ?? throw DiscoveryException::permanent("OpenStreetMap does not support the category [{$category->value}].");
    }

    /**
     * @param  array<string, string>  $tags
     */
    public function categoryFor(array $tags): ?BusinessCategory
    {
        foreach (self::CATEGORIES as $category => $filters) {
            foreach ($filters as $key => $values) {
                if (isset($tags[$key]) && in_array($tags[$key], $values, true)) {
                    return BusinessCategory::from($category);
                }
            }
        }

        return null;
    }

    /**
     * The tag that best describes the element's business type, e.g. ['amenity', 'fast_food'].
     *
     * @param  array<string, string>  $tags
     * @return array{0: string, 1: string}|null
     */
    public function primaryTag(array $tags): ?array
    {
        foreach (self::BUSINESS_KEYS as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '' && $tags[$key] !== 'no') {
                return [$key, $tags[$key]];
            }
        }

        return null;
    }

    public function isNonBusiness(string $key, string $value): bool
    {
        return in_array($value, self::NON_BUSINESS[$key] ?? [], true);
    }
}
