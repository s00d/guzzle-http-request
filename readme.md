# GHR

GHR is a simple Guzzle wrapper + multiple request + DomCrawler


Real documentation is in the works, but for now [read the tests](https://github.com/s00d/guzzle-http-request/blob/master/tests/GuzzleHttpRequestTest.php).


## Installation

Require this package in your `composer.json` or install it by running:
```
composer require s00d/guzzle-http-request
```
To start using Laravel, add the Service Provider and the Facade to your `config/app.php`:


```php
'providers' => [
	// ...
	 s00d\GuzzleHttpRequest\GHRServiceProvider::class,
]
```

```php
'aliases' => [
	// ...
	'GHR' => s00d\GuzzleHttpRequest\Facades\GHR::class,
]
```

## Publish the configurations

Run this on the command line from the root of your project:
```
php artisan vendor:publish
```
A configuration file will be publish to config/ghr.php

## Basic Usage

```php
use GHR;
...

Config::set('ghr.cookie_file', "/cookie/text.txt");
$request = GHR::createRequest()->setProxy('tcp://127.0.0.1:8080')->setRedirects(5)->setHttpErrors(false)->setTimeout(500);
$request->addHeader('user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36');
$response = $request->setUrl('https://localhost')->setType('POST')->send();

$crawler = $response->getCrawler();
try{
    $crawler->filter('.exit')->html();
    print_r(Carbon::now()->toTimeString().": Client auth\n");
} catch(\Exception $e) {
    print_r(Carbon::now()->toTimeString().": Client NOT auth\n");
}

$form = $crawler->selectButton('next')->form();

$form->setValues([
    'user' => $user,
]);

$response->sendForm($form);

var_dump($response->getContents());
var_dump($response->getJson());
...

$response = GHR::createRequest()
    ->setMultipleFlowCount(10) 
    ->setProxy('tcp://127.0.0.1:8080')
    ->removeDataParams()->addToQueue('/guzzle-test/get', 'GET', 0)
    ->setBody(['foo' => 'bar'], 'form_params')->addToQueue('/guzzle-test/post',    'POST',   1)
    ->setBody(['foo' => 'bar'], 'form_params')->addToQueue('/guzzle-test/put',     'PUT',    2)
    ->setBody(['foo' => 'bar'], 'form_params')->addToQueue('/guzzle-test/patch',   'PATCH',  3)
    ->setBody(['foo' => 'bar'], 'form_params')->addToQueue('/guzzle-test/delete'), 'DELETE', 4)
    ->multipleSend('form_params')->getMultiResp();

var_dump($response->responses()); // all responses
var_dump($response->errors());
var_dump($response->finished());

```

