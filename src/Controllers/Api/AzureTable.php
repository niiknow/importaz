<?php

namespace Controllers\Api;

use MicrosoftAzure\Storage\Common\ServiceException;
use MicrosoftAzure\Storage\Table\Models;
use MicrosoftAzure\Storage\Table\Models\BatchOperations;
use MicrosoftAzure\Storage\Table\Models\Entity;
use WindowsAzure\Common\ServicesBuilder;

class AzureTable extends \Controllers\BaseSecuredController
{
    /**
     * delete table partition helper
     */
    public function deleteTablePartition()
    {
        $notifyQueue  = $this->getOrDefault("GET.notifyQueue", null);
        $partitionKey = $this->params["partitionKey"];
        $resultQuery  = $this->execQuery("PartitionKey eq '$partitionKey'", null, "PartitionKey,RowKey");
        $items        = $queryResult["items"];
        foreach ($items as $item) {
            $item["delete"] = true;
        }
        $postData = ["items" => $items];
        if ($notifyQueue != null) {
            $postData["notifyQueue"] = $notifyQueue;
        }
        $result = $this->execAction($postBody);

        // delete perform bulk 1000 items each time this method is called and return hasMoreItems
        // client should keep calling this method until hasMoreItems is false
        $result["hasMoreItems"] = isset($resultQuery["nextrk"]);
        $this->json($result);
    }

    /**
     * query table
     */
    public function query()
    {
        $filter = $this->getOrDefault('GET.$filter', null);
        $top    = $this->getOrDefault('GET.$top', null);
        $select = $this->getOrDefault('GET.$select', null);
        $nextpk = $this->getOrDefault('GET.nextpk', null);
        $nextrk = $this->getOrDefault('GET.nextrk', null);
        $result = $this->execQuery($filter, $top, $select, $nextpk, $nextrk);
        $this->json($result);
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
    public function doAzureTableQuery($tableName, $filter, $top, $select, $nextpk, $nextrk) {
        $reqdata = $this->getAzureTableStorageData($tableName);
        $query = [
            "$filter"    => $filter
        ];
        if (!is_null($top)) {
            $query["$top"] = $top;
        }
        
        if (!is_null($select)) {
            $query["$select"] = $select;
        }

        if (!is_null($nextpk)) {
            $query["NextPartitionKey"] = $nextpk;
        }
        
        if (!is_null($nextrk)) {
            $query["NextRowKey"] = $nextrk;
        }
        
        $response = $this->doGetJson($reqdata->url, $reqdata->headers, $query);
        $response["json"] = json_decode($response->raw_body);
        $json = $response["json"];
        if (!is_null($json) && isset($json["value"])) {
            $response["entities"] = $json["value"];
            $response["nextpk"] = null;
            $response["nextrk"] = null;

            if (isset($rst->headers["x-ms-continuation-NextPartitionKey"])) {
                $response["nextpk"] = $rst->headers["x-ms-continuation-NextPartitionKey"];
            }

            if (isset($rst->headers["x-ms-continuation-NextRowKey"])) {
                $response["nextrk"] = $rst->headers["x-ms-continuation-NextRowKey"];
            }
        }

        return $response;
    }

    /**
     * execute query helper
     * @param  string  $filter
     * @param  string  $top
     * @param  string  $select
     * @param  string  $nextpk
     * @param  string  $nextrk
     * @return array
     */
    public function execQuery($filter, $top = null, $select = null, $nextpk = null, $nextrk = null)
    {
        $f3              = $this->f3;
        $params          = $this->params;
        $errors          = array();
        $tableRst        = $this->getTableName($errors);
        $tableName       = $tableRst['tableName'];
        $result          = [ "items" => array()];
        $rst             = array();

        if (is_null($filter)) {
            $errors[] = ["message" => "$filter is required"];
        }

        if (count($errors) <= 0) {
            $response = $this->doAzureTableQuery($tableName, $filter, $top, $select, $nextpk, $nextrk);
            $json = $response->json;

            if (!is_null($json) && isset($json["odata.error"])) {
                $errors[] = $json["odata.error"];
            }
        }

        if (count($errors) <= 0) {
            $items    = $response["entities"];
            $result   = [
                "nextpk" => $response["nextpk"],
                "nextnk" => $$response["nextrk"],
            ];

            $result["items"] = $items;
            $result["count"] = count($items);
        } else {
            $result = ["errors" => $errors];
        }
        
        $result["tableName"]  = $tableName;

        return $result;
    }

    /**
     * insert/update/delete json
     */
    public function index()
    {
        $postBody = json_decode($this->f3->BODY, true);
        $this->execAction($postBody);
    }

    /**
     * get table name
     */
    public function tableName()
    {
        $errors          = array();
        $this->json($this->getTableName($errors));
    }

    /**
     * get table name
     */
    public function getTableName(&$errors)
    {
        $f3              = $this->f3;
        $params          = $this->params;
        $tenant          = $this->getTenantCode($errors);
        $env             = $this->envId();
        $tableName       = $params['tableName'];
        $namePrefix      = $tenant . $env;
        $actualTableName = $namePrefix . $tableName;
        $partitionKey    = $this->getOrDefault('GET.pk', $this->azDefaultPartition);

        $result          = [ "tableName" => $actualTableName, "partitionKey" => $partitionKey, "namePrefix" => $namePrefix ];
        return $result;
    }


    /**
     * execute table action
     * @param array $postBody
     */
    public function execAction($postBody)
    {
        $errors       = array();
        $f3           = $this->f3;
        $params       = $this->params;
        $tableRst     = $this->getTableName($errors);
        $tableName    = $tableRst['tableName'];
        $partitionKey = $tableRst['partitionKey'];

        // $count        = 0;
        $result       = array();
        $errorCount   = 0;
        $transactions = array();

        // making it possible to execute up to 1000 items
        $items      = $postBody['items'];
        
        $itemsCount = count($items);
        if ($itemsCount <= 1000) {
            $packages = ceil($itemsCount / 100);
            for ($i = 0; $i < $packages; $i++) {
                $postBody["items"] = array_slice($items, $i * 100, 100);
                $tran              = $this->execTable($tableName, $partitionKey, $postBody, $isDelete);
                $transactions[]    = $tran;
                // $count += $result["count"];
                $errorCount += count($tran["errors"]);
                foreach ($tran as $k => $v) {
                    if ($k == "errors") {
                        continue;
                    }
                    $result[$k] = $v;
                }
                // some random wait so server can catch up
                usleep(rand(10, 200));
            }
        } else {
            $errors[]         = ["message" => "expected items count to be less than 1001 but got " . $itemsCount];
            $result["errors"] = $errors;
        }

        $result["errorCount"] = $errorCount;
        $result["count"]      = $itemsCount;
        $result["trans"]      = $transactions;

        if (isset($postBody["errorRows"])) {
            $result["errorRows"] = $postBody["errorRows"];
        }
        $this->json($result);
    }

    /**
     * insert/update/delete csv
     */
    public function csv()
    {
        $csvString = $this->f3->BODY;
        $postBody  = $this->csvToArray($csvString);
        $this->execAction($postBody);
    }

    /**
     * convert csv to array
     * @param  string  $csvString
     * @param  string  $delimiter
     * @param  string  $enclosure
     * @param  string  $escape
     * @return array
     */
    public function csvToArray($csvString, $delimiter = ',', $enclosure = '"', $escape = '\\')
    {
        $header = null;
        $data   = array();
        $lines  = explode("\n", $csvString);
        $i      = 0;
        $errors = array();

        foreach ($lines as $line) {
            $i = $i + 1;
            // fix product data issue
            $myLine = str_replace(", ", ";", $line);
            $myLine = str_replace(",", "|", $myLine);
            $myLine = str_replace(";", ", ", $myLine);
            $values = str_getcsv($myLine, '|', $enclosure, $escape);

            if (!$header) {
                $header = $values;
            } else {
                if (count($header) !== count($values)) {
                    /* ignore horribly bad data
                    var_dump($header);
                    var_dump($i);
                    var_dump($values); */
                    array_unshift($values, $i);
                    $errors[] = join(",", $values);
                } else {
                    $data[] = array_combine($header, $values);
                }
            }
        }

        return ["items" => $data, "errorRows" => join("\n", $errors)];
    }

    /**
     * execute table
     * @param  string   $tableName
     * @param  string   $partitionKey
     * @param  object   $postBody
     * @return object
     */
    public function execTable($tableName, $partitionKey, $postBody)
    {
        $errors = array();
        // Create list of batch operation.
        $operations = new BatchOperations();

        $nameRegex = "/^[a-z][a-z0-9]{2,62}$/";
        // validate table name
        if (!preg_match($nameRegex, $tableName)) {
            $errors[] = ["message" => "invalid tableName '$tableName' value"];
        }

        // validate partition key
        $nameRegex = "/[a-zA-Z0-9-_\.\~\,\! ]+/";
        if (!preg_match($nameRegex, $partitionKey)) {
            $errors[] = ["message" => "invalid PartitionKey '$partitionKey' value"];
        }

        if (!isset($postBody['items'])) {
            $errors[] = ["message" => "items array is required"];
        }
        
        $items      = $postBody['items'];
        $itemsCount = count($items);
        if ($itemsCount > 100) {
            $errors[] = ["message" => "expected items count to be less than 100 but got " . $itemsCount];
        }

        $entity = null;
        $rst    = [
            "tableName"    => $tableName,
            "PartitionKey" => $partitionKey,
            "body"         => $postBody,
            "errors"       => $errors,
            "count"        => $itemsCount,
        ];

        if (count($errors) <= 0) {
            // loop through post body
            foreach ($items as $i => $item) {
                if (count($errors) > 100) {
                    break;
                }

                // validate RowKey
                if (!isset($item['RowKey'])) {
                    // convert id to RowKey
                    if (isset($item['id'])) {
                        $item['RowKey'] = $item['id'];
                    }

                    // convert ean or upc to RowKey
                    if (isset($item['ean'])) {
                        $item['RowKey'] = str_pad($item['ean'], 13, '0', STR_PAD_LEFT);
                    } elseif (isset($item['upc'])) {
                        $item['RowKey'] = str_pad($item['upc'], 13, '0', STR_PAD_LEFT);
                    }

                    if (!isset($item['RowKey'])) {
                        $errors[] = ["message" => "$i required a RowKey"];
                        continue;
                    }
                }

                $rowKey = $item['RowKey'];
                if (!preg_match($nameRegex, $rowKey)) {
                    $errors[] = ["message" => "$i has invalid RowKey: $rowKey"];
                }

                if (isset($item['delete'])) {
                    $operations->addDeleteEntity($rst['tableName'], $partitionKey, $rowKey);
                } else {
                    $entity = new Entity();
                    $entity->setPartitionKey($partitionKey);
                    $entity->setRowKey($rowKey);

                    foreach ($item as $key => $value) {
                        $excludes = array("delete", "RowKey", "PartitionKey", "Timestamp");
                        if (in_array($key, $excludes)) {
                            continue;
                        }

                        if (!preg_match('/[a-zA-Z0-9-_\.\~\,]+/', $key)) {
                            $errors[] = ["message" => "$i column name $key is invalid"];
                            continue;
                        }

                        $entity->addProperty($key, null, $value);
                    }

                    $operations->addInsertOrMergeEntity($rst['tableName'], $entity);
                }
            }
        }

        if (count($errors) <= 0) {
            try {
                $today = new \DateTime();

                if ($itemsCount <= 1) {
                    $rst['item'] = $items[0];
                }
                
                if (isset($postBody['notifyQueue'])) {
                    unset($rst['item']);
                    // reserve '10' to sort at top, reserve 0n for other things
                    $jobTable = 'a10job' . $today->format('Ymd');
    
                    // use a max number that is smaller than 32bit int to keep consistency
                    $jobPartitionKey = str_pad((2000000000 - time()) . "", 10, '0', STR_PAD_LEFT);
                    $rst["jobTable"] = $jobTable;
                    $rst["jobId"]    = $jobPartitionKey;
                    $jobMessage      = json_encode($rst);
    
                    // insert import data, must be < 640KB?
                    $jobEntity = new Entity();
                    $jobEntity->setPartitionKey($jobPartitionKey . '');
                    $jobEntity->setRowKey($rst['tableName'] . "-" . $partitionKey);
                    $jobEntity->addProperty("Message", null, $jobMessage);
                    $this->tableRestProxy($jobTable, $errors)->insertEntity($jobTable, $jobEntity);
                    
                }
                
                unset($rst['body']);

                // main action
                if (isset($rst['item'])) {
                    // handle single update
                    $item = $rst['item'];

                    if (isset($item['delete'])) {
                        $this->tableRestProxy($rst['tableName'], $errors)
                            ->deleteEntity($rst['tableName'], $partitionKey, $item['RowKey']);
                    } else {
                        $this->tableRestProxy($rst['tableName'], $errors)
                            ->insertOrMergeEntity($rst['tableName'], $entity);
                    }
                } else {
                    // handle multi-update
                    $this->tableRestProxy($rst['tableName'], $errors)->batch($operations);
                }

                // if this should trigger queue that handle webhooks
                if (isset($postBody['notifyQueue'])) {
                    $queueName = $postBody['notifyQueue'];
                    $this->enqueue($queueName, $rst);
                }
            } catch (ServiceException $e) {
                $errors[] = ["message" => $e->getMessage(), "code" => $e->getCode()];
            }
        }
        
        $rst['errors'] = $errors;

        return $rst;
    }

    /**
     * get tenant code
     */
    public function getTenantCode(&$errors)
    {
        $f3     = $this->f3;
        $tenant = $f3->get('GET.tenant');
        if (empty($tenant)) {
            $tenant = 'a';
        }

        // validate tenant
        if (!preg_match('/^[a-z]+$/', $tenant)) {
            $errors[] = ["message" => "invalid tenant '$tenant' value"];
        }

        return $tenant;
    }

    /**
     * get table rest body
     */
    public function tableRestProxy($tableName, &$errors)
    {
        $proxy = ServicesBuilder::getInstance()->createTableService($this->connectionString);
        try {
            // check cache
            $cache = \Cache::instance();
            if ($cache->exists("aztable-" . $tableName)) {
                return $proxy;
            }

            // Create table if not exists.
            $proxy->createTable($tableName);
        } catch (ServiceException $e) {
            $errors[] = ["message" => $e->getMessage(), "code" => $e->getCode()];
        }

        $cache->set("aztable-" . $tableName, true, $this->getOrDefault("ttl.aztable", 600));
        return $proxy;
    }

    /**
     * insert queue message
     */
    public function enqueue($queueName, $rst)
    {
        $proxy = ServicesBuilder::getInstance()->createQueueService($this->connectionString);
        try {
            $jobMessage = json_encode($rst);

            // error may occur if queue does not exists
            // must base64 encode to ensure MS cloud
            // storage explorer visibility
            $proxy->createMessage($queueName, base64_encode($jobMessage));
        } catch (ServiceException $e) {
            $errors[] = ["message" => $e->getMessage(), "code" => $e->getCode()];
        }
    }
}
