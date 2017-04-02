<?php

namespace Drupal\Driver\Database\dbal\Install;

use Drupal\Core\Database\Install\Tasks as InstallTasks;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\DriverManager as DBALDriverManager;

/**
 * Specifies installation tasks for DruDbal driver.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver specific code
 * in Drupal\Driver\Database\dbal\DBALDriver\[driver_name] classes and
 * execution handed over to there.
 */
class Tasks extends InstallTasks {

  /**
   * Constructs a \Drupal\Driver\Database\dbal\Install\Tasks object.
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
        '@dbal_driver' => $connection->getDbalConnection()->getDriver()->getName(),
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
    // Drupal\Driver\Database\dbal\DBALDriver\[driver_name]::runInstallTasks.
    return '2.5.12';
  }

  /**
   * Check if we can connect to the database.
   */
  protected function connect() {
    try {
      // Just set the active connection to default. This doesn't actually test
      // the connection.
      Database::setActiveConnection();
      // Now actually do a check.
      $connection = Database::getConnection();
      $results['pass'][] = t('Drupal can CONNECT to the database ok.');
    }
    catch (\Exception $e) {
      if (isset($connection)) {
        $results = $connection->getDbalDriver()->installConnectException();
        foreach ($results['pass'] as $result) {
          $this->pass($result);
        }
        foreach ($results['fail'] as $result) {
          $this->fail($result);
        }
      }
      else {
        $this->fail($e->getMessage());
      }
      $this->fail(t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist, and have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname?</li></ul>', ['%error' => $e->getMessage()]));
    }

    return empty($results['fail']);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormOptions(array $database) {
    $form = parent::getFormOptions($database);

    // Hide the options, will be resolved while processing the Dbal URL.
    $form['database']['#type'] = 'hidden';
    $form['database']['#required'] = FALSE;
    $form['username']['#type'] = 'hidden';
    $form['username']['#required'] = FALSE;
    $form['password']['#type'] = 'hidden';
    $form['advanced_options']['host']['#type'] = 'hidden';
    $form['advanced_options']['port']['#type'] = 'hidden';

    // Add a Dbal URL entry field.
    $form['dbal_url'] = [
      '#type' => 'textarea',
      '#title' => t('Database URL'),
      '#description' => t('The database URL. See <a href="http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url" target="_blank">Doctrine DBAL documentation</a> for details.'),
      '#default_value' => empty($database['dbal_url']) ? '' : $database['dbal_url'],
      '#rows' => 3,
      '#size' => 45,
      '#required' => TRUE,
      '#element_validate' => [[$this, 'validateDBALUrl']],
      '#states' => [
        'required' => [
          ':input[name=driver]' => ['value' => 'dbal'],
        ],
      ],
    ];

    // Add a hidden field for the Dbal driver.
    $form['dbal_driver'] = [
      '#type' => 'hidden',
      '#title' => t('DBAL driver'),
      '#default_value' => empty($database['dbal_driver']) ? '' : $database['dbal_driver'],
    ];

    return $form;
  }

  /**
   * @todo Validates the 'url' field of the installation form.
   */
  public function validateDBALUrl(array $element, FormStateInterface $form_state, array $form) {
    // Opens a DBAL connection just to retrieve the actual DBAL driver being
    // used, so that it does get stored in the settings.
    try {
      $options = [];
      $options['url'] = $form_state->getValue(['dbal', 'dbal_url']);
      $dbal_connection = DBALDriverManager::getConnection($options);
      $form_state->setValue(['dbal', 'database'], $dbal_connection->getDatabase());
      $form_state->setValue(['dbal', 'username'], $dbal_connection->getUsername());
      $form_state->setValue(['dbal', 'password'], $dbal_connection->getPassword());
      $form_state->setValue(['dbal', 'advanced_options', 'host'], $dbal_connection->getHost());
      $form_state->setValue(['dbal', 'advanced_options', 'port'], $dbal_connection->getPort());
      $form_state->setValue(['dbal', 'dbal_driver'], $dbal_connection->getDriver()->getName());
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
    $results = $connection->runInstallTasks();
    foreach ($results['pass'] as $result) {
      $this->pass($result);
    }
    foreach ($results['fail'] as $result) {
      $this->fail($result);
    }
  }

}
