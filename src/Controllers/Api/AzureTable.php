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
        $rst          = $this->execTable($workspace, $tableName, $partitionKey, $postBody, $isDelete);
        $this->json($rst);
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
        $csv       = $this->csvToArray($csvString);
        $this->execAction(["items" => $csv]);
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

        foreach ($lines as $line) {
            // fix product data issue
            $myLine = str_replace(", ", ";", $line);
            $myLine = str_replace(",", "|", $myLine);
            $myLine = str_replace(";", ", ", $myLine);
            $values = str_getcsv($myLine, '|', $enclosure, $escape);

            if (!$header) {
                $header = $values;
            } else {
                $data[] = array_combine($header, $values);
            }
        }

        return $data;
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

        $items = $postBody['items'];
        if (count($items) > 100) {
            $errors[] = "expected items count to be less than 100 but got " . count($items);
        }

        $env = $this->envId();
        $rst = ["tableName" => $workspace . $env . $tableName, "partitionKey" => $partitionKey, "body" => $postBody, "errors" => $errors];
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
                    if (count($items) > 1) {
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

                // perform actual insert
                $this->tableRestProxy($rst['tableName'])->batch($operations);

                // if this should trigger queue that handle webhooks
                if (isset($postBody['notifyQueue'])) {
                    $this->enqueue($postBody['notifyQueue'], $rst);
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
     * get two digit that identify the environment
     * @return string
     */
    public function envId()
    {
        $env = $this->getOrDefault('app.env', 'dev');

        // use 3 to prevent system table conflict
        $rst = '3';
        if ($env == 'dev') {
            return $rst . '1';
        } elseif ($env == 'tst') {
            return $rst . '3';
        } elseif ($env == 'uat') {
            return $rst . '5';
        } elseif ($env == 'stg') {
            return $rst . '7';
        } elseif ($env == 'prd') {
            return $rst . '9';
        }

        return $rst . '0';
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
