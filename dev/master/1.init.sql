create database test;
use test;

# ========================= Table =========================

create table table_for_insert
(
    id1 int         null,
    id2 varchar(30) null
);

create table test_deadlock_table
(
    id  int not null primary key,
    val int null
);

create table test_duplicate
(
    id int not null primary key
);

create table test_insert_param
(
    num      int         null,
    str      varchar(32) null,
    json     json        null,
    for_null int         null,
    bool     tinyint(1)  null,
    date     datetime    null
);

create table test_table
(
    id    int           null,
    value int default 0 null
);

# ========================= Procedure =========================

DELIMITER //

create definer = root@`127.0.0.1` procedure test_exception()
begin
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'custom-error';
end;

create procedure init_dead_lock_table(IN p_id1 int, IN p_id2 int)
begin
    update test_deadlock_table
    set val = 0
    where id in (p_id1, p_id2);
end;

create procedure test_dead_lock(IN p_id1 int, IN p_id2 int)
begin
    start transaction;
    update test_deadlock_table set val = val + 1 where id = p_id1;
    do sleep(1);
    update test_deadlock_table set val = val + 1 where id = p_id2;
    commit;
end;

create procedure test_duplicate_insert()
begin
    delete from test_duplicate where id = 1;
    insert into test_duplicate(id) values (1);
    insert into test_duplicate(id) values (1);
end;

create procedure test_proc()
begin
    select CURRENT_USER();
end;

create procedure test_sleep()
begin
    select 1 union select 2;
    select 3;
end;

//

DELIMITER ;

# ========================= Data =========================

insert into test_deadlock_table (id, val)
values (1, 0),
       (2, 0),
       (3, 0),
       (4, 0);

insert into test_table (id, value)
values (1, 0),
       (2, 1);

# ========================= Users =========================

CREATE USER 'replica'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'pwd';
GRANT REPLICATION SLAVE ON *.* TO 'replica'@'127.0.0.1';

CREATE USER 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'pwd';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;

FLUSH PRIVILEGES;