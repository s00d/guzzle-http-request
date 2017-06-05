<?php

use s00d\GuzzleHttpRequest\GHR;
use \Tests\TestCase;

class GuzzleHttpRequestTest extends TestCase
{
    public static function setUpBeforeClass(){
        Server::start();
    }

    function url($url){
        return vsprintf('%s/%s', ['http://localhost:' . getenv('TEST_SERVER_PORT'), ltrim($url, '/')]);
    }

    /** @test */
    function query_parameters_can_be_passed_as_an_array(){
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->setQuery([
                'foo' => 'bar',
                'baz' => 'qux',
            ])
            ->get($this->url('/guzzle-test/get'));

        $this->assertArraySubset([
            'query' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ]
        ], $response->json());
    }

    /** @test */
    function query_parameters_in_urls_can_be_combined_with_array_parameters()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addDataParam('baz', 'qux')
            ->get($this->url('/guzzle-test/get?foo=bar'));
        $this->assertArraySubset([
            'query' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ]
        ], $response->json());
    }
    /** @test */
    function post_content_is_json_by_default()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->setJson([
                'foo' => 'bar',
                'baz' => 'qux',
            ])
            ->post($this->url('/guzzle-test/post'));

        $this->assertArraySubset([
            'headers' => [
                'content-type' => ['application/json'],
            ],
            'json' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ]
        ], $response->json());
    }
    /** @test */
    function post_content_can_be_sent_as_form_params()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->setFormParams([
                'foo' => 'bar',
                'baz' => 'qux',
            ])
            ->post($this->url('/guzzle-test/post'));

        $this->assertArraySubset([
            'headers' => [
                'content-type' => ['application/x-www-form-urlencoded'],
            ],
            'form_params' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ]
        ], $response->json());

    }

    /** @test */
    function get_with_additional_header()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addHeader('Custom', 'Header')
            ->get($this->url('/guzzle-test/get'));

        $this->assertArraySubset([
            'headers' => [
                'custom' => ['Header'],
            ],
        ], $response->json());
    }
    /** @test */
    function get_with_additional_headers()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addHeaders(['Custom' => 'Header2'])
            ->get($this->url('/guzzle-test/get'));

        $this->assertArraySubset([
            'headers' => [
                'custom' => ['Header2'],
            ],
        ], $response->json());
    }

    /** @test */
    function post_with_additional_headers()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->setHeader(['Custom' => 'Header'])
            ->get($this->url('/guzzle-test/get'));

        $this->assertArraySubset([
            'headers' => [
                'custom' => ['Header'],
            ],
        ], $response->json());
    }
    /** @test */
    function the_accept_header_can_be_set_via_shortcut()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->accept('banana/sandwich')
            ->post($this->url('/guzzle-test/post'));
        $this->assertArraySubset([
            'headers' => [
                'accept' => ['banana/sandwich'],
            ],
        ], $response->json());
    }
    /** @test */
    function redirects_are_followed_by_default(){
        $response = GHR::createRequest()
            ->setRedirects(1)
            ->setProxy('tcp://127.0.0.1:8080')
            ->get($this->url('/guzzle-test/redirect'));
        $this->assertEquals(200, $response->status());
        $this->assertEquals('Redirected!', $response->body());
    }
    /** @test */
    function redirects_can_be_disabled()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->get($this->url('/guzzle-test/redirect'));
        $this->assertEquals(302, $response->status());
        $this->assertEquals($this->url('/guzzle-test/redirected'), $response->getHeader('Location'));
    }
    /** @test */
    function patch_requests_are_supported()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->setFormParams([
                'foo' => 'bar',
                'baz' => 'qux',
            ])
            ->patch($this->url('/guzzle-test/patch'));
        $this->assertArraySubset([
            'form_params' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ]
        ], $response->json());
    }
    /** @test */
    function put_requests_are_supported()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->setFormParams([
                'foo' => 'bar',
                'baz' => 'qux',
            ])
            ->put($this->url('/guzzle-test/put'));
        $this->assertArraySubset([
            'form_params' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ]
        ], $response->json());
    }
    /** @test */
    function delete_requests_are_supported()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->setFormParams([
                'foo' => 'bar',
                'baz' => 'qux',
            ])
            ->delete($this->url('/guzzle-test/delete'));

        $this->assertArraySubset([
            'form_params' => [
                'foo' => 'bar',
                'baz' => 'qux',
            ]
        ], $response->json());
    }

    /** @test */
    function can_retrieve_the_raw_response_body()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->get($this->url('/guzzle-test/simple-response'));
        $this->assertEquals("A simple string response", $response->body());
    }
    /** @test */
    function can_retrieve_response_header_values()
    {
        $response = GuzzleHttpRequest::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->get($this->url('/guzzle-test/get'));
        $this->assertEquals('application/json', $response->getHeader('Content-Type'));
    }
    /** @test */
    function can_check_if_a_response_is_success()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->get($this->url('/guzzle-test/get'));
        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isClientError());
        $this->assertFalse($response->isServerError());
    }
    /** @test */
    function can_check_if_a_response_is_redirect()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addHeader('Z-Status', 300)
            ->get($this->url('/guzzle-test/get'));
        $this->assertTrue($response->isRedirect());
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isClientError());
        $this->assertFalse($response->isServerError());
    }
    /** @test */
    function can_check_if_a_response_is_client_error()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addHeader('Z-Status', 404)
            ->get($this->url('/guzzle-test/get'));
        $this->assertTrue($response->isClientError());
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isServerError());
    }
    /** @test */
    function can_check_if_a_response_is_server_error()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addHeader('Z-Status', 508)
            ->get($this->url('/guzzle-test/get'));
        $this->assertTrue($response->isServerError());
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isClientError());
    }
    /** @test */
    function is_ok_is_an_alias_for_is_success()
    {
        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addHeader('Z-Status', 200)
            ->get($this->url('/guzzle-test/get'));
        $this->assertTrue($response->isOk());
        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isRedirect());
        $this->assertFalse($response->isClientError());
        $this->assertFalse($response->isServerError());
    }

    /** @test */
    function is_ok_multiple_requests(){

        $response = GHR::createRequest()
            ->setProxy('tcp://127.0.0.1:8080')
            ->addHeader('Z-Status', 200)
            ->removeDataParams()->addToQueue($this->url('/guzzle-test/get'),    'GET',    0)
            ->setBody(['foo' => 'bar'], 'form_params')->addToQueue($this->url('/guzzle-test/post'),   'POST',   1)
            ->setBody(['foo' => 'bar'], 'form_params')->addToQueue($this->url('/guzzle-test/put'),    'PUT',    2)
            ->setBody(['foo' => 'bar'], 'form_params')->addToQueue($this->url('/guzzle-test/patch'),  'PATCH',  3)
            ->setBody(['foo' => 'bar'], 'form_params')->addToQueue($this->url('/guzzle-test/delete'), 'DELETE', 4)
            ->multipleSend('form_params')->getMultiResp()->responses();

        $this->assertArraySubset([], $response[0]);
        $this->assertArraySubset(['foo' => 'bar'], $response[1]);
        $this->assertArraySubset(['foo' => 'bar'], $response[2]);
        $this->assertArraySubset(['foo' => 'bar'], $response[3]);
        $this->assertArraySubset(['foo' => 'bar'], $response[4]);

        $this->assertArraySubset([
            1 => ['foo' => 'bar'],
            2 => ['foo' => 'bar'],
            3 => ['foo' => 'bar'],
            4 => ['foo' => 'bar'],
            0 => []
        ], $response);
    }
}
class Server
{
    static function start()
    {
//        if (! file_exists(__DIR__.'../server/vendor')) {
//            exec('cd "'.__DIR__.'../server"; php composer.phar install');
//        }

        $cmd = 'php -S ' . static::getServerUrl() . ' -t '.__DIR__.'\..\server\client';
        $win = substr(php_uname(), 0, 7) == "Windows";
        if ($win) $pid = popen("start /B ". $cmd, "r");
        else $pid = exec($cmd . " > nul 2>&1 & echo $!");

        var_dump($pid);

        while (!static::serverHasBooted()) {
            var_dump('wait');
            usleep(1000);
        }
        var_dump('start');
        register_shutdown_function(function () use ($pid, $win) {
            if ($win)  pclose($pid);
            else   exec('kill ' . $pid);
        });
    }

    public static function getServerUrl(string $endPoint = ''): string
    {
        return 'localhost:'.env('TEST_SERVER_PORT').'/'.$endPoint;
    }

    public static function serverHasBooted(): bool
    {
        return @file_get_contents('http://'.self::getServerUrl('guzzle-test/simple-response')) != false;
    }
}