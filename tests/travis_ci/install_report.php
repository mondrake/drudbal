<?php

/**
 * @file
 * Reports Drupal database installation status.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;

// Change the directory to the Drupal root.
chdir('..');

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$autoloader = require_once $root_path . '/autoload.php';

DrupalKernel::bootEnvironment($root_path);
$kernel = new DrupalKernel('prod', $autoloader);
$kernel->setSitePath('sites/default');
$kernel->boot();

require_once $root_path . '/core/includes/install.inc';

$installer = db_installer_object('dbal');
print("-----------------------------------------------------------\n");
print("Installation: OK\n");
print("Database    : " . $installer->name() . "\n");
print("Version     : " . Database::getConnection()->version() . "\n");
print("-----------------------------------------------------------\n");
