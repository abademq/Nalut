<?php

return [
    // الدومين الفرعي لموقع الطلب (مثلاً order.dar-almaqam.com.ly) — فاضي = على المسار /order بس
    'domain' => env('WEB_ORDER_DOMAIN'),

    // المسار على أي دومين (يخدم دائماً — مفيد قبل ما الدومين الفرعي يجهز)
    'path' => env('WEB_ORDER_PATH', 'order'),
];
