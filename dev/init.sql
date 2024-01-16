create
database test;
use
test;
CREATE
USER 'replica1'@'%' IDENTIFIED BY '123';
GRANT ALL PRIVILEGES ON test.* TO
'replica1'@'%';
FLUSH
PRIVILEGES;

create table test_table
(
    id int null
);

create table table_for_insert
(
    id1 int null,
    id2 varchar(30) null
);

create procedure test_proc()
begin
select CURRENT_USER();
end;

create table test.test_deadlock_table
(
    id  int not null primary key,
    val int null
);

insert into test_deadlock_table (id, val)
values (1, 1),
       (2, 2),
       (3, 3),
       (4, 4);

create table test_duplicate
(
    id int not null,
    constraint test_duplicate_pk
        primary key (id)
);

create
definer = root@`%` procedure test_duplicate()
begin
start transaction;
insert into test_duplicate(id)
values (1);
insert into test_duplicate(id)
values (1);
commit;
end;

