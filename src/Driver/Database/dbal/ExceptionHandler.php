<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\ExceptionHandler as DatabaseExceptionHandler;
use Drupal\Core\Database\StatementInterface;

/**
 * DruDbal implementation of \Drupal\Core\Database\ExceptionHandler.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class ExceptionHandler extends DatabaseExceptionHandler {

  /**
   * The DruDbal connection.
   *
   * @var \Drupal\drudbal\Driver\Database\dbal\Connection
   */
  protected $connection;

  /**
   * Constructs a DruDbal exception object.
   *
   * @param \Drupal\drudbal\Driver\Database\dbal\Connection $drudbal_connection
   *   The Drupal database connection object for this extension.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function handleExecutionException(\Exception $exception, StatementInterface $statement, array $arguments = [], array $options = []): void {
    if (array_key_exists('throw_exception', $options)) {
      @trigger_error('Passing a \'throw_exception\' option to ' . __METHOD__ . ' is deprecated in drupal:9.2.0 and is removed in drupal:10.0.0. Always catch exceptions. See https://www.drupal.org/node/3201187', E_USER_DEPRECATED);
      if (!($options['throw_exception'])) {
        return;
      }
    }

    $query_string = $statement->getQueryString();
    $message = $exception->getMessage() . ": " . $query_string . "; " . print_r($arguments, TRUE);
    $this->connection->getDbalExtension()->delegateQueryExceptionProcess($query_string, $arguments, $options, $message, $exception);
  }

}
