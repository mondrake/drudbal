<?php

/**
 * @file
 * Reports Drupal database installation status.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$autoloader = require_once $root_path . '/autoload.php';

DrupalKernel::bootEnvironment($root_path);
$kernel = new DrupalKernel('prod', $autoloader);
$kernel->setSitePath('sites/default');
Settings::initialize($root_path, 'sites/default', $autoloader);
$kernel->boot();

require_once $root_path . '/core/includes/install.inc';

$connection = Database::getConnection();
$connection_info = Database::getConnectionInfo();
$task_class = $connection_info['default']['namespace'] . "\\Install\\Tasks";
$installer = new $task_class();
print("------------------------------------------------------------------------------------------------\n");
print("Installation info\n");
print("PHP         : " . phpversion() . "\n");
print("Drupal      : " . \Drupal::VERSION . "\n");
print("Database    : " . $installer->name() . "\n");
print("Version     : " . $connection->version() . "\n");
print("------------------------------------------------------------------------------------------------\n");
print("Connection options:\n");
dump($connection_info['default']);
print("------------------------------------------------------------------------------------------------\n");
print("Drupal db connection URL:\n");
print($connection->createUrlFromConnectionOptions($connection_info['default']) . "\n");
print("------------------------------------------------------------------------------------------------\n");
