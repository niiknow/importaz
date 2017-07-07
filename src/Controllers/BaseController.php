<?php

namespace Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

class BaseController
{
  protected $guzzleClient;

  /**
   * Base Controller
   *
   * @param \Base $f3
   * @param array $params
   */
  public function __construct(\Base $f3, array $params = [])
  {
    $this->f3                   = $f3;
    $this->params               = $params;
    $this->connectionString     = $f3->get('db.azstorage');
    $this->azDefaultPartition   = $f3->get('db.azdpart');
    $this->connectionStringData = array();
    $parts                      = explode(";", $this->connectionString);
    foreach ($parts as &$part) {
      $iparts                                 = explode("=", $part);
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
  public function isValidSignature($sharedSecret, $payload, $algo = 'sha256')
  {
    $parts       = explode(":", $payload);
    $ts          = $parts[0];
    $validLength = intval($parts[1]);
    if ($ts < (time() - $validLength)) {
      return false;
    }

    $sig = $this->generateSignature($sharedSecret, $ts, $validLength, $parts[2], $algo);
    return ($sig === $payload);
  }

  /**
   * generate signature
   */
  public function generateSignature($sharedSecret, $timestamp, $validLength = 3600, $data = "", $algo = 'sha256')
  {
    $str = $timestamp . ':' . $validLength . ':' . $data;
    return $str . ':' . base64_encode(hash_hmac($algo, $str, $sharedSecret, true));
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

  protected function processJsonResult($response)
  {
    try {
      $rawBody = $response->getBody()->getContents();
      $result  = json_decode($rawBody);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
      if ($e->hasResponse()) {
        $response = $e->getResponse();
      }
    }

    if (is_null($response)) {
      return [
        'raw_body' => null,
        'code'     => 503,
        'body'     => null,
        'headers'  => array(),
      ];
    }

    return [
      'raw_body' => $rawBody,
      'code'     => $response->getStatusCode(),
      'body'     => $result,
      'headers'  => $response->getHeaders(),
    ];
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
    $client   = new \GuzzleHttp\Client();
    $response = null;

    $response = $client->request('GET', $url, ['query' => $query, 'headers' => $inHeaders]);
    return $this->processJsonResult($response);
  }

  public function doGetAzureJson($req1, $req2)
  {
    $client = new \GuzzleHttp\Client();

    // Initiate each request but do not block
    $promises = [
      'one' => $client->getAsync($req1['url'], $req1),
      'two' => $client->getAsync($req2['url'], $req2),
    ];

    // Wait on all of the requests to complete. Throws a ConnectException
    // if any of the requests fail
    $results = Promise\unwrap($promises);

    // Wait for the requests to complete, even if some of them fail
    $results = Promise\settle($promises)->wait();
    $rsp1    = $this->processJsonResult($results['one']['value']);
    $rsp2    = $this->processJsonResult($results['two']['value']);
    return [$rsp1, $rsp2];
  }

  /**
   * get azure table storage data
   * @param  string $tableName
   * @return array        headers
   */
  public function getAzureTableStorageData($tableName, $includePreviousTable = false)
  {

    $date         = gmdate('D, d M Y H:i:s', time()) . ' GMT';
    $account      = $this->connectionStringData['AccountName'];
    $stringToSign = "$date\n/$account/$tableName";
    $accountKey   = $this->connectionStringData['AccountKey'];
    $sig          = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($accountKey), true));
    $headers      = [
      'Authorization' => "SharedKeyLite $account:$sig",
      'x-ms-date'     => $date,
      'Accept'        => 'application/json;odata=nometadata',
      'x-ms-version'  => '2016-05-31',
    ];

    $rst = [
      'url'     => "https://$account.table.core.windows.net/$tableName",
      'account' => $account,
      'headers' => $headers,
      'ptable'  => 'test',
    ];

    // detect if tablename has number at the end
    if ($includePreviousTable) {
      preg_match('@\d*$@i', $tableName, $matches);
      if ($matches[0]) {
        $mlen    = strlen($matches[0]);
        $bs      = substr($tableName, 0, strlen($tablename) - $mlen);
        $newdate = substr($matches[0] . "0101", 0, 8);
        $td      = "day";
        $pd      = strtotime("-1 day", strtotime($newdate));
        if ($mlen == 4) {
          $td = "month";
        }
        $nd            = strtotime("-1 " . $td, strtotime($newdate));
        $ptable        = $bs . substr(date("Ymd", $nd), 0, $mlen);
        $rst['ptable'] = $ptable;
      }
    }
    return $rst;
  }

  protected function processAzureTableQuery($tableName, $rsp)
  {
    $json = $rsp['body'];
    if (!is_null($json) && !is_null($json->value)) {
      $rsp['entities'] = $json->value;
      $rsp['nextpk']   = null;
      $rsp['nextrk']   = null;
      $headers         = $rsp['headers'];

      if (isset($headers['x-ms-continuation-NextPartitionKey'])) {
        $rsp['nextpk'] = $headers['x-ms-continuation-NextPartitionKey'];
      }

      if (isset($headers['x-ms-continuation-NextRowKey'])) {
        $rsp['nextrk'] = $headers['x-ms-continuation-NextRowKey'];
      }
    }
    $rsp['tableName'] = $tableName;
    return $rsp;
  }

  /**
   * perform azure table query
   * @param  string $tableName
   * @param  string $filter
   * @param  string $top
   * @param  string $select
   * @param  string $nextpk
   * @param  string $nextrk
   * @return object
   */
  public function doAzureTableQuery($tableName, $filter, $top = null, $select = null, $nextpk = null, $nextrk = null)
  {
    $reqdata  = $this->getAzureTableStorageData($tableName, true);
    $reqdata2 = null;

    $query = [
      '$filter' => $filter,
    ];
    $query2 = [
      '$filter' => $filter,
    ];
    if (!is_null($top)) {
      $query['$top']  = $top;
      $query2['$top'] = $top;
    }

    if (!is_null($select)) {
      $query['$select'] = $select;
      $query['$select'] = $select;
    }

    if (!is_null($nextpk)) {
      $query['NextPartitionKey'] = $nextpk;
    }

    if (!is_null($nextrk)) {
      $query['NextRowKey'] = $nextrk;
    }

    $reqdata['query'] = $query;
    if ($reqdata['ptable']) {
      $reqdata2          = $this->getAzureTableStorageData($reqdata['ptable'], false);
      $reqdata2['query'] = $query2;
    }

    $rst = $this->doGetAzureJson($reqdata, $reqdata2);
    $rsp = $this->processAzureTableQuery($tableName, $rst[0]);
    if ($reqdata['ptable']) {
      $rsp['ptable'] = $this->processAzureTableQuery($reqdata['ptable'], $rst[1]);
    }

    return $rsp;
  }
}
