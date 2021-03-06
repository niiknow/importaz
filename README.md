# importaz
In search for a cheap, low to no-maintenance (cloud), and high performance document database that will notify you when a record has changed.  

# Goal
This library aim to create a RESTful service endpoint to extend Azure Storage, especially Azure Table Storage.

* Ability to CRUD and perform BULK of Azure Table Storage in a single method.  Azure Table Storage BULK limit you to only 100 records batch.

* Ability to notify record change with Azure Storage Queue.
This is going to be really easy since Azure Storage Queue can trigger Azure Cloud Functions.

* Secure API endpoint.
Security is of utmost important.  We start with a very basic HMAC signing security.  This allow for easy integration on any Serverless platform.  Example: 
    1. You can insert some big file into s3.
    2. This trigger a lambda function to perform import.
    3. You would split the file into multiple of 100 items and import each sequentially.
    4. Success result in an original-file-name-batch-n-of-x.log
    5. Failure result in filename-batch-n-of-x.err.  This file maybe queued up for retry at a later time.
    6. Finally, there will be audit logs of the import.

* For future enhancements, you can build other microservices to handle better API Authentication.  You can simply use Firebase, Amazon Cognito with Lambda, or even Mashape Kong.

To run:
```
php -S 0.0.0.0:8888 -t public
```

To deploy:
```
composer install
composer update --no-dev --optimize-autoloader
```

Example:
```
curl -i -X POST -H "Content-Type: application/json" http://localhost:8888/api/table/test -d  '{
    "notifyQueue": "test2",
    "items": [{
        "id": "123",
        "blah": "blah",
        "col2": "hi"
    },{
        "id": "456",
        "blah": "blah",
        "col2": "hi"
    }]
}' -H "X_AUTH: timestamp_in_seconds:valid_duration_in_seconds:apiUserName:our_hmac_signature_in_base64"
```

## API explain
https://github.com/niiknow/importaz/blob/master/config/routes-api.ini

### POST /api/table/exec/@tableName?pk=partitionKey&tenant=tenantCode
* @tableName - the table to perform operation
By convention, we introduced three additional parameter/features:

1. *pk* - partition key, default is '1default'.  Allow for multiple workspaces.
2. *tenant* - the tenant code, default is 'a'.  Allow for multi-tenancy.
3. *environment* - are identified as a number in the table name: dev (79), tst (77), uat (75), stg (73), and prd (71).  This reserved a00-a69 for internal table naming.  These tables would, obviously, be sorted at the top.  Environment can be setup in a file called config/config.ini, which has example and documenation here (https://github.com/niiknow/importaz/blob/master/config/config.example.ini) 

POST BODY:
``` json
 { "items" : [...], "notifyQueue": "queueName" }
```

Example, let say you have the following parameters: @tableName ('products'), @pk (empty), @tenant ('acme'), and @environment ('prd').

Your destination table would be: *acme71products*

All items will be imported into the "_default" partition.  All items (including *delete*) must include one of the following required fields: *rowKey*, *id*, *ean*, or *upc*.

To delete an item, include property called *delete* and set to true or anything.

### POST /api/table/execsv/@tableName?pk=partitionKey&tenant=tenantCode
POST a CSV content to this endpoint to import.  First row must be header row.  The CSV content will be converted to the *items* array and POST to the previous endpoint.

### DELETE /api/table/@tableName/@partitionKey?notifyQueue=queueName
This method is to help bulk delete a table partition.  It returns the result with *hasMoreItems* value set.
Client can keep calling this method until *hasMoreItems* is false.

### GET /api/table/query/@tableName
This is an OData endpoint with parameters:
1. *tenant* - default 'a'
2. *$filter* - the filter
3. *$select* - fields to get
4. *$top* - number of records to get
5. *nextpk* - next continuation/paging primary key
6. *nextrk* - next continuation/paging row key

## Why? TLDR;
1. Cloud - because we don't want to manage it
2. High Performance - because we want to scale and would like to support multitenancy, someday.
3. Cheap - because we want to help our client save $$$
4. Bulk - quickly import data
5. Notification - we need the ability to trigger, webhook, and possibly index on record change, and so on...

There are so many BaaS out there that support this, such as Stamplay; but after witnessing Parsed shutdown, we decided to limit our research to the  three big Cloud Providers: Azure, AWS, and Google.  This result in the following options:

AWS - SimpleDB and DynamoDB.

Azure - Table Storage and DocumentDB.

Google -  Bigtable, Firebase, and DataStore

We ruled out DynamicDB, DocumentDB, Bigtable, and DataStore due to their  running node/instance cost.  SimpleDB seem to be dead.  What left are Firebase and Azure Table Storage.  

At first, Firebase seem to be great.  It provides a lot of features out of the box.  Since it's a live database, it can easily notify you on document changes.  It already have integration with Elastic Search by using Flashlight.  Unfortunately, research shows that it's kind of quirky and doesn't perform well on the server-side.  There are also report of performance issue even on a single tenant.  Through process of elimination, we're left with Azure Table Storage.

We have not completely ruled out Firebase yet.  We loved the tight integration with authentication, authorization, and analytics.  We may use it in the front-end some day; but for now, we decided to work with Azure Table Storage.

Along the way, we also looked at Cloudant by IBM.  The biggest advantage of Cloudant is that it has Elastic Search built-in.  It has alot of potentials but it's also little known and is on the high price range.  

The lesson of Parsed is not about how small BaaS cannot survive.  Parsed was actually not small, but was quite the opposite because it was backed by Facebook.  The lesson was about how you cannot bet on just one BaaS.  It requires this kind of research to record possible options, Cloudant or Firebase, if Azure Table Storage no longer work for us in the future.  

#License

MIT - Copyright © 2016 niiknow friends@niiknow.org

THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.