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
        $this->f3                 = $f3;
        $this->params             = $params;
        $this->connectionString   = $f3->get('db.azstorage');
        $this->azDefaultPartition = $f3->get('db.azdpart');
        $this->connectionStringData = array();
        $parts = explode(";", $this->connectionString);
        foreach ($parts as &$part) {
            $iparts = explode("=", $part);
            $this->connectionStringData[$iparts[0]] = $iparts[1];
        }
    }

    /**
     * get temp dir
     */
    public function getTempDir()
    {
        return trim($this->getOrDefault('TEMP', '../tmp/'), '/') . '/';
    }

    /**
     * validate the signature of the timestamp
     */
    public function isValidSignature($tsField, $signatureBase64, $sharedSecret, $algo = 'sha256')
    {
        // $tsField is timestamp,validLength
        $parts       = explode(",", $tsField);
        $ts          = $parts[0];
        $validLength = $parts[1];
        if ($ts < (time() - $validLength)) {
            return false;
        }
        $key = $this->generateSignature($ts, $validLength, $sharedSecret, $algo);
        return ($key === $signatureBase64);
    }

    /**
     * generate signature
     */
    public function generateSignature($timestamp, $validLength, $sharedSecret, $algo = 'sha256')
    {
        return base64_encode(hash_hmac($algo, $timestamp . ',' . $validLength, $sharedSecret, true));
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
            'Access-Control-Expose-Headers'    =>
            array_key_exists('acl_expose_headers', $params) ? $params['acl_expose_headers'] : null,
            'Access-Control-Allow-Methods'     =>
            array_key_exists('acl_http_methods', $params) ? $params['acl_http_methods'] : null,
            'Access-Control-Allow-Origin'      => array_key_exists('acl_origin', $params) ? $params['acl_origin'] : '*',
            'Access-Control-Allow-Credentials' =>
            array_key_exists('acl_credentials', $params) && !empty($params['acl_credentials']) ? 'true' : 'false',
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

    /**
     * get two digit that identify the environment
     * @return string
     */
    public function envId()
    {
        $env = $this->getOrDefault('app.env', 'dev');

        // use 3 to prevent system table conflict
        $rst = '3';
        if ($env == 'dev') {
            return $rst . '9';
        } elseif ($env == 'tst') {
            return $rst . '7';
        } elseif ($env == 'uat') {
            return $rst . '5';
        } elseif ($env == 'stg') {
            return $rst . '3';
        } elseif ($env == 'prd') {
            return $rst . '1';
        }

        return $rst . '0';
    }

    /**
     * perform get request
     * @param  string $url     
     * @param  array  $headers 
     * @param  array  $query   
     * @return object          response
     */
    public function doGetJson($url, $inHeaders, $query) 
    {
        $client = new \GuzzleHttp\Client(['headers' => ['Authorization' => $inHeaders['Authorization']]]);
        $response = null;

        try {
            $response = $client->request('GET', $url, ['query' => $query, 'headers' => $inHeaders]);
            $rawBody = $response->getBody()->getContents();
            $result = json_decode($rawBody);
        } catch(\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
            }
        }

        if (is_null($response)) {
            return [
                'raw_body' => null,
                'code' => 503,
                'body' => null,
                'headers' => array()
            ];
        }

        return [
            'raw_body' => $rawBody,
            'code' => $response->getStatusCode(),
            'body' => $result,
            'headers' => $response->getHeaders()
        ];
    }

    /**
     * get azure table storage data
     * @param  string $tableName
     * @return array        headers
     */
    public function getAzureTableStorageData($tableName) 
    {
        $date = gmdate('D, d M Y H:i:s', time()) . ' GMT';
        $account = $this->connectionStringData['AccountName'];
        $stringToSign = "$date\n/$account/$tableName";
        $accountKey = $this->connectionStringData['AccountKey'];
        $sig = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($accountKey), true));
        $headers = [
            'Authorization' => "SharedKeyLite $account:$sig",
            'x-ms-date' => $date,
            'Accept' => 'application/json;odata=nometadata',
            'x-ms-version' => '2016-05-31'
        ];

        $rst = [
            'url'     => "https://$account.table.core.windows.net/$tableName",
            'account' => $account,
            'headers' => $headers,
        ];
        return $rst;
    }
}
