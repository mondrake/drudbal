<?php

/**
 * @file
 * Initiates a command line installation of Drupal.
 */

use Drupal\Component\Utility\Timer;
use Drupal\Core\Database\Database;

// Change the directory to the Drupal root.
chdir('..');

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

$settings = [
  'parameters' => [
    'profile' => 'standard',
    'locale' => 'en',
  ],
  'forms' => [
    'install_settings_form' => [
      'driver' => 'dbal',
      'dbal' => [
        'dbal_url' => getenv("DBAL_URL"),
      ],
    ],
    'install_configure_form' => [
      'site_name' => 'drudbal',
      'site_mail' => 'drudbal@drudbal.com',
      'account' => [
        'name' => 'admin',
        'mail' => 'drudbal@drudbal.com',
        'pass' => [
          'pass1' => 'adminpass',
          'pass2' => 'adminpass',
        ],
      ],
      'update_status_module' => [
        1 => TRUE,
        2 => TRUE,
      ],
    ],
  ],
];

// Start the installer.
require_once $root_path . '/core/includes/install.core.inc';
Timer::start('drudbal:setup');
install_drupal($class_loader, $settings);

// Report installation.
$installer = db_installer_object('dbal');
print("Installation OK\n");
print("Database: " . $installer->name() . "\n");
print("Version : " . Database->getConnection()->version() . "\n");
