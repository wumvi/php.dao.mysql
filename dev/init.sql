create
database test;
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

create table table_for_insert (id1 int null, id2 varchar(30) null);

create procedure test_proc()
begin
select CURRENT_USER();
end;
