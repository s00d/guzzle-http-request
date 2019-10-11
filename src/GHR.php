<?php

namespace s00d\GuzzleHttpRequest;

use \GuzzleHttp\Client;
use \GuzzleHttp\Cookie\CookieJar;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\MessageFormatter;
use Monolog\Handler\StreamHandler;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\RequestOptions;

use \GuzzleHttp\HandlerStack;
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
    /** @var \Monolog\Logger $logger */
    private $logger = null;

    private function log($string, $type = \Monolog\Logger::INFO) {
        if(!$this->logger) return;
        $arg_lists = func_get_args();

        foreach ($arg_lists as $arg) {
            $this->logger->addRecord($type, $arg, []);
        }

    }

    /**
     * Создание запроса, для сброса всех параметров необходимо еще раз обратиться к этой функции
     * @param $url string
     * @param bool|array $middlewares
     * @return self
     * @throws \Exception
     */
    public static function createRequest($url = '', $middlewares = false)
    {
        if (!self::$_instance) self::$_instance = new self();

        $handlerStack = HandlerStack::create();
        if (config('ghr.cache')) $handlerStack->push(self::$_instance->_createCacheMiddleware(), 'cache');

        if (config('ghr.logs')) {
            self::$_instance->logger = with(new \Monolog\Logger('api_log'))->pushHandler(
                new \Monolog\Handler\RotatingFileHandler(storage_path('logs/ghr.log'))
            );
            $middleware = new Logger(self::$_instance->logger);
            $middleware->setFormatter(new MessageFormatter("'{method} {target} HTTP/{version}' " . PHP_EOL . ' [{date_common_log} {code} {res_body} {res_header_Content-Length}]'));
            $handlerStack->push($middleware);
        }

        if ($middlewares) {
            foreach ($middlewares as $middleware) {
                $handlerStack->unshift($middleware);
            }
        }

        self::$_instance->cookieJar = config('ghr.cookie_file') ? new FileCookieJarMod(storage_path('cookie/'.config('ghr.cookie_file')), TRUE) : new CookieJar;
        $param = [
            RequestOptions::TIMEOUT => 100,
            RequestOptions::VERIFY => false,
            RequestOptions::COOKIES => self::$_instance->cookieJar,
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function send($redirect = false)
    {
        try {
            if ($this->referer) $this->addHeader('referer', $this->referer);
            if ($this->type == 'POST') $this->addHeader('Content-Type', $this->contentType);
            else $this->removeHeader('Content-Type');
            if ($redirect) $this->redirectCount++; else $this->redirectCount = 0;
            if (!$redirect) $this->addHeader('host', $this->extractHost());
            if (is_array($this->body)) {
                $this->paramsMarge($this->body, $this->getUrlParams());
            }
            if (is_string($this->body)) $this->params['body'] = $this->body;
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
        $this->params[RequestOptions::HEADERS][$title] = $data;
        return $this;
    }

    /**
     * Добавление заголовков к запросу
     * @param $data array
     * @return $this
     */
    public function addHeaders($data)
    {
        foreach ($data as $key => $item) $this->params[RequestOptions::HEADERS][$key] = $item;
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
        $this->params[RequestOptions::HEADERS] = $data;
        return $this;
    }

    /**
     * удаление заголовка из запроса
     * @param $title string
     * @return $this
     */
    public function removeHeader($title)
    {
        unset($this->params[RequestOptions::HEADERS][$title]);
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
    public function setReferer($url = false) {
        $this->referer = $url;
        return $this;

    }

    /**
     * Установка типа
     * @param $type string
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Установка параметров формы в формате массива
     * @param $body array|string
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
        $this->contentType = 'multipart';
        $this->body = $multipart;
        return $this;
    }

    /**
     * @param $data array
     * @return $this
     */
    public function setMultipartStream($data)
    {
        $body = new \GuzzleHttp\Psr7\MultipartStream($data);
        $this->removeDataParams();
        $this->setContentType('multipart/form-data; boundary='. $body->getBoundary());
        $this->body = $body->getContents();
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
     * Установка параметров формы в формате xml
     * @param $json array
     * @return $this
     */
    public function setXml($xml)
    {
        $this->removeDataParams();
        $this->contentTypeAsXml();
        $this->body_type = 'xml';
        $this->body = $xml;
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
        unset($this->params['xml']);
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
        $this->params[RequestOptions::PROXY] = $proxy;
        return $this;
    }

    /**
     * Отключение прокси
     * @return $this
     */
    public function removeProxy()
    {
        unset($this->params[RequestOptions::PROXY]);
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
        $this->params[RequestOptions::TIMEOUT] = $seconds;
        return $this;
    }

    /**
     * Включение илди отключение отладки
     * @param $debug boolean
     * @return $this
     */
    public function setDebug($debug)
    {
        if ($debug) $this->params[RequestOptions::TIMEOUT] = true;
        else unset($this->params[RequestOptions::TIMEOUT]);
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

    public function saveCookie() {
        if($this->cookieJar instanceof FileCookieJarMod) {
            $this->cookieJar->saveMe();
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
        $this->params[RequestOptions::HTTP_ERRORS] = $http_errors;
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

    public function contentTypeAsXml()
    {
        return $this->setContentType('text/xml; charset=UTF8');
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
     * @param int $max
     * @param bool $strict
     * @param bool $referer
     * @return $this
     */
    public function setGuzzleRedirects($redirects, $max = 0, $strict = false, $referer = true)
    {
        $onRedirect = function(RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
            $this->setUrl($uri);
            $this->log('Redirecting! From ' . $request->getUri() . ' to ' . $uri);
        };

        if ($redirects) $this->params[RequestOptions::ALLOW_REDIRECTS] = [
            'strict' => $strict,
            'max' => $max,
            'referer' => $referer,
            'track_redirects' => true,
            'on_redirect'     => $onRedirect,
        ];
        else $this->params[RequestOptions::ALLOW_REDIRECTS] = false;
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
     * @return \GuzzleHttp\Cookie\FileCookieJar
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function sendForm(\Symfony\Component\DomCrawler\Form $form, array $values = [])
    {
        $form->setValues($values);

        $this->setType($this->type);
        $this->setUrl($this->getUrl());

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
        return $this->data ? $this->data->body() : false;
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
        return $this->data ? $this->data->contents() : false;
    }

    /**
     * Получение данных в виде Json
     * @param $parse bool
     * @return bool|mixed
     */
    public function getJson($parse = true)
    {
        return $this->data ? $this->data->json($parse) : false;
    }

}
