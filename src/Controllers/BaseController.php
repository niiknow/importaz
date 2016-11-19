<?php

namespace Controllers;

class BaseController
{
    /**
     * Base Controller
     *
     * @param \Base $f3
     * @param array $params
     */
    public function __construct(\Base $f3, array $params = [])
    {
        $this->f3               = $f3;
        $this->params           = $params;
        $this->connectionString = $f3->get('db.azure_storage');
    }

    /**
     * Authenticate
     */
    public function beforeRoute()
    {
        $hdr = $_SERVER['X_AUTH_HMAC'];

        // split to timestamp and signature
        $hdrs      = explode(':', $hdr);
        $timestamp = $hdrs[0];
        $signature = $hdrs[1];
    }

    /**
     * validate the signature of the timestamp
     */
    public function isValidSignature($timestamp, $signature, $sharedSecret, $algo = 'sha256')
    {
        $seconds_in_half_a_day = 12 * 60 * 60;
        $older_than_half_a_day = $timestamp < (time() - $seconds_in_half_a_day);
        if ($older_than_half_a_day) {
            return false;
        }

        return (hash_hmac($algo, $timestamp, $sharedSecret) === $signature);
    }

    /**
     * get temp dir
     */
    public function getTempDir()
    {
        return trim($this->getOrDefault('TEMP', '../tmp/'), '/') . '/';
    }

    /**
     * $f3 get value or default
     * @param  string   $key
     * @param  string   $default
     * @return object
     */
    public function getOrDefault($key, $default = null)
    {
        $rst = $this->f3->get($key);
        if (!isset($rst)) {
            return $default;
        }
        return $rst;
    }

    /**
     * echo json
     * @param object $data
     * @param array  $params
     */
    public function json($data, array $params = [])
    {
        $f3      = $this->f3;
        $body    = json_encode($data, JSON_PRETTY_PRINT);
        $headers = array_key_exists('headers', $params) ? $params['headers'] : [];

        // set ttl
        $ttl = (int) array_key_exists('ttl', $params) ? $params['ttl'] : 0; // cache for $ttl seconds
        if (empty($ttl)) {
            $ttl = 0;
        }

        $headers = array_merge($headers, [
            'Content-type'                     => 'application/json; charset=utf-8',
            'Expires'                          => '-1',
            'Access-Control-Max-Age'           => $ttl,
            'Access-Control-Expose-Headers'    => array_key_exists('acl_expose_headers', $params) ? $params['acl_expose_headers'] : null,
            'Access-Control-Allow-Methods'     => array_key_exists('acl_http_methods', $params) ? $params['acl_http_methods'] : null,
            'Access-Control-Allow-Origin'      => array_key_exists('acl_origin', $params) ? $params['acl_origin'] : '*',
            'Access-Control-Allow-Credentials' => array_key_exists('acl_credentials', $params) && !empty($params['acl_credentials']) ? 'true' : 'false',
            'ETag'                             => array_key_exists('etag', $params) ? $params['etag'] : md5($body),
            'Content-Length'                   => \UTF::instance()->strlen($body),
        ]);

        // send the headers + data
        $f3->expire($ttl);

        // default status is 200 - OK
        $f3->status(array_key_exists('http_status', $params) ? $params['http_status'] : 200);

        // do not send session cookie
        if (!array_key_exists('cookie', $params)) {
            header_remove('Set-Cookie'); // prevent php session
        }

        ksort($headers);
        foreach ($headers as $header => $value) {
            if (!isset($value)) {
                continue;
            }
            header($header . ': ' . $value);
        }

        // HEAD request should be identical headers to GET request but no body
        if ('HEAD' !== $f3->get('VERB')) {
            echo $body;
        }
    }
}
