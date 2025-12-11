<?php

use Websyspro\Core\Shareds\Database\MariaDBDriver;
use Websyspro\Core\Shareds\Database\MySQLDriver;


$mysqlDriver = new MySQLDriver(
	"localhost", 
	3308, 
	"root", 
	"@Qazwsx190483"
);

$connectResult = $mysqlDriver->connect();

if($connectResult->connected === false ){
	print_r($connectResult);
	exit();
}

$queryResult = $mysqlDriver->query(
	"show databases",
	[]
);

$mysqlDriver->execute(
	"use test"
);

print_r($executeResult);

$mysqlDriver->execute(
	"create table if not exists test.tbTest(
		id bigint not null primary key auto_increment,
		description varchar(255)
	)engine=innodb;"
);

$queryResult = $mysqlDriver->query(
	"select * from tbTest where description=?",
	[ $mysqlDriver->serverVersion ]
);

if( $queryResult->exists() === false ) {
	$mysqlDriver->execute(
		"insert into tbTest (description) values(?)", 
		[ $mysqlDriver->serverVersion ]
	);	
}

$queryResult = $mysqlDriver->query(
	"select * from tbTest", []
);

print_r($queryResult);