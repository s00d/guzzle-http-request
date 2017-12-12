<?php

namespace s00d\GuzzleHttpRequest;

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