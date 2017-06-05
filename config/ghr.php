<?php
return [
    'logs' => env('APP_DEBUG'), // api-consumer.log
    'content_type' => 'application/x-www-form-urlencoded',
    'cookie_file' => false, // GHRcookie.txt

    'base_url' => false, // http://localhost
    'cache' => true,

    'default_headers' => [
        'Accept' => 'text/html, application/xhtml+xml, image/jxr, */*',
        'Accept-Language' => 'en-US,en;q=0.7,ru;q=0.3',
        'Accept-Encoding' => 'gzip, deflate',
    ]
];