<?php

namespace s00d\GuzzleHttpRequest;

use Psr\Http\Message\ResponseInterface;

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
        return $this->response ? $this->response->getBody()->__toString() : '';
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
        return call_user_func_array([$this->response, $method], $args);
//        return $this->response->{$method}(...$args);
    }
}
