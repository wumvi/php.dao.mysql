mysql -uroot --socket=/var/run/mysqld/mysqld.sock -ppwd < /dump/dump.sql
mysql -uroot --socket=/var/run/mysqld/mysqld.sock -ppwd <<EOF
CHANGE REPLICATION SOURCE TO
    SOURCE_HOST='127.0.0.1',
    SOURCE_USER='replica',
    SOURCE_PASSWORD='pwd',
    SOURCE_PORT=3432,
    SOURCE_AUTO_POSITION=1;
START REPLICA;
EOF