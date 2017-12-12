<?php
/**
 * Created by PhpStorm.
 * User: pavel
 * Date: 12.12.2017
 * Time: 15:27
 */

namespace s00d\GuzzleHttpRequest;


use \GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;


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