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
    $rowKey                    = (9007199254740991 - time()) . '';
    $query                     = "(PartitionKey eq '$name') and (RowKey le '$rowKey')";
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
      // use low cache ttl here to provide better qos with azure origin
      $this->cache->set("app-$$tableName-$name", $result['items'][0]->value, 2);
      echo $result['items'][0]->value;
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
    $expiresAt                 = time() + $ttl;
    $rowKey                    = (9007199254740991 - $expiresAt) . '';
    $errors                    = array();
    $tableRst                  = $this->getTableName($errors);
    $tableName                 = $tableRst['tableName'];

    $postBody = [
      'RowKey' => $rowKey,
      'value'  => $this->getOrDefault('GET.v', $this->f3->BODY),
      'ttl'    => $ttl,
      'expAt'  => date('c', $expiresAt),
    ];

    $data = [
      'items' => [$postBody],
    ];
    $result = $this->execTable($tableName, $name, $data);

    // use low cache ttl here to provide better qos with azure origin
    $this->cache->set("app-$$tableName-$name", $postBody['value'], 5);
    echo $postBody['value'];
  }
}
