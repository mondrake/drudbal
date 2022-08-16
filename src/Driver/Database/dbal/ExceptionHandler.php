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
   * Constructs a DruDbal exception handler object.
   */
  public function __construct(
    protected Connection $connection
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handleExecutionException(\Exception $exception, StatementInterface $statement, array $arguments = [], array $options = []): void {
    $query_string = $statement->getQueryString();
    $message = $exception->getMessage() . ": " . $query_string . "; " . print_r($arguments, TRUE);
    $this->connection->getDbalExtension()->delegateQueryExceptionProcess($query_string, $arguments, $options, $message, $exception);
  }

}
