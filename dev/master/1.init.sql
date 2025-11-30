create database test;
use test;

CREATE USER 'replica'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'pwd';
GRANT REPLICATION SLAVE ON *.* TO 'replica'@'127.0.0.1';

CREATE USER 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'pwd';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;

FLUSH PRIVILEGES;