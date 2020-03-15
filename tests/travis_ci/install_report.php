<?php

/**
 * @file
 * Reports Drupal database installation status.
 */

use Drupal\Core\Database\Database;

// Change the directory to the Drupal root.
chdir('..');

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

require_once $root_path . '/core/includes/install.core.inc';
$installer = db_installer_object('dbal');
print("Installation OK\n");
print("Database: " . $installer->name() . "\n");
print("Version : " . Database::getConnection()->version() . "\n");
