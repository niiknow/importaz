<?php

namespace Controllers\Api;

/**
 * Use azure table as cache.
 * Cache Rules:
 *  1. Cache clear daily on UTC time
 *  2. User must pass in cache ttl/expires in seconds
 */
class AzureCache extends \Controllers\Api\AzureTable
{
  /**
   * get cache by name
   */
  public function getCache()
  {
    $this->params['tableName'] = 'cache' . date("Ym");
    $name                      = $this->params['name'];
    $query                     = "PartitionKey eq '$name'";
    $errors                    = array();
    $tableRst                  = $this->getTableName($errors);
    $tableName                 = $tableRst['tableName'];
    $result                    = null;

    if ($this->cache->exists("app-$$tableName-$name", $result)) {
      echo $result;
      return;
    }

    $result = $this->execQuery($query, 1);

    if ($result['items'] && is_array($result['items']) && $result['items'][0]) {
      $obj = $result['items'][0];
      if (time() > (int)$obj->ttlx) {
        return;
      }

      // use low cache ttl here to provide better qos with azure origin
      $this->cache->set("app-$$tableName-$name", $obj->value, 2);
      echo $obj->value;
      return;
    }
  }

  /**
   * set cache
   */
  public function setCache()
  {

    $this->params['tableName'] = 'cache' . date("Ym");
    $name                      = $this->params['name'];
    $ttl                       = $this->getOrDefault('GET.ttl', 600);
    $time                      = time();
    $rowKey                    = (9007199254740991 - $time) . '';
    $errors                    = array();
    $tableRst                  = $this->getTableName($errors);
    $tableName                 = $tableRst['tableName'];

    $postBody = [
      'RowKey' => $rowKey,
      'value'  => $this->getOrDefault('GET.v', $this->f3->BODY),
      'ttl'    => $ttl . '',
      'dtc'    => date('Y-m-d h:i:s A', $time - 21600),
      'ttlx'   => ($time + $ttl) . '', // unix expired time
    ];

    $data = [
      'items' => [$postBody],
    ];
    $result = $this->execTable($tableName, $name, $data);

    // use low cache ttl here to provide better qos with azure origin
    $this->cache->set("app-$$tableName-$name", $postBody['value'], 2);
    echo $postBody['value'];
  }
}