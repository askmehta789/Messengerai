<?php

return [
    // Shown on the public privacy policy page (/privacy), which Meta App Review requires.
    'business_name' => env('BUSINESS_NAME', env('APP_NAME', 'Our business')),
    'contact_email' => env('CONTACT_EMAIL', 'contact@example.com'),

    // Shown before amounts in orders and exports.
    'currency' => env('CURRENCY', 'Rs.'),
];
