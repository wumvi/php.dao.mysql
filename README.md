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
