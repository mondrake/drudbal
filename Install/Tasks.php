<?php

namespace Drupal\Driver\Database\drubal\Install;

use Drupal\Core\Database\Install\Tasks as InstallTasks;
use Drupal\Core\Database\Database;
use Drupal\Driver\Database\drubal\DBALDriver; // @todo
use Doctrine\DBAL\DriverManager as DBALDriverManager;

/**
 * Specifies installation tasks for DRUBAL driver.
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
    if ($this->connect() === TRUE) {
      $connection = Database::getConnection();
      return t('Doctrine DBAL on @database_type/@database_server_version via @dbal_driver', [
        '@database_type' => $connection->databaseType(),
        '@database_server_version' => $connection->getDbServerVersion(),
        '@dbal_driver' => $connection->getDBALDriver(),
      ]);
    }
    else {
      return t('Doctrine DBAL');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function minimumVersion() {
    return '2.5.12';
  }

  /**
   * Check if we can connect to the database.
   */
  protected function connect() {
    $results = DBALDriver\PdoMysql::installConnect();
    foreach ($results['pass'] as $result) {
      $this->pass($result);
    }
    foreach ($results['fail'] as $result) {
      $this->fail($result);
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
   * Validates the 'dbal_driver' field of the installation form.
   */
  public function validateDBALDriver($element, $form_state, $form) {
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
    $results = DBALDriver\PdoMysql::runInstallTasks($connection);
    foreach ($results['pass'] as $result) {
      $this->pass($result);
    }
    foreach ($results['fail'] as $result) {
      $this->fail($result);
    }
  }

}
