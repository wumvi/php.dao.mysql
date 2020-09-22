```bash
docker run --name mysql-test -e MYSQL_ROOT_PASSWORD=my-secret-pw -d -p 3316:3306 mysql:8.0.21
```

root
my-secret-pw


```mysql
create database recall_request;
create table recall_request
(
    id int,
    url varchar(255) not null,
    method varchar(4) not null,
    data text not null,
    attempt int default 0 not null,
    last_error text null
)
    comment 'Хранит данные для повторных вызовов';

create unique index recall_request_id_uindex
    on recall_request (id);

alter table recall_request
    add constraint recall_request_pk
        primary key (id);

alter table recall_request modify id int auto_increment;
```


```mysql
CREATE USER 'service'@'%' IDENTIFIED BY 'service';
GRANT ALL PRIVILEGES ON *.* TO 'service'@'%';
FLUSH PRIVILEGES;
```