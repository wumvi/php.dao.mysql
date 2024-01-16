```bash
docker run -ti \
    --rm \
    -v "$(pwd)":/data/ \
    -e APP_ENV=dev \
    --workdir /data/ \
    --network host \
    --add-host mysqltest:192.168.1.96 \
    dfuhbu/php8.3.1-cli:1 \
    bash
```


Commands out of sync; you can't run this command now in /data/src/BaseDao.php on line 164
