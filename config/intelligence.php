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
    | Opportunity Rules
    |--------------------------------------------------------------------------
    */

    'opportunities' => [
        // An audit score below this suggests the website needs a redesign.
        'redesign_below_score' => 40,
    ],

    /*
    |--------------------------------------------------------------------------
    | Services We Sell
    |--------------------------------------------------------------------------
    |
    | A business can only be HOT when one of its opportunities is a service
    | we actually provide (OpportunityType values).
    |
    */

    'services_offered' => [
        'website_missing', 'website_redesign', 'mobile_improvement', 'online_booking',
        'online_ordering', 'ecommerce', 'whatsapp_integration', 'multilingual_website',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Resolution
    |--------------------------------------------------------------------------
    |
    | When two records describe the same business. Never merge on the same
    | name without a nearby location, nor on a shared phone alone: chains and
    | unrelated businesses share central numbers.
    |
    */

    'identity' => [
        'matching' => [
            'max_distance_meters' => 150,
            // Practically identical names this close are the same place.
            'same_name_distance_meters' => (int) env('DISCOVERY_DEDUPE_DISTANCE_METERS', 75),
            'same_name_similarity' => 0.9,
            // Similar names need a second identifier (phone, website or brand).
            'similar_name' => 0.75,
            // A branch of the same brand at practically the same spot.
            'same_brand_distance_meters' => 100,
            'same_brand_name_similarity' => 0.5,
            'adjacent_distance_meters' => 40,
        ],

        // Whose value is preferred when sources disagree (first wins).
        'source_priority' => ['website', 'alltheplaces', 'overture', 'openstreetmap', 'geoapify'],

        // Evidence not seen again for this long is stale.
        'stale_after_days' => 365,
        'recent_within_days' => 90,

        // Overture datasets that are really another source: facts they
        // provide are not independent of that source's group.
        'overture_provenance_groups' => [
            'alltheplaces' => 'first_party',
            'openstreetmap' => 'osm_family',
            'osm' => 'osm_family',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Classification
    |--------------------------------------------------------------------------
    |
    | Deterministic rules deciding whether a location is an independent
    | business, a chain branch, an enterprise, a government office... Weak
    | signals (a similar name, a shared phone) never classify on their own.
    |
    */

    'entity' => [
        // Entity confidence (0-100) needed before a chain/enterprise type disqualifies a lead.
        'disqualify_min_confidence' => 70,

        'branches' => [
            // Locations sharing a brand, an own website domain or an official store locator.
            'local_multi_branch_min' => 2,
            'chain_min_locations' => 10,
            'national_min_cities' => 3,
            // A website or phone shared by this many locations is a chain signal.
            'repeated_domain_min' => 3,
            'shared_phone_min' => 3,
        ],

        'brand_scope' => [
            // Locations needed (in a single country) to call a brand national rather than regional.
            'national_min_locations' => 15,
        ],

        'government' => [
            'domain_suffixes' => ['.gov.sa', '.gov.ae', '.gov.qa', '.gov.kw', '.gov.bh', '.gov.om', '.gov', '.mil', '.gov.eg', '.gov.jo'],
            'name_keywords' => [
                'ministry', 'وزارة', 'municipality', 'بلدية', 'أمانة', 'amanah', 'authority', 'هيئة', 'directorate',
                'مديرية', 'embassy', 'سفارة', 'consulate', 'قنصلية', 'police', 'شرطة', 'court', 'محكمة',
                'government', 'حكومة', 'civil defense', 'الدفاع المدني', 'passports', 'الجوازات',
            ],
            'osm_tags' => [
                'office' => ['government', 'diplomatic'],
                'amenity' => ['townhall', 'courthouse', 'police', 'embassy', 'fire_station'],
            ],
        ],

        'nonprofit' => [
            'name_keywords' => ['charity', 'charitable', 'جمعية خيرية', 'خيرية', 'nonprofit', 'non-profit', 'وقف', 'foundation'],
            'osm_tags' => ['office' => ['ngo', 'association', 'charity', 'foundation']],
        ],

        // Marketplaces and listing sites. A business whose own identity is one
        // of these is an aggregator/directory; one linking to them is not.
        'aggregators' => [
            'talabat.com' => 'Talabat', 'hungerstation.com' => 'HungerStation', 'jahez.net' => 'Jahez',
            'careem.com' => 'Careem', 'deliveroo.ae' => 'Deliveroo', 'noon.com' => 'Noon', 'zomato.com' => 'Zomato',
            'ubereats.com' => 'Uber Eats', 'mrsool.co' => 'Mrsool', 'toyou.io' => 'ToYou', 'thechefz.co' => 'The Chefz',
            'amazon.sa' => 'Amazon', 'amazon.ae' => 'Amazon', 'haraj.com.sa' => 'Haraj', 'dubizzle.com' => 'Dubizzle',
            'bayut.com' => 'Bayut', 'propertyfinder.ae' => 'Property Finder', 'aqar.fm' => 'Aqar',
        ],
        'directories' => [
            'tripadvisor.com' => 'Tripadvisor', 'yelp.com' => 'Yelp', 'foursquare.com' => 'Foursquare',
            'yellowpages.com' => 'Yellow Pages', 'booking.com' => 'Booking.com', 'agoda.com' => 'Agoda',
            'expedia.com' => 'Expedia', 'vezeeta.com' => 'Vezeeta', 'okadoc.com' => 'Okadoc',
        ],

        'enterprise' => [
            // Brand registry categories whose brands are enterprises rather than shop chains.
            'nsi_categories' => ['brands/amenity/bank', 'brands/office/telecommunication', 'brands/office/insurance', 'brands/amenity/fuel'],
            'name_keywords' => ['bank', 'بنك', 'مصرف', 'telecom', 'insurance', 'تأمين', 'airlines', 'الخطوط الجوية'],
            // Wikidata employee count above which an organization is an enterprise.
            'min_employees' => 1000,
        ],

        // Words suggesting a corporate group; a weak signal on their own.
        'corporate_name_keywords' => ['group', 'holding', 'holdings', 'مجموعة', 'القابضة', 'corporation'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Confidence
    |--------------------------------------------------------------------------
    |
    | "How confident are we that this business and its core data are real
    | and current?" Independent source groups count, not sources: OSM and
    | Geoapify together are one group. This never measures buying intent.
    |
    */

    'data_confidence' => [
        'points' => [
            'groups_1' => 35,
            'groups_2' => 60,
            'groups_3' => 75,
            'first_party' => 10,
            'phone_corroborated' => 5,
            'location_corroborated' => 5,
            'recently_seen' => 5,
            'stale' => -15,
            'operating_open' => 5,
            'permanently_closed' => -60,
            'source_confidence_high' => 5,
            'source_confidence_low' => -10,
            'website_working' => 5,
            'website_broken' => -10,
            'major_conflict' => -25,
            'minor_conflict' => -5,
            'no_coordinates' => -10,
            'junk_name' => -20,
        ],
        'minor_conflict_cap' => 15,
        'source_confidence_high' => 0.8,
        'source_confidence_low' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Business Fit
    |--------------------------------------------------------------------------
    |
    | "Is this type of business a good target for our services?" A category
    | base plus an adjustment for the kind of entity. The negative entity
    | adjustments are the chain, enterprise and government penalties.
    |
    */

    'business_fit' => [
        'category_points' => ['high' => 80, 'medium' => 60, 'low' => 35, 'other' => 50],

        'categories' => [
            'high' => [
                'restaurant', 'cafe', 'clinic', 'dentist', 'real_estate', 'contractors', 'beauty', 'education',
                'car_services', 'legal', 'accounting', 'events', 'furniture', 'jewelry', 'car_rental', 'hotel',
            ],
            'medium' => [
                'bakery', 'fitness', 'travel', 'fashion', 'electronics', 'florist', 'optician', 'veterinary',
                'kindergarten', 'laundry', 'car_dealer',
            ],
            'low' => ['pharmacy', 'grocery'],
        ],

        'entity_adjustments' => [
            'independent' => 10,
            'local_business' => 5,
            'local_multi_branch' => 5,
            'franchise' => -20,
            'regional_chain' => -40,
            'national_chain' => -70,
            'international_chain' => -100,
            'enterprise' => -100,
            'corporate' => -50,
            'government' => -100,
            'nonprofit' => -40,
            'aggregator' => -100,
            'directory' => -100,
            'unknown' => -10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Opportunity Score
    |--------------------------------------------------------------------------
    |
    | "Does the business have digital gaps we can fix?" 0-100, every point
    | explained. An excellent existing digital presence lowers the score.
    |
    */

    'opportunity_score' => [
        'points' => [
            // Website status (one applies).
            'website_missing' => 45,
            'website_broken' => 40,
            'website_weak' => 25,
            'website_average' => 10,

            // No website, but the category needs online booking / ordering / e-commerce.
            'latent_need_high' => 12,
            'latent_need_medium' => 6,

            // Each detected opportunity besides the website itself (capped below).
            'opportunity_high' => 15,
            'opportunity_medium' => 9,
            'opportunity_low' => 4,

            // The strong digital presence penalty.
            'strong_digital_presence' => -25,
        ],

        'website_weak_below' => 50,
        'website_average_below' => 80,
        'opportunity_points_cap' => 40,
        // An audit at or above this score without a high-priority gap is a strong presence.
        'strong_presence_min_audit_score' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Eligibility
    |--------------------------------------------------------------------------
    |
    | The final HOT / WARM / LOW / REVIEW_REQUIRED / DISQUALIFIED decision.
    | Defaults are conservative: a business is HOT only when every
    | requirement holds; doubt sends it to review, never to HOT.
    |
    */

    'eligibility' => [
        'hot' => [
            'min_data_confidence' => 60,
            'min_business_fit' => 60,
            'min_opportunity' => 45,
            // Independent source groups confirming that the business exists.
            'min_evidence_groups' => 2,
        ],

        'warm' => [
            'min_data_confidence' => 35,
            'min_business_fit' => 45,
            'min_opportunity' => 25,
        ],

        // Entity types that can become HOT.
        'eligible_entity_types' => ['independent', 'local_business', 'local_multi_branch'],

        // Hard disqualifiers, applied when the entity confidence reaches
        // entity.disqualify_min_confidence; with less certainty they go to review.
        'disqualified_entity_types' => [
            'international_chain', 'national_chain', 'enterprise', 'corporate', 'government', 'aggregator', 'directory',
        ],

        // A franchise may be locally operated: review (default), warm (capped at WARM) or disqualify.
        'franchise_treatment' => env('LEADS_FRANCHISE_TREATMENT', 'review'),
        // Regional chains and non-profits: review or disqualify.
        'regional_chain_treatment' => 'review',
        'nonprofit_treatment' => 'review',

        // An excellent digital presence with no gap worth selling disqualifies.
        'strong_presence_max_opportunity' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Score
    |--------------------------------------------------------------------------
    |
    | A composite 0-100 score for sorting, combining the three separate
    | scores. Disqualified businesses score 0. Bump "version" whenever a
    | scoring or eligibility rule changes, so old scores can be identified.
    |
    */

    'lead_score' => [
        'version' => '2',

        'weights' => [
            'opportunity' => 0.45,
            'business_fit' => 0.35,
            'data_confidence' => 0.20,
        ],
    ],

];
