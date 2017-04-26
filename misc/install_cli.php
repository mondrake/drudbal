<?php

/**
 * @file
 * Initiates a command line installation of Drupal.
 */

// Change the directory to the Drupal root.
chdir('..');
// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

$settings = array(
  'parameters' => array(
    'profile' => 'standard',
    'locale' => 'en',
  ),
  'forms' => array(
    'install_settings_form' => array(
      'driver' => 'dbal',
      'dbal_url' => "mysql://root:@127.0.0.1/drupal_travis_db",
    ),
    'install_configure_form' => array(
      'site_name' => 'drudbal',
      'site_mail' => 'drudbal@drudbal.com',
      'account' => array(
        'name' => 'admin',
        'mail' => 'drudbal@drudbal.com',
        'pass' => array(
          'pass1' => 'adminpass',
          'pass2' => 'adminpass',
        ),
      ),
      'update_status_module' => array(
        1 => TRUE,
        2 => TRUE,
      ),
    ),
  ),
);

// Start the installer.
require_once $root_path . '/core/includes/install.core.inc';
install_drupal($class_loader, $settings);
