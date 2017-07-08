<?php

namespace Controllers\Api;

/**
 * Use azure table as cache.
 */
class AzureCache extends \Controllers\Api\AzureTable
{
  /**
   * get cache by name
   */
  public function getCache()
  {
    $this->params['tableName'] = 'cache';
    $name                      = $this->params['name'];
    $query                     = "PartitionKey eq '$name' and RowKey eq ''";
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
      if (time() > (int) $obj->ttlx) {
        return;
      }

      // use low cache ttl here to provide better qos with azure origin
      $this->cache->set("app-$$tableName-$name", $obj->v, 2);
      echo $obj->v;
      return;
    }
  }

  /**
   * set cache
   */
  public function setCache()
  {

    $this->params['tableName'] = 'cache';
    $name                      = $this->params['name'];
    $ttl                       = $this->getOrDefault('GET.ttl', 3600);
    $time                      = time();
    $rowKey                    = '';
    $errors                    = array();
    $tableRst                  = $this->getTableName($errors);
    $tableName                 = $tableRst['tableName'];

    $postBody = [
      'RowKey' => $rowKey,
      'expAt'  => $time + $ttl,
      'v'      => $this->getOrDefault('GET.v', $this->f3->BODY),
      'ttl'    => $ttl . '',
      'ttlx'   => ($time + $ttl) . '', // unix expired time
    ];

    $data = [
      'items' => [$postBody],
    ];
    $result = $this->execTable($tableName, $name, $data);

    // use low cache ttl here to provide better qos with azure origin
    $this->cache->set("app-$$tableName-$name", $postBody['v'], 2);
    echo $postBody['v'];
  }
}
