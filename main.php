<?php

use Websyspro\Core\Shareds\Database\MySQLDriver;


$mysqlDriver = new MySQLDriver(
	"localhost", 3309, "root", "qazwsx", "test"
);

$mysqlDriver->connect();

$mysqlDriver->execute("create table if not exists test.tbTest(id bigint not null primary key auto_increment, description varchar(255))engine=innodb;");

$execute = $mysqlDriver->execute("insert into tbTest (description) values('{$mysqlDriver->serverVersion}')");

$result = $mysqlDriver->query("select * from tbTest");

print_r($result);