<?php

return [
    'locales' => ['en', 'gu', 'hi'],
    'default_locale' => 'en',
    'indexable' => env('WEBSITE_INDEXABLE', false),
    'enquiry_recipient' => env('WEBSITE_ENQUIRY_RECIPIENT'),
    'address' => 'Bilkha Plaza, Kasturba Rd, Naval Nagar, Sadar, Rajkot, Gujarat 360001',
    'phone' => '+91 9429550666',
    'whatsapp' => '919429550666',
    'maps_url' => 'https://www.google.com/maps/place/?q=place_id:ChIJV3XRcwDLWTkR1f6vleY6Aco',
    'categories' => ['flex', 'vinyl', 'laminate', 'board', 'tarpaulin', 'awnings', 'fabric', 'paper', 'wallpaper', 'glass', 'reflective', 'display', 'lighting'],
    'brands' => ['SRF', 'Sunstar', 'Starflex', 'Sun Sign & Technologies', 'Jindal / Jinflex', 'LX Hausys / VIZUON', '3M', 'Interone', 'Senfa', 'Signtek', 'Startec', 'Ultra Sign', 'HiSign / NEO'],
    'logo_aliases' => ['Starflex' => 'Sunstar', 'Sun Sign & Technologies' => 'Sunstar'],
    'logos' => [
        'SRF' => 'assets/srf-logo.svg',
        'Sunstar' => 'assets/sunsign-logo.webp',
        'Jindal / Jinflex' => 'assets/jindal-logo.png',
        'LX Hausys / VIZUON' => 'assets/lx-logo.svg',
    ],
];
