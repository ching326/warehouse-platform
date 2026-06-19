<?php

return [
    'sender' => [
        'phone' => env('COURIER_SENDER_PHONE', '0455507090'),
        'postal_code' => env('COURIER_SENDER_POSTAL_CODE', '240-0065'),
        'address1' => env('COURIER_SENDER_ADDRESS1', '神奈川県横浜市保土ケ谷区和田2-6-8'),
        'address2' => env('COURIER_SENDER_ADDRESS2', ''),
        'name' => env('COURIER_SENDER_NAME', null),
    ],
];
