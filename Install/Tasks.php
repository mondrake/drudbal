<?php

namespace Drupal\Driver\Database\drubal\Install;

use Drupal\Core\Database\Install\Tasks as InstallTasks;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Driver\Database\drubal\Connection as DrubalConnection;
use Doctrine\DBAL\DriverManager as DBALDriverManager;

/**
 * Specifies installation tasks for DRUBAL driver.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver specific code
 * in Drupal\Driver\Database\drubal\DBALDriver\[driver_name] classes and
 * execution handed over to there.
 */
class Tasks extends InstallTasks {

  /**
   * Constructs a \Drupal\Driver\Database\drubal\Install\Tasks object.
   */
  public function __construct() {
    $this->tasks[] = [
      'function' => 'runDBALDriverInstallTasks',
      'arguments' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function installable() {
    return empty($this->error);
  }

  /**
   * {@inheritdoc}
   */
  public function name() {
    try {
      $connection = Database::getConnection();
      return t('Doctrine DBAL on @database_type/@database_server_version via @dbal_driver', [
        '@database_type' => $connection->databaseType(),
        '@database_server_version' => $connection->getDbServerVersion(),
        '@dbal_driver' => $connection->getDBALDriver(),
      ]);
    }
    catch (\Exception $e) {
      return t('Doctrine DBAL');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function minimumVersion() {
    // Note: This is the minimum version of Doctrine DBAL; the minimum version
    // of the db server should be managed in
    // Drupal\Driver\Database\drubal\DBALDriver\[driver_name]::runInstallTasks.
    return '2.5.12';
  }

  /**
   * Check if we can connect to the database.
   */
  protected function connect() {
    try {
      // Just set the active connection to default. This doesn't actually
      // test the connection.
      Database::setActiveConnection();
      // Find the DBAL driver class to hand over connection checks to.
      $dbal_connection_info = Database::getConnectionInfo()['default'];
      if (empty($dbal_connection_info)) {
        throw new \Exception('No connection information available');
      }
      if (isset($dbal_connection_info['dbal_driver'])) {
        $dbal_connection_info['driver'] = $dbal_connection_info['dbal_driver'];
      }
      $dbal_connection = DBALDriverManager::getConnection($dbal_connection_info);
      $dbal_driver_class = DrubalConnection::getDBALDriverExtensionClass($dbal_connection->getDriver()->getName());
      $results = $dbal_driver_class::installConnect();
      foreach ($results['pass'] as $result) {
        $this->pass($result);
      }
      foreach ($results['fail'] as $result) {
        $this->fail($result);
      }
    }
    catch (\Exception $e) {
      $this->fail(t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist, and have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname?</li></ul>', ['%error' => $e->getMessage()]));
    }
    return empty($results['fail']);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormOptions(array $database) {
    $form = parent::getFormOptions($database);

    // Remove the options that only apply to client/server style databases.
    unset($form['database'], $form['username'], $form['password'], $form['advanced_options']['host'], $form['advanced_options']['port']);

    $form['url'] = [
      '#type' => 'textarea',
      '#title' => t('Database URL'),
      '#description' => t('@todo point to Doctrine docs http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html. MySql example: mysql://dbuser:password@localhost:port/mydb'),
      '#default_value' => empty($database['url']) ? '' : $database['url'],
      '#rows' => 3,
      '#size' => 45,
      '#required' => TRUE,
      '#states' => [
        'required' => [
          ':input[name=driver]' => ['value' => 'drubal'],
        ],
      ],
    ];
    $form['dbal_driver'] = [
      '#type' => 'hidden',
      '#title' => t('DBAL driver'),
      '#default_value' => empty($database['dbal_driver']) ? '' : $database['dbal_driver'],
      '#element_validate' => [[$this, 'validateDBALDriver']],
    ];

    return $form;
  }

  /**
   * @todo Validates the 'url' field of the installation form.
   */
  public function validateDBALUrl(array $element, FormStateInterface $form_state, array $form) {
    // At least some basic form of validation of the first component of the
    // URL, i.e. the DBAL driver.
  }

  /**
   * Validates the 'dbal_driver' field of the installation form.
   */
  public function validateDBALDriver(array $element, FormStateInterface $form_state, array $form) {
    // Opens a DBAL connection just to retrieve the actual DBAL driver being
    // used, so that it does get stored in the settings.
    try {
      $dbal_connection = DBALDriverManager::getConnection($form_state->getValue('drubal'));
      $form_state->setValue(['drubal', 'dbal_driver'], $dbal_connection->getDriver()->getName());
    }
    catch (\Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * Executes DBAL driver installation specific tasks.
   */
  public function runDBALDriverInstallTasks() {
    $connection = Database::getConnection();
    $dbal_driver = $connection->getDBALDriverExtensionClass($connection->getDBALDriver());
    $results = $dbal_driver::runInstallTasks($connection);
    foreach ($results['pass'] as $result) {
      $this->pass($result);
    }
    foreach ($results['fail'] as $result) {
      $this->fail($result);
    }
  }

}
