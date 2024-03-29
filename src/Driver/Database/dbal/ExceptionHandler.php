<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\ExceptionHandler as DatabaseExceptionHandler;
use Drupal\Core\Database\Exception\EventException;
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
    assert($exception instanceof DbalDriverException || $exception instanceof DatabaseExceptionWrapper || $exception instanceof EventException, 'Unexpected exception: ' . get_class($exception) . ' ' . $exception->getMessage());
    if ($exception instanceof EventException) {
      throw $exception;
    }
    $query_string = $statement->getQueryString();
    $message = $exception->getMessage() . ": " . $query_string . "; " . print_r($arguments, TRUE);
    $this->connection->getDbalExtension()->delegateQueryExceptionProcess($query_string, $arguments, $options, $message, $exception);
  }

  /**
   * Handles exceptions thrown by DBAL Connection::executeStatement().
   *
   * @param DbalDriverException $exception
   *   The exception thrown by executeStatement().
   * @param string $sql
   *   A string containing the failing SQL query.
   */
  public function handleClientExecuteStatementException(DbalDriverException $exception, string $sql): void {
    $message = $exception->getMessage() . ": " . $sql . ";";
    $this->connection->getDbalExtension()->delegateClientExecuteStatementException($exception, $sql, $message);
  }

}
