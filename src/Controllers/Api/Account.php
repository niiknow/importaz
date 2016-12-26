<?php

namespace Controllers\Api;

class Account extends \Controllers\Api\AzureTable
{
    /**
     * get all accounts
     * search by partition with rowkey - _config
     */
    public function getAllAccounts()
    {
        $this->params['tableName'] = 'account';
        $excludeDeleted            = $this->getOrDefault('GET.excludeDeleted', '');
        $query                     = "RowKey eq '_config'";
        if (!empty($excludeDeleted)) {
            $query = "$query and deleteAt eq ''";
        }

        return $this->execQuery($query);
    }

    /**
     * get account by name
     * - admins column: all email of admins
     * - modules column: all modules that account has access to
     */
    public function getAccountByName()
    {
        $this->params['tableName'] = 'account';
        $name                      = $this->params["name"];

        return $this->execQuery("", 1000, null, $name, '_config');
    }

    /**
     * get all users for account
     * - email column - raw email in lowered case
     */
    public function getAccountUsers()
    {
        $this->params['tableName'] = 'account';
        $name                      = $this->params["name"];

        // return $this->execQuery("PartitionKey eq '$name' and RowKey ne '_config'");
        return $this->execQuery("", 1000, null, $name);
    }

    /**
     * get a particular user (permissions) by email
     * - permissions column: csv of all permission to all modules
     * - email column - raw email in lowered case
     */
    public function getAccountByUserEmail()
    {
        $this->params['tableName'] = 'account';
        $name                      = $this->params["name"];
        $email                     = $this->getOrDefault('GET.email', '');
        if (empty($email)) {
            $this->json(["error" => "email query argument is required"], ["http_status" => 400]);
            return;
        }
        // slugify user
        $user = Web::instance()->slug(trim($email));

        // return $this->execQuery("PartitionKey eq '$name' and RowKey eq '$user'");
        return $this->execQuery("", 1000, null, $name, $user);
    }

    /**
     * create Or Update Account permission for a user email
     * - permissions column: csv of all permission to all modules
     * - email column - raw email in lowered case
     */
    public function createOrUpdateAccountPermissionForUserEmail()
    {
        $this->params['tableName'] = 'account';
        $name                      = $this->params["name"];
        $email                     = $this->getOrDefault('GET.email', '');
        if (empty($email)) {
            $this->json(["error" => "email query argument is required"], ["http_status" => 400]);
            return;
        }

        // slugify user
        $user = Web::instance()->slug(trim($email));

        $postBody           = json_decode($this->f3->BODY, true);
        $postBody['RowKey'] = $user;
        $postBody['email']  = strtolower($email);
        $data               = [
            "items" => [$postBody],
        ];
        $result = $this->execTable("account", $name, $postBody);
        return $this->json($result);
    }

    /**
     * create or update account
     */
    public function createOrUpdateAccount($deleteAt = '')
    {
        $name     = $this->params["name"];
        $postBody = [];
        if (!isempty($this->f3->BODY)) {
            $postBody = json_decode($this->f3->BODY, true);
        }

        $postBody['RowKey']   = "_config";
        $postBody['deleteAt'] = $deleteAt;
        $data                 = [
            "items" => [$postBody],
        ];
        $result = $this->execTable("account", $name, $postBody);
        return $this->json($result);
    }

    /**
     * delete account
     */
    public function deleteAccount()
    {
        return $this->createOrUpdateAccount(date('Y-m-d\TH:i:s'));
    }
}
