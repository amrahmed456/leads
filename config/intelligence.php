<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Category Needs
    |--------------------------------------------------------------------------
    |
    | Which online capabilities matter for each business category, and how
    | much. Missing capabilities become opportunities with that priority.
    | Keys are BusinessCategory values; capabilities are OpportunityType values.
    |
    */

    'category_needs' => [
        'restaurant' => ['online_ordering' => 'high', 'online_booking' => 'medium'],
        'cafe' => ['online_ordering' => 'high'],
        'bakery' => ['online_ordering' => 'high', 'ecommerce' => 'medium'],
        'hotel' => ['online_booking' => 'high'],
        'clinic' => ['online_booking' => 'high'],
        'dentist' => ['online_booking' => 'high'],
        'pharmacy' => ['ecommerce' => 'medium'],
        'beauty' => ['online_booking' => 'high'],
        'fitness' => ['online_booking' => 'medium'],
        'car_dealer' => ['online_booking' => 'low'],
        'car_services' => ['online_booking' => 'medium'],
        'car_rental' => ['online_booking' => 'high'],
        'legal' => ['online_booking' => 'medium'],
        'accounting' => ['online_booking' => 'low'],
        'travel' => ['online_booking' => 'high'],
        'furniture' => ['ecommerce' => 'high'],
        'fashion' => ['ecommerce' => 'high'],
        'jewelry' => ['ecommerce' => 'high'],
        'electronics' => ['ecommerce' => 'high'],
        'grocery' => ['ecommerce' => 'high'],
        'florist' => ['ecommerce' => 'high'],
        'laundry' => ['online_booking' => 'medium'],
        'optician' => ['online_booking' => 'medium', 'ecommerce' => 'medium'],
        'veterinary' => ['online_booking' => 'high'],
        'events' => ['online_booking' => 'high'],
    ],

    /*
    | Business types (as reported by the provider) for which a capability does
    | not apply even though their category needs it, e.g. fast food outlets
    | do not take table bookings.
    */

    'type_exclusions' => [
        'online_booking' => ['fast_food', 'food_court', 'ice_cream'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Category Value
    |--------------------------------------------------------------------------
    |
    | Categories that typically invest in websites, apps and systems.
    |
    */

    'category_value' => [
        'high' => [
            'clinic', 'dentist', 'real_estate', 'hotel', 'car_dealer', 'car_rental',
            'education', 'beauty', 'events', 'furniture', 'jewelry',
        ],
        'medium' => [
            'restaurant', 'cafe', 'bakery', 'fitness', 'car_services', 'legal', 'accounting',
            'travel', 'fashion', 'electronics', 'florist', 'optician', 'veterinary',
            'pharmacy', 'kindergarten', 'contractors', 'grocery', 'laundry',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Opportunity Rules
    |--------------------------------------------------------------------------
    */

    'opportunities' => [
        // An audit score below this suggests the website needs a redesign.
        'redesign_below_score' => 40,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Score
    |--------------------------------------------------------------------------
    |
    | A rule-based score from 0 to 100; every point is recorded with a reason.
    | Bump "version" when changing the rules so old scores can be identified.
    |
    */

    'lead_score' => [
        'version' => '1',

        'points' => [
            // Website status (one applies).
            'website_missing' => 40,
            'website_broken' => 30,
            'website_weak' => 15,
            'website_average' => 5,

            // Business has no website, but its category needs online booking / ordering / e-commerce.
            'latent_need_high' => 8,
            'latent_need_medium' => 4,

            // Each detected opportunity besides the website itself (capped below).
            'opportunity_high' => 10,
            'opportunity_medium' => 6,
            'opportunity_low' => 3,

            // Can we reach them?
            'phone' => 8,
            'email' => 4,
            'whatsapp' => 3,
            'social' => 5,
            'no_contact' => -15,

            // Physical presence and category value.
            'location' => 2,
            'category_high' => 10,
            'category_medium' => 5,
        ],

        'website_weak_below' => 50,
        'website_average_below' => 80,
        'opportunity_points_cap' => 25,

        'grades' => [
            'hot' => 70,
            'warm' => 45,
        ],
    ],

];
