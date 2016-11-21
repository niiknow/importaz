<?php

namespace Controllers\Api;

use MicrosoftAzure\Storage\Common\ServiceException;
use MicrosoftAzure\Storage\Table\Models\BatchOperations;
use MicrosoftAzure\Storage\Table\Models\Entity;
use WindowsAzure\Common\ServicesBuilder;

class AzureTable extends \Controllers\BaseSecuredController
{
    /**
     * execute table action
     * @param array $postBody
     */
    public function execAction($postBody)
    {
        $f3           = $this->f3;
        $params       = $this->params;
        $partitionKey = $params['partitionKey'];
        $tableName    = $params['tableName'];
        $workspace    = $f3->get('GET.workspace');
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
                $tran              = $this->execTable($workspace, $tableName, $partitionKey, $postBody, $isDelete);
                $transactions[]    = $tran;
                // $count += $result["count"];
                $errorCount += count($tran["errors"]);
                foreach ($tran as $k => $v) {
                    $result[$k] = $v;
                }
                // some random wait so server can catch up
                usleep(rand(10, 200));
            }
        } else {
            $errors = ["expected items count to be less than 1001 but got " . $itemsCount];
        }

        unset($result["errors"]);
        $result["errorCount"] = $errorCount;
        // $result["total"]      = $count;
        $result["count"] = $itemsCount;
        $result["trans"] = $transactions;

        if (isset($postBody["errorRows"])) {
            $result["errorRows"] = $postBody["errorRows"];
        }
        $this->json($result);
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
     * @param  string   $workspace
     * @param  string   $tableName
     * @param  string   $partitionKey
     * @param  object   $postBody
     * @return object
     */
    public function execTable($workspace, $tableName, $partitionKey, $postBody)
    {
        $errors = array();

        // Create list of batch operation.
        $operations = new BatchOperations();
        if (empty($workspace)) {
            $workspace = 'a';
        }

        // validate workspace
        if (!preg_match('/^[a-z]+$/', $workspace)) {
            $errors[] = "invalid workspace '$workspace' value";
        }

        $nameRegex = '/^[a-z][a-z0-9]{2,62}$/';
        // validate table name
        if (!preg_match($nameRegex, $tableName)) {
            $errors[] = "invalid tableName '$tableName' value";
        }

        // validate partition key
        if (!preg_match($nameRegex, $partitionKey)) {
            $errors[] = "invalid partitionKey '$partitionKey' value";
        }

        if (!isset($postBody['items'])) {
            $errors[] = "items array is required";
        }

        $items      = $postBody['items'];
        $itemsCount = count($items);
        if ($itemsCount > 100) {
            $errors[] = "expected items count to be less than 100 but got " . $itemsCount;
        }

        $entity     = null;
        $env        = $this->envId();
        $namePrefix = $workspace . $env;
        $rst        = [
            "tableName"    => $namePrefix . $tableName,
            "partitionKey" => $partitionKey,
            "body"         => $postBody,
            "errors"       => $errors,
            "namePrefix"   => $namePrefix,
            "count"        => $itemsCount,
        ];

        if (count($errors) <= 0) {
            // loop through post body
            foreach ($items as $i => $item) {
                if (count($errors) > 100) {
                    break;
                }

                // validate rowKey
                if (!isset($item['rowKey'])) {
                    // convert id to rowKey
                    if (isset($item['id'])) {
                        $item['rowKey'] = $item['id'];
                    }

                    // convert ean or upc to rowKey
                    if (isset($item['ean'])) {
                        $item['rowKey'] = str_pad($item['ean'], 13, '0', STR_PAD_LEFT);
                    } elseif (isset($item['upc'])) {
                        $item['rowKey'] = str_pad($item['upc'], 13, '0', STR_PAD_LEFT);
                    }

                    if (!isset($item['rowKey'])) {
                        $errors[] = "$i required a rowKey";
                        continue;
                    }
                }

                $rowKey = $item['rowKey'];
                if (!preg_match('/[a-zA-Z0-9-_\.\~\,]+/', $rowKey)) {
                    $errors[] = "$i has invalid rowKey: $rowKey";
                }

                if (isset($item['delete'])) {
                    $operations->addDeleteEntity($rst['tableName'], $partitionKey, $rowKey);
                } else {
                    $entity = new Entity();
                    $entity->setPartitionKey($partitionKey);
                    $entity->setRowKey($rowKey);

                    foreach ($item as $key => $value) {
                        if ($key === 'rowKey' || $key == 'partitionKey' || $key == 'delete') {
                            continue;
                        }

                        if (!preg_match('/[a-zA-Z0-9-_\.\~\,]+/', $key)) {
                            $errors[] = "$i column name $key is invalid";
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

                if (isset($postBody['notifyQueue'])) {
                    if ($itemsCount > 1) {
                        // user 00 to sort at top
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
                        $this->tableRestProxy($jobTable)->insertEntity($jobTable, $jobEntity);
                    } else {
                        $rst['item'] = $items[0];
                    }
                }
                unset($rst['body']);

                // main action
                if (isset($rst['item'])) {
                    // handle single update
                    $item = $rst['item'];

                    if (isset($item['delete'])) {
                        $this->tableRestProxy($rst['tableName'])
                            ->deleteEntity($rst['tableName'], $partitionKey, $item['rowKey']);
                    } else {
                        $this->tableRestProxy($rst['tableName'])
                            ->insertOrMergeEntity($rst['tableName'], $entity);
                    }
                } else {
                    // handle multi-update
                    $this->tableRestProxy($rst['tableName'])->batch($operations);
                }

                // if this should trigger queue that handle webhooks
                if (isset($postBody['notifyQueue'])) {
                    // if useNamePrefix is set, then apply prefix to queue name
                    $queueName = $postBody['notifyQueue'];
                    if (isset($postBody['useNamePrefix'])) {
                        $queueName = $rst['namePrefix'] . $queueName;
                    }
                    $this->enqueue($queueName, $rst);
                }
            } catch (ServiceException $e) {
                // Handle exception based on error codes and messages.
                // Error codes and messages are here:
                // http://msdn.microsoft.com/library/azure/dd179438.aspx
                $code            = $e->getCode();
                $error_message   = $e->getMessage();
                $rst['errors'][] = "main $code: $error_message";
            }
        }

        return $rst;
    }

    /**
     * get table rest body
     */
    public function tableRestProxy($tableName)
    {
        $proxy = ServicesBuilder::getInstance()->createTableService($this->connectionString);
        try {
            // Create table if not exists.
            $proxy->createTable($tableName);
        } catch (ServiceException $e) {
            $code            = $e->getCode();
            $error_message   = $e->getMessage();
            $rst['errors'][] = "createTable $code: $error_message";
        }
        $proxy = ServicesBuilder::getInstance()->createTableService($this->connectionString);
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
            // queue must be created ahead of time so it can be handled
            // otherwise, what is the point?
            $code            = $e->getCode();
            $error_message   = $e->getMessage();
            $rst['errors'][] = "enqueue $code: $error_message";
        }
    }
}
