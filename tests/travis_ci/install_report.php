<?php

/**
 * @file
 * Reports Drupal database installation status.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;

// Change the directory to the Drupal root.
chdir('..');

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

print("------------------------------------------------------------------------------------------------\n");
dump(Database::getAllConnectionInfo());
print("------------------------------------------------------------------------------------------------\n");
$connection = Database::getConnection();
$installer = db_installer_object($connection->driver());
print("------------------------------------------------------------------------------------------------\n");
print("Installation: OK\n");
print("Database    : " . $installer->name() . "\n");
print("Version     : " . $connection->version() . "\n");
print("------------------------------------------------------------------------------------------------\n");
