<?php

/**
 * @file
 * Initiates a command line installation of Drupal.
 */

// Some minimal values for $_SERVER.
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['HTTP_HOST'] = 'localhost';

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

$settings = [
  'parameters' => [
    'profile' => getenv("DRUDBAL_DRUPAL_PROFILE"),
    'locale' => 'en',
  ],
  'forms' => [
    'install_settings_form' => [
      'driver' => 'dbal',
      'dbal' => [
        'prefix' => 'dru',
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
install_drupal($class_loader, $settings);
