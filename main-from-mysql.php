<?php

use Websyspro\Core\Shareds\Database\MySQLDriver;


$mysqlDriver = new MySQLDriver(
	"localhost", 3306, "root", "@Qazwsx190483", "test"
);

$mysqlDriver->connect();

$mysqlDriver->execute("create table if not exists test.tbTest(id bigint not null primary key auto_increment, description varchar(255))engine=innodb;");

$execute = $mysqlDriver->execute("insert into tbTest (description) values('{$mysqlDriver->serverVersion}')");

// Teste de transação
//$mysqlDriver->startTransaction();
$mysqlDriver->execute(
	"insert into tbTest (description) VALUES (?)", 
	[ "Transação 3" ]
);
print_r( 
	$mysqlDriver->query(
		"select * from tbTest where description=?", 
		[ "Transação 3" ]
	)
);
//$mysqlDriver->commit();
echo "End";