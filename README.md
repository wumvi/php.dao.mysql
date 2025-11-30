```bash
docker run -ti \
    --rm \
    -v "$(pwd)":/data/ \
    -e APP_ENV=dev \
    --workdir /data/ \
    --network host \
    --add-host mysqltest:192.168.1.96 \
    dfuhbu/php8.3.1-cli-dev:1 \
    bash
```


SELECT @@server_id


XDEBUG_MODE=coverage ./vendor/bin/phpunit --filter testDeadLockException phpunit/tests/ConnectionTest.php
XDEBUG_MODE=coverage ./vendor/bin/phpunit --filter testConstructor phpunit/tests/ConnectionTest.php
XDEBUG_MODE=coverage ./vendor/bin/phpunit --filter testCustomTextException phpunit/tests/ConnectionTest.php