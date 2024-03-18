<?php

// NOTE - THIS FILE IS CURRENTLY UNUSED, INSTALLATION OCCURS VIA DRUPAL CONSOLE.

/**
 * @file
 * Cleans up Drupal tables.
 */

use Doctrine\DBAL\DriverManager as DbalDriverManager;
use Doctrine\DBAL\Tools\DsnParser;

// Change the directory to the Drupal root.
chdir('..');
// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

$dbal_connection = DbalDriverManager::getConnection((new DsnParser())->parse(getenv("DBAL_URL")));
echo($dbal_connection->getNativeConnection()->getServerVersion() . "\n");

// Drop all tables.
$tables = $dbal_connection->createSchemaManager()->listTableNames();
foreach ($tables as $table) {
  $dbal_connection->createSchemaManager()->dropTable($table);
  echo('Dropped ' . $table . "\n");
}
