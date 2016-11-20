# importaz
We were looking for a cheap, low to no maintenance (cloud), and high performance document database that will notify you when a record has changed.  

# Goal
This library aim to create a RESTful service endpoint to extend Azure Storage, especially Azure Table Storage.

* Ability to CRUD and perform BULK of Azure Table Storage in a single method.
We struggle with this at the beginning, but after much considerations, we realized that we don't really need/want to import very large amount of records.  Since we would like to notify all changes, having large bulk import make it harder to notify changes down the road.

* Ability to notify record change with Azure Storage Queue.
This is going to be really easy with Azure Storage Queue trigger integration on Azure Cloud Functions.

* Secure API endpoint.
Security is of utmost important.  We start with a very basic HMAC signing security.  This allow for easy integration on any Serverless platform.  Example: 
1. We can insert some big file into s3.
2. This trigger a lambda function to perform import.
3. We split the file into multiple of 100 items and import each sequentially.
4. Success result in an original-file-name-batch-n-of-x.log
5. Failure result in filename-batch-n-of-x.err.  This file maybe queued up for retry at a later time.
6. We have audit logs of our import.

* For future enhancements, we may build other microservices to handle better API Authentication.  We can simply use Firebase, Amazon Cognito with Lambda, or even Mashape Kong.

To run:
```
php -S 0.0.0.0:8888 -t public
```

To deploy:
```
composer install
composer update --no-dev
```

Example:
```
curl -i -X POST -H "Content-Type: application/json" http://localhost:8888/api/table/test/a123 -d  '{
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
}' -H "X_AUTH: hmac:timestamp_in_seconds,valid_duration_in_seconds:our_hmac_signature"
```

## Why? TLDR;
1. Cloud - because we don't want to manage it
2. High Performance - because we want to scale and would like to support multitenancy, someday.
3. Cheap - because we want to help our client save $$$
4. Bulk - quickly import data
5. Notification - we need the ability to trigger, webhook, and possibly index on record change, and so on...

There are so many BaaS out there that support this, such as Stamplay; but after witnessing how Parsed shutdown, we decided to limit our research to the big three Cloud Providers: Azure, AWS, and Google.  After much research, it was down to:


AWS - SimpleDB and DynamoDB.

Azure - Table Storage and DocumentDB.

Google -  Bigtable, Firebase, and DataStore

We ruled out DynamicDB, DocumentDB, Bigtable, and DataStore due to their cost running node/instance cost.  SimpleDB seem to be dead.  What left are Firebase and Azure Table Storage.  

At first, Firebase seem to be great.  It provides a lot of features out of the box.  Since it's a live database, it can easily notify you on document changes.  It already have integration with Elastic Search by using Flashlight.  Unfortunately, research shows that it's kind of quirky and doesn't perform well on the server-side.  There are also report of performance issue even with a single tenant.  Through process of elimination, we're left with Azure Table Storage.

We have not completely ruled out Firebase yet.  We loved the tight integration with authentication, authorization, and analytics.  We may use it in the front-end some day; but for now, we decided to work with Azure Table Storage.

Along the way, we also looked at Cloudant by IBM.  One biggest advantage of Cloudant is that it has Elastic Search built-in.  It has potential but it's still a little on the high price range.  The lesson of Parsed is not about how small BaaS cannot survive.  Parsed was own by Facebook so they are not small.  It is about how you cannot bet on just one BaaS.  It requires this kind of research to record possible options, Cloudant or Firebase, if Azure Table Storage no longer work for us in the future.  

#License

MIT - Copyright © 2016 niiknow friends@niiknow.org

THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.