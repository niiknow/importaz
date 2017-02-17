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
        $this->params['tableName'] = 'cache';
        $name                      = $this->params['name'];
        $rowKey                    = time();
        $query                     = "(PartitionKey eq '$name') and (RowKey le '$rowKey')";

        $rst = $this->execQuery($query, 1);
        return $this->json($result);
    }

    /**
     * set cache
     */
    public function setCache()
    {
        
        $this->params['tableName'] = 'cache';
        $name                      = $this->params['name'];
        $ttl                       = $this->params['ttl'];
        $expiresAt                 = time() + $ttl;
        $rowKey                    = PHP_INT_MAX - $expiresAt;

        $postBody = [
            'RowKey'          =>  $rowKey,
            'value'           =>  $this->getOrDefault('GET.v', $this->f3->BODY),
            'ttl'             =>  $ttl,
            'expAt'           =>  date('c', $expiresAt);
        ];

        $data                 = [
            'items'   => [$postBody],
        ];
        $result = $this->execTable('cache', $name, $data);
        return $this->json($result);
    }
}
