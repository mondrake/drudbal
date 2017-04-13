<?php

namespace Drupal\Driver\Database\dbal\Install;

use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Install\Tasks as InstallTasks;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\ConnectionException as DbalExceptionConnectionException;
use Doctrine\DBAL\DriverManager as DbalDriverManager;

/**
 * Specifies installation tasks for DruDbal driver.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Tasks extends InstallTasks {

  /**
   * Constructs a \Drupal\Driver\Database\dbal\Install\Tasks object.
   */
  public function __construct() {
    // The DBAL driver delegates the installation tasks to the Dbal extension.
    // We just add a catchall task in this class.
    $this->tasks[] = [
      'function' => 'runDbalInstallTasks',
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
    catch (ConnectionNotDefinedException $e) {
      return t('Doctrine DBAL');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function minimumVersion() {
    // Note: This is the minimum version of Doctrine DBAL; the minimum version
    // of the db server should be managed in
    // Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]::runInstallTasks.
    return '2.5.12';
  }

  /**
   * Check if we can connect to the database.
   *
   * @return bool
   *   TRUE if the connection succeeded, FALSE otherwise. The self::$results
   *   array stores pass/fail messages.
   */
  protected function connect() {
    try {
      // Just set the active connection to default. This doesn't actually test
      // the connection.
      Database::setActiveConnection();
      // Now actually try to get a full Drupal connection object.
      $connection = Database::getConnection();
      $this->pass(t('Drupal can CONNECT to the database ok.'));
      return TRUE;
    }
    catch (ConnectionNotDefinedException $e) {
      // We get here if both 'dbal_driver' and 'dbal_url' are missing from the
      // connection definition.
      $this->fail($e->getMessage());
      return FALSE;
    }
    catch (DbalExceptionConnectionException $e) {
      // We get here if 'dbal_url' could be processed, but the driver (or the
      // server) has found problems in the connection (e.g. wrong
      // username/password, or host, etc). It's possible that the problem can
      // be fixed (e.g. by creating a missing database), so hand over to the
      // Dbal extension for the processing.
      $connection_info = Database::getConnectionInfo()['default'];
      if (!empty($connection_info['dbal_driver'])) {
        $dbal_extension_class = DruDbalConnection::getDbalExtensionClass($connection_info['dbal_driver']);
        $results = $dbal_extension_class::handleInstallConnectException($e);
        foreach ($results['pass'] as $result) {
          $this->pass($result);
        }
        foreach ($results['fail'] as $result) {
          $this->fail($result);
        }
      }
      else {
        $this->fail(t('Failed to connect to your database server. Doctrine DBAL reports the following message: %error.', ['%error' => $e->getMessage()]));
      }
      return empty($this->results['fail']);
    }
    catch (DBALException $e) {
      // We get here if 'dbal_url' is defined but invalid/malformed.
      $this->fail(t('There is a problem with the database URL. Doctrine DBAL reports the following message: %message', ['%message' => $e->getMessage()]));
      return FALSE;
    }
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
      '#element_validate' => [[$this, 'validateDbalUrl']],
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
   * Validation handler for  the 'dbal_url' field of the installation form.
   *
   * In fact, it just 'explodes' the DBAL URL into Drupal's connection info
   * keys.
   */
  public function validateDbalUrl(array $element, FormStateInterface $form_state, array $form) {
    // Opens a DBAL connection using the URL, just to resolve the details of
    // all the parameters required, including the actual DBAL driver being
    // used, so that it does get stored in the settings.
    try {
      $options = [];
      $options['url'] = $form_state->getValue(['dbal', 'dbal_url']);
      $dbal_connection = DbalDriverManager::getConnection($options);
      $form_state->setValue(['dbal', 'database'], $dbal_connection->getDatabase());
      $form_state->setValue(['dbal', 'username'], $dbal_connection->getUsername());
      $form_state->setValue(['dbal', 'password'], $dbal_connection->getPassword());
      $form_state->setValue(['dbal', 'host'], $dbal_connection->getHost());
      $form_state->setValue(['dbal', 'port'], $dbal_connection->getPort());
      $form_state->setValue(['dbal', 'dbal_driver'], $dbal_connection->getDriver()->getName());
    }
    catch (DBALException $e) {
      // If we get DBAL exception, probably the URL is malformed. We cannot
      // update user here, ::connect() will take care of that detail.
      return;
    }
  }

  /**
   * Executes DBAL driver installation specific tasks.
   */
  public function runDbalInstallTasks() {
    $extension = Database::getConnection()->getDbalExtension();
    $results = $extension->runInstallTasks();
    foreach ($results['pass'] as $result) {
      $this->pass($result);
    }
    foreach ($results['fail'] as $result) {
      $this->fail($result);
    }
  }

}
