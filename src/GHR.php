<?php

namespace s00d\GuzzleHttpRequest;

use \GuzzleHttp\Client;
use \GuzzleHttp\Cookie\CookieJar;
use \GuzzleHttp\Cookie\FileCookieJar;

use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\TransferStats;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;

use \GuzzleHttp\Promise\EachPromise;
use \GuzzleHttp\HandlerStack;
use \Symfony\Component\DomCrawler\Form;

use Kevinrob\GuzzleCache\CacheMiddleware;
use \Illuminate\Support\Facades\Cache;
use \Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;
use \Kevinrob\GuzzleCache\Storage\LaravelCacheStorage;

use Concat\Http\Middleware\Logger;


/**
 * @author s00d <Virus191288@gmail.com>
 * @method string get(string $string)
 * @method string post(string $string)
 * @method string patch(string $string)
 * @method string put(string $string)
 * @method string delete(string $string)
 */

class GHR extends GHRCore
{
    public function __construct(){}

    /**
     * Создание запроса, для сброса всех параметров необходимо еще раз обратиться к этой функции
     * @param $url string
     * @return self
     */
    public static function createRequest($url = '')
    {
        if (!self::$_instance) self::$_instance = new self();

        $handlerStack = HandlerStack::create();
        if (config('ghr.cache')) $handlerStack->push(self::$_instance->_createCacheMiddleware(), 'cache');

        if (config('ghr.logs')) {
            $logger = with(new \Monolog\Logger("api_log"))->pushHandler(
                new \Monolog\Handler\RotatingFileHandler(storage_path('logs/ghr.log'))
            );
            $middleware = new Logger($logger);
            $middleware->setFormatter(new MessageFormatter("'{method} {target} HTTP/{version}' " . PHP_EOL . " [{date_common_log} {code} {res_header_Content-Length}]"));
            $handlerStack->push($middleware);
        }

        self::$_instance->cookieJar = config('ghr.cookie_file') ? new FileCookieJarMod(storage_path('cookie/'.config('ghr.cookie_file')), TRUE) : new CookieJar;
        $param = [
            'timeout' => 100,
            'verify' => false,
            'cookies' => self::$_instance->cookieJar,
            'handler' => $handlerStack
        ];
        if (config('ghr.base_url')) $param['base_url'] = config('ghr.base_url');
        self::$_instance->client = new Client($param);

        self::$_instance->url = $url;
        self::$_instance->params = self::$_instance->genParams();

        /** default variables */
        self::$_instance->data = false;
        self::$_instance->maxRedirects = 0;
        self::$_instance->redirectCount = 0;
        self::$_instance->contentType = config('ghr.content_type');
        self::$_instance->multiResp = new GHRMultipleResponse();

        return self::$_instance;
    }

    /**
     * Отправка запроса
     * @param $redirect boolean не передавать параметр, необходим для правильной работы класса
     * @return $this
     */
    public function send($redirect = false)
    {
        try {
            if ($this->previousUrl) $this->addHeader('referer', $this->previousUrl);
            $this->previousUrl = $this->url;
            if ($this->type == 'POST') $this->addHeader('Content-Type', $this->contentType);
            else $this->removeHeader('Content-Type');
            if ($redirect) $this->redirectCount++; else $this->redirectCount = 0;
            if (!$redirect) $this->addHeader('host', $this->extractHost());
            $this->paramsMarge($this->body, $this->getUrlParams());
            $this->data = new GHRResponseData($this->client->request($this->type, $this->url, $this->params));

        } catch (RequestException $e) {
            $this->data = new GHRResponseData($e->getResponse());
        }
        $this->body_type = false;
        return $this;
    }

    /**
     * Отправка асинхронных заапросов через газл. После отправки очередь отчищается.
     * @todo  полностью переписать. Как вариант можно переделать на класс очереди, добавив туда саму очередь, метод для добавления в очередь и метод для получения всей очереди с отчисткой
     * @param $parse boolean Тип json
     * @param $callback
     * @return $this
     */
    public function multipleSend($parse = true, $callback = false)
    {
        $this->multiResp->clearResponses();
        $count = $this->multiResp->getQueueCount();

        $promises = function () use ($count, $parse) {
            $queue = $this->multiResp->getQueue();
            foreach ($queue as $key => $data) {
                $this->setParamsByType($data['body_type'], $data['body']);
                $this->paramsMarge($this->body, $this->getUrlParams());
                if ($data['content_type']) $this->setContentType($data['content_type']);
                yield $this->client->requestAsync($data['type'], $data['url'], $this->params)
                    ->then(function (ResponseInterface $response) use ($key, $data, $count, $parse) {
                        $resp = (new GHRResponseData($response));
                        if ($data['callback']) $data['callback']($key, $resp);
                        $this->multiResp->addResponse($key, $resp);
                        $this->multiResp->addEnd($key, $data);
                        echo "Promise! {$key} / {$count} \n";
                        return $resp;
                    }, function (RequestException $e) use ($key, $data, $count) {
                        $this->multiResp->addError($key, $data);
                        echo "err! {$key} / {$count} \n";
                        return $e;
                    });
            }
        };

        $this->runQueuePromise($promises, $callback);
        return $this;
    }

    /**
     * Добавление ссылки в очередь для отправки
     * @param string $url
     * @param string $type
     * @param integer|string $id
     * @return $this
     */
    public function addToQueue($url, $type = 'GET', $id = '', $callback = false)
    {
        $params = $this->getBody();
        if (array_key_exists('body_type', $params)) $params['content_type'] = $this->contentType;
        if ($id !== '') $params['id'] = $id;
        $this->multiResp->pushQueue($url, $type, $params, $callback);
        return $this;
    }


    /**
     * Установка количества активных потоков, по умолчанию 4
     * @param integer $count
     * @return $this
     */
    public function setMultipleFlowCount($count)
    {
        $this->multipleFlowCount = $count;
        return $this;
    }

    /**
     * Добавление заголовка к запросу
     * @param $title string
     * @param $data string
     * @return $this
     */
    public function addHeader($title, $data)
    {
        $this->params['headers'][$title] = $data;
        return $this;
    }

    /**
     * Добавление заголовков к запросу
     * @param $data array
     * @return $this
     */
    public function addHeaders($data)
    {
        foreach ($data as $key => $item) $this->params['headers'][$key] = $item;
        return $this;
    }

    /**
     * Установка Accept
     * @param $header string
     * @return $this
     */
    function accept($header)
    {
        return $this->addHeaders(['Accept' => $header]);
    }

    /**
     * Добавление заголовка к запросу
     * @param $data array
     * @return $this
     */
    public function setHeader($data)
    {
        $this->params['headers'] = $data;
        return $this;
    }

    /**
     * удаление заголовка из запроса
     * @param $title string
     * @return $this
     */
    public function removeHeader($title)
    {
        unset($this->params['headers'][$title]);
        return $this;
    }

    /**
     * Сброс заголовков
     * @return $this
     */
    public function dropHeaders()
    {
        $this->params['headers'] = config('ghr.default_headers');
        return $this;
    }

    /**
     * Установкка предыдущей ссылкуи
     * @param $url string|boolean
     * @return self
     */
    public function setPreviousUrl($url = false) {
        $this->previousUrl = $url;
        return $this;

    }

    /**
     * Установка типа, rType для редиректов
     * @param $type string
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;
        $this->rType = $type;
        return $this;
    }

    /**
     * Установка параметров формы в формате массива
     * @param $body array
     * @param $type string
     * @return $this
     */
    public function setBody($body, $type = 'body')
    {
        $this->removeDataParams();
        $this->contentTypeAsForm();
        $this->body_type = $type;
        $this->body = $body;
        return $this;
    }

    /**
     * Установка параметров формы в формате массива
     * @return array
     */
    public function getBody()
    {
        if ($this->body_type && $this->body) return ['body_type' => $this->body_type, 'body' => $this->body];
        return [];
    }

    /**
     * Установка параметров формы в формате массива(конвертивует все параметры в строку)
     * @param $form_params array
     * @return $this
     */
    public function setFormParams($form_params)
    {
        $this->removeDataParams();
        $this->contentTypeAsForm();
        $this->body_type = 'form_params';
        $this->body = $form_params;
        return $this;
    }

    public function setParamsByType($type, $form_params)
    {
        $this->removeDataParams();
        $this->body_type = false;
        if ($type) {
            $this->body_type = $type;
            $this->body = $form_params;
        }

        return $this;
    }

    /**
     * Установка параметров формы в формате Multipart
     * @param $multipart array
     * @return $this
     */
    public function setMultipart($multipart)
    {
        $this->removeDataParams();
        $this->contentTypeAsForm();
        $this->body_type = 'multipart';
        $this->body = $multipart;
        return $this;
    }

    /**
     * Установка параметров формы в формате query
     * @param $query array
     * @return $this
     */
    public function setQuery($query)
    {
        $this->removeDataParams();
        $this->contentTypeAsForm();
        $this->body_type = 'query';
        $this->body = $query;
        return $this;
    }

    /**
     * Установка параметров формы в формате json
     * @param $json array
     * @return $this
     */
    public function setJson($json)
    {
        $this->removeDataParams();
        $this->contentTypeAsJson();
        $this->body_type = 'json';
        $this->body = $json;
        return $this;
    }

    /**
     * Отчистка параметров формы
     * @return $this
     */
    public function removeDataParams()
    {
        $this->body_type = false;
        $this->body = [];
        unset($this->params['body']);
        unset($this->params['form_params']);
        unset($this->params['json']);
        unset($this->params['query']);
        unset($this->params['multipart']);
        return $this;
    }

    /**
     * Добавление параметра к форме
     * @param $title string Заголовок поля
     * @param $data string данные поля
     * @return $this
     */
    public function addDataParam($title, $data = '_add_disable_rm_')
    {
        if ($data == '_add_disable_rm_') unset($this->body[$title]);
        else $this->body[$title] = $data;
        return $this;
    }

    /**
     * Замена параметров к форме
     * @param $data array данные поля
     * @return $this
     */
    public function setDataParam($data)
    {
        $this->body = $data;
        return $this;
    }

    /**
     * Включение прокси
     * @param $proxy
     * @return $this
     */
    public function setProxy($proxy)
    {
        $this->params['proxy'] = $proxy;
        return $this;
    }

    /**
     * Отключение прокси
     * @return $this
     */
    public function removeProxy()
    {
        unset($this->params['proxy']);
        return $this;
    }

    /**
     * Установка версии ssl(по дефолту 4)
     * @param $version
     * @return $this
     */
    public function setSslVersion($version)
    {
        $this->params['curl'][CURLOPT_SSLVERSION] = $version;
        return $this;
    }

    /**
     * Добавление авторизации к запросу
     * @param $data array массив в формате ['username', 'password']
     * @return $this
     */
    public function setAuth($data)
    {
        $this->params['auth'] = $data;
        return $this;
    }

    /**
     * Удаление авторизации из запроса
     * @return $this
     */
    public function removeAuth()
    {
        unset($this->params['auth']);
        return $this;
    }

    /**
     * Установка таймаута запроса в секундах
     * @param $seconds integer
     * @return $this
     */
    public function setTimeout($seconds)
    {
        $this->params['timeout'] = $seconds;
        return $this;
    }

    /**
     * Включение илди отключение отладки
     * @param $debug boolean
     * @return $this
     */
    public function setDebug($debug)
    {
        if ($debug) $this->params['timeout'] = true;
        else unset($this->params['timeout']);
        return $this;
    }

    /**
     * Установка ссылки для запроса
     * @param $url string
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * получение последней активной ссылки(в случае редиректа будет получена последняя)
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    public function disableSaveCookie() {
        if($this->cookieJar instanceof FileCookieJarMod) {
            $this->cookieJar->disableSave();
        }
        return $this;
    }

    public function removeCookie() {
        if($this->cookieJar instanceof FileCookieJarMod) {
            $this->cookieJar->remove();
        }
        return $this;
    }

    /**
     * Влючение или отключение ошибок запросов( для правильной работы класса необходимо отключить все ошибки и обрабатывать их этим классом)
     * @param $http_errors boolean
     * @return $this
     */
    public function setHttpErrors($http_errors)
    {
        $this->params['http_errors'] = $http_errors;
        return $this;
    }

    /**
     * Установка максимального количества редиректов, для отключения необходимо передать 0
     * @param $count integer
     * @return $this
     */
    public function setRedirects($count)
    {
        $this->maxRedirects = $count;
        return $this;
    }

    /**
     * Установка типа пост запроса
     * @param $type string
     * @return $this
     */
    public function setContentType($type)
    {
        $this->contentType = $type;
        return $this;
    }

    public function contentTypeAsJson()
    {
        return $this->setContentType('application/json');
    }

    public function contentTypeAsForm()
    {
        return $this->setContentType('application/x-www-form-urlencoded');
    }

    /**
     * Включение редиректов Guzzle, для правильной работы редиректов класса, эти редиректы должны быть выключены
     * @param $redirects Boolean
     * @return $this
     */
    public function setGuzzleRedirects($redirects, $max = 0, $strict = true, $referer = true)
    {
        if ($redirects) $this->params['allow_redirects'] = [
            'strict' => $strict,
            'max' => $max,
            'referer' => $referer,
            'track_redirects' => true
        ];
        else $this->params['allow_redirects'] = false;
        $this->params['redirect'] = $redirects;
        return $this;
    }

    /**
     * @return GHRMultipleResponse
     */
    public function getMultiResp()
    {
        return $this->multiResp;
    }

    /**
     * Прямое получение экземпляра Crawler`a
     * @return Crawler
     */
    public function getCrawler()
    {
        try {
            $crawler = new Crawler(null, $this->getUrl());
            $crawler->addContent($this->getRequestBody(), $this->data->getHeader('Content-Type'));
            return $crawler;
        } catch (\Exception $e) {
            return false;
        }

    }

    /**
     * Сброс параметров
     * @return $this
     */
    public function setDefaultParams()
    {
        $this->params = $this->genParams();
        return $this;

    }

    /**
     * Куки
     * @return $this->cookieJar
     */
    public function cookie()
    {
        return $this->cookieJar;
    }

    /**
     * Отправка параметров из формы
     * @param $form \Symfony\Component\DomCrawler\Form Форма с параметрами
     * $form = $this->crawler->selectButton('Войти')->form();
     *  $form->setValues([
     *      'IDToken1' => $IDToken1,
     *      'IDToken2' => $IDToken2,
     *  ]);
     * @param $values array Дополнительные параметры в виде массива
     * @return $this
     */
    public function sendForm(\Symfony\Component\DomCrawler\Form $form, array $values = [])
    {
        $form->setValues($values);

        $this->setType($form->getMethod());
        $this->setUrl($form->getUri());

        $values = $form->getPhpValues();
        if (!empty($values)) $this->setFormParams($values);

        $this->send(false);
        return $this;
    }

    /**
     * Получение body
     * @return bool|StreamInterface
     */
    public function getRequestBody()
    {
        return ($this->data) ? $this->data->body() : false;
    }

    /**
     * Получение GuzzleHttpResponse
     * @return GHRResponseData
     */
    public function getRequestData()
    {
        return $this->data;
    }

    /**
     * Получение контента
     * @return bool|string
     */
    public function getContents()
    {
        return ($this->data) ? $this->data->contents() : false;
    }

    /**
     * Получение данных в виде Json
     * @param $parse bool
     * @return bool|mixed
     */
    public function getJson($parse = true)
    {
        return ($this->data) ? $this->data->json($parse) : false;
    }

}

class GHRCore
{

    /** @var GHR $_instance */
    protected static $_instance = false;
    /** @var \GuzzleHttp\Client $client */
    protected $client;
    /** @var FileCookieJar $cookieJar */
    protected $cookieJar;
    protected $url = '', $previousUrl = false;
    protected $type = 'GET', $rType = 'GET';
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
            'verify' => false,
            'timeout' => 100,
            'headers' => config('ghr.default_headers'),
            'allow_redirects' => false,
            'redirect' => false,
            'http_errors' => true,
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
            ],
            "on_stats" => function (TransferStats $stats) {
                //var_dump('getEffectiveUri', $stats->getEffectiveUri());
                //var_dump('getTransferTime', $stats->getTransferTime());
//                var_dump('getHandlerStats', $stats->getHandlerStats());
            }
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

class GHRResponseData
{
    /** @var ResponseInterface $response */
    private $response;

    function __construct($response, $fuild = false)
    {
        $this->response = $response;
    }

    function body()
    {
        return ($this->response) ? (string)$this->response->getBody() : '';
    }

    function contents()
    {
        try {
            if(!$this->response) return false;
            $data = $this->response->getBody();
            if(!$data) return false;
            return $data->getContents();
        } catch (\Exception $e) {
            return false;
        }
    }

    function json($parse = true, $depth = 512, $options = 0)
    {
        return json_decode($this->body(), $parse, $depth, $options);
    }

    function getHeader($header, $first = true)
    {
        return ($this->response) ? $this->response->getHeaderLine($header) : '';
//        $normalizedHeader = str_replace('-', '_', strtolower($header));
//        $seader = $this->response->getHeaders();
//        foreach ($seader as $key => $value) {
//            if (str_replace('-', '_', strtolower($key)) === $normalizedHeader) {
//                if ($first) {
//                    return is_array($value) ? (count($value) ? $value[0] : '') : $value;
//                }
//
//                return is_array($value) ? $value : array($value);
//            }
//        }
//
//        return $first ? null : array();
    }

    function getHeaders()
    {
        return $this->response->getHeaders();
    }

    function status()
    {
        return $this->response->getStatusCode();
    }

    function isSuccess()
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    function isOk()
    {
        return $this->isSuccess();
    }

    function isRedirect()
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    function isGetRedirect()
    {
        return $this->status() == 302 || $this->status() < 303;
    }

    function isClientError()
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    function isServerError()
    {
        return $this->status() >= 500;
    }

    function __call($method, $args)
    {
        return $this->response->{$method}(...$args);
    }
}

class GHRMultipleResponse
{
    protected $err;
    protected $finish;
    protected $response;
    protected $queue = [];

    function __construct()
    {
    }

    public function clearResponses()
    {
        $this->err = [];
        $this->finish = [];
        $this->response = [];
    }

    public function pushQueue($url, $type = 'GET', $params = ['body_type', 'body', 'id'], $callback = false)
    {
        $data = [
            'url' => $url,
            'type' => $type,
            'callback' => $callback,
            'body_type' => array_key_exists('body_type', $params) ? $params['body_type'] : false,
            'body' => array_key_exists('body', $params) ? $params['body'] : false,
            'content_type' => array_key_exists('body', $params) ? $params['body'] : false
        ];
        if (array_key_exists('id', $params)) $this->queue[$params['id']] = $data;
        else $this->queue[] = $data;
    }

    public function setQueue($data)
    {
        $this->queue = $data;
    }

    public function getQueueCount()
    {
        return count($this->queue);
    }

    public function getQueue()
    {
        $result = $this->queue;
        $this->queue = [];
        return $result;
    }

    public function addError($id, $data)
    {
        $this->err[$id] = $data;
    }

    public function addEnd($id, $data)
    {
        $this->finish[$id] = $data;
    }

    public function addResponse($id, $data)
    {
        $this->response[$id] = $data;
    }

    public function errors()
    {
        return $this->err;
    }

    public function finished()
    {
        return $this->finish;
    }

    /**
     * @return GHRResponseData[]
     */
    public function responses()
    {
        return $this->response;
    }

    public function responseQueue($string = false)
    {
        $data = [];
        foreach ($this->err as $item) {
            $data[] = $string ? json_encode($item) : $item;
        }
        foreach ($this->finish as $item) {
            $data[] = $string ? json_encode($item) : $item;
        }
        return $data;
    }
}

class FileCookieJarMod extends CookieJar {
    /** @var string filename */
    private $filename;
    /** @var bool Control whether to persist session cookies or not. */
    private $storeSessionCookies;
    private $saveFile = true;

    public function disableSave()
    {
        $this->saveFile = false;
    }

    public function __construct($cookieFile, $storeSessionCookies = false)
    {
        $this->filename = $cookieFile;
        $this->storeSessionCookies = $storeSessionCookies;

        if (file_exists($cookieFile)) $this->load($cookieFile);
    }

    public function __destruct()
    {
        if($this->saveFile) $this->save($this->filename);
    }

    public function remove() {
        if (file_exists($this->filename)) unlink($this->filename);
    }

    public function save($filename)
    {
        $json = [];
        foreach ($this as $cookie) {
            /** @var SetCookie $cookie */
            if (CookieJar::shouldPersist($cookie, $this->storeSessionCookies)) {
                $json[] = $cookie->toArray();
            }
        }

        $jsonStr = \GuzzleHttp\json_encode($json);
        if (false === file_put_contents($filename, $jsonStr)) {
            throw new \RuntimeException("Unable to save file {$filename}");
        }
    }

    public function load($filename)
    {
        $json = file_get_contents($filename);
        if (false === $json) {
            throw new \RuntimeException("Unable to load file {$filename}");
        } elseif ($json === '') {
            return;
        }

        $data = \GuzzleHttp\json_decode($json, true);
        if (is_array($data)) {
            foreach (json_decode($json, true) as $cookie) {
                $this->setCookie(new SetCookie($cookie));
            }
        } elseif (strlen($data)) {
            throw new \RuntimeException("Invalid cookie file: {$filename}");
        }
    }
}