<?php

namespace s00d\GuzzleHttpRequest;


use \GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use \GuzzleHttp\Promise\EachPromise;
use Kevinrob\GuzzleCache\CacheMiddleware;
use \Illuminate\Support\Facades\Cache;
use \Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;
use \Kevinrob\GuzzleCache\Storage\LaravelCacheStorage;
use GuzzleHttp\RequestOptions;

class GHRCore
{

    /** @var GHR $_instance */
    protected static $_instance = false;
    /** @var \GuzzleHttp\Client $client */
    protected $client;
    /** @var FileCookieJar $cookieJar */
    protected $cookieJar;
    protected $url = '';
    protected $referer = false;
    protected $type = 'GET';
    /** @var GHRResponseData $data */
    protected $data = false;

    protected $maxRedirects = 0, $redirectCount = 0;
    protected $multipleFlowCount = 4;
    protected $redirectUrl;
    protected $contentType = 'application/x-www-form-urlencoded';
    /** @var GHRMultipleResponse $multiResp */
    protected $multiResp;
    protected $params = [];
    protected $body = [];
    protected $body_type = false;
    protected $saveCookie = true;

    protected function _createCacheMiddleware()
    {
        return new CacheMiddleware(new PrivateCacheStrategy(new LaravelCacheStorage(Cache::store('redis'))));
    }

    /**
     * генератоор коротких запросов
     * Пример $request = GuzzleHttpRequest::createRequest('', 'MTSGuzzleClient')->get('http://localhost');
     * @param $name
     * @param $args
     * @return mixed
     * @throws \Exception
     */
    function __call($name, $args)
    {
        if (!array_key_exists(strtoupper($name), array_flip(['GET', 'POST', 'PATCH', 'PUT', 'DELETE']))) throw new \Exception(sprintf('error! method not found'));
        if (function_exists($name)) {
            if (!array_key_exists(0, $args)) throw new \Exception(sprintf('error! url not found'));
            $this->setType(strtoupper($name));
            $this->setUrl($args[0]);
            if (array_key_exists(1, $args)) $this->setDataParam($args[1]);

            $this->send();
            return $this->data;
        }
    }

    protected function genParams()
    {
        $params = [
            RequestOptions::VERIFY => false,
            RequestOptions::TIMEOUT => 100,
            RequestOptions::HEADERS => config('ghr.default_headers'),
            RequestOptions::ALLOW_REDIRECTS => false,
            'redirect' => false,
            RequestOptions::HTTP_ERRORS => true,
            'curl' => [
                CURLOPT_SSLVERSION => 4,
                // CURLOPT_SSLVERSION => CURL_SSLVERSION_DEFAULT,
                CURLOPT_SSL_VERIFYPEER => false,
                'body_as_string' => true
            ],
            'curl.options' => [
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                'body_as_string' => true
            ]
//            RequestOptions::ON_STATS => function (TransferStats $stats) {
                //var_dump('getEffectiveUri', $stats->getEffectiveUri());
                //var_dump('getTransferTime', $stats->getTransferTime());
//                var_dump('getHandlerStats', $stats->getHandlerStats());
//            }
        ];
        return $params;
    }

    /**
     * Функция получает домен и подставляет порт при необходимости
     * @return string
     */
    protected function extractHost()
    {
        $host = parse_url($this->url, PHP_URL_HOST);
        if ($port = parse_url($this->url, PHP_URL_PORT)) {
            return $host . ':' . $port;
        }
        return $host;
    }

    /**
     * Запуск промиса и ожидание заверщения
     * @param $promises
     * @param $callback mixed
     */
    protected function runQueuePromise($promises, $callback = false) {
        $promise = new EachPromise($promises(), [
            'concurrency' => $this->multipleFlowCount,
            'fulfilled' => function ($responses) use ($callback) {
                if ($responses instanceof ResponseInterface)  {
                    if($callback) $callback($responses);
                } elseif ($responses instanceof RequestException) {
//                    echo $responses->getMessage();
                }
            },
        ]);
        $promise->promise()->wait();
    }

    protected function getUrlParams()
    {
        parse_str(parse_url($this->url, PHP_URL_QUERY), $query);
        return $query;
    }

    protected function paramsMarge($data, $urlParams)
    {
        if (count($urlParams) > 0 && $this->body_type == false) $this->body_type = 'query';
        $this->params[$this->body_type] = array_merge($data, $urlParams);

        return $this;
    }
}
