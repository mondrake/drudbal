<?php

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * @file
 * Creates a test schema on the Oracle database.
 */

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

// Create Oracle database
$tmpConnection = DriverManager::getConnection(["url" => "pdo-oci://system:oracle@0.0.0.0:1521/XE"]);
$tmpConnection->executeStatement('CREATE USER DRUDBAL IDENTIFIED BY ORACLE');
$tmpConnection->executeStatement('GRANT DBA TO DRUDBAL');
$tmpConnection->close();

/*
$tmpConnection = DriverManager::getConnection(["url" => "oci8://DRUDBAL:ORACLE@0.0.0.0:1521/XE"]);

$table = new Table('"tester"');
$table->addColumn('"id"', Types::INTEGER);
$table->addColumn('"name"', Types::STRING);
$table->addColumn('"test_field"', Types::STRING);

$schemaManager = $tmpConnection->createSchemaManager();
$schemaManager->dropAndCreateTable($table);

$current_schema = $schemaManager->introspectSchema();
$to_schema = clone $current_schema;
$to_schema->getTable('"tester"')->dropColumn('"test_field"');
$schema_diff = (new Comparator())->compareSchemas($current_schema, $to_schema);
foreach ($schema_diff->toSql($tmpConnection->getDatabasePlatform()) as $sql) {
  print($sql . "\n");
  $tmpConnection->executeStatement($sql);
}
*/
