# importaz
import to azure helpers

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
}' -H "X_AUTH: hmac:timestamp_in_seconds:somesignuature"
```
