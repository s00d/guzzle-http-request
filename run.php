<?php

use s00d\GuzzleHttpRequest\GHR;

require_once __DIR__ . '/vendor/autoload.php';

class Config {

    public static $CFG = [
        'logs' => 'test.log', // api-consumer.log
        'content_type' => 'application/x-www-form-urlencoded',
        'cookie_file' => false, // GHRcookie.txt

        'base_url' => false, // http://localhost
        'cache' => false,

        'default_headers' => [
            'Accept' => 'text/html, application/xhtml+xml, image/jxr, */*',
            'Accept-Language' => 'en-US,en;q=0.7,ru;q=0.3',
            'Accept-Encoding' => 'gzip, deflate',
        ]
    ];

    public static function get($name) {
        $name = str_replace('ghr.', '', $name);
        return isset(self::$CFG[$name]) ? self::$CFG[$name] : false;
    }
}

function storage_path($path) {
    return  $path;
}

$request = GHR::createRequest()->setProxy('127.0.0.1:4034')->setGuzzleRedirects(true, 2);

$response = $request->setUrl('http://google.ru')->setQuery([
        'foo' => 'bar',
        'baz' => 'qux',
    ])
    ->send()->getRequestData()->body();

var_dump($response);

$response = $request
    ->setUrl('https://httpbin.org/get')
    ->setDataParam([
        'foo' => 'bar',
        'baz' => 'qux',
    ])
    ->send()->getJson(false);

var_dump($response);
