<?php

require "./MySqlDriver.php";

$cli = new MySqlDriver("localhost", 3306, "root", "qazwsx");
$cli->connect();

// INSERT (execute)
$ok = $cli->execute("INSERT INTO test.solicitacoes (descriptor) VALUES ('CRIAR RELATORIOS')");
echo "Inserted ID: " . $ok['lastInsertId'] . " affected: " . $ok['affectedRows'] . PHP_EOL;

// SELECT
$res = $cli->query("SELECT id, descriptor FROM test.solicitacoes");
print_r($res);

$cli->close();