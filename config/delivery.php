<?php

return [
    'base_fee'           => env('DELIVERY_BASE_FEE', 5),
    'fee_per_km'         => env('DELIVERY_FEE_PER_KM', 1.5),
    'free_radius_km'     => env('DELIVERY_FREE_RADIUS_KM', 1),
    'commission_percent' => env('PLATFORM_COMMISSION_PERCENT', 15),
    'currency'           => 'LYD',

    // محرك حساب المسافة على الطريق (OSRM)
    // للتجربة: خادم المشروع العام. للإنتاج: نصّبه على سيرفرك.
    'osrm_url'  => env('OSRM_URL', 'https://router.project-osrm.org'),

    // معامل احتياطي لو OSRM ما ردّش (خط مستقيم × المعامل)
    'road_factor' => env('ROAD_DISTANCE_FACTOR', 1.3),
];
