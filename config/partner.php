<?php

return [
    'url' => env('PARTNER_API_URL', 'http://partner-mock:8080/events'),
    'timeout' => (float) env('PARTNER_TIMEOUT', 5),
    'connect_timeout' => (float) env('PARTNER_CONNECT_TIMEOUT', 2),
    'retry_delay_ms' => (int) env('PARTNER_RETRY_DELAY_MS', 200),
    'dedup_store' => env('DEDUP_STORE', 'redis'),
    'inspection_token' => env('INSPECTION_TOKEN'),
];
