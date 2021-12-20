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

dump(Database::getAllConnectionInfo());
$connection = Database::getConnection();
$connection_info = Database::getConnectionInfo();
$installer = db_installer_object($connection->driver(), $connection_info['default']['namespace']);
print("------------------------------------------------------------------------------------------------\n");
print("Installation: OK\n");
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
