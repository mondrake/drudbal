<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
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
   * {@inheritdoc}
   */
  public function handleStatementException(\Exception $exception, string $sql, array $options = []): void {
    if ($exception instanceof \PDOException) {
      // Wrap the exception in another exception, because PHP does not allow
      // overriding Exception::getMessage(). Its message is the extra database
      // debug information.
      $message = $exception->getMessage() . ": " . $sql . "; ";
      throw new DatabaseExceptionWrapper($message, 0, $exception);
    }

    throw $exception;
  }

  /**
   * {@inheritdoc}
   */
  public function handleExecutionException(\Exception $exception, StatementInterface $statement, array $arguments = [], array $options = []): void {
    $query_string = $statement->getQueryString();
    $message = $e->getMessage() . ": " . $query_string . "; " . print_r($arguments, TRUE);
    $options['dbalExtension']->delegateQueryExceptionProcess($query_string, $arguments, $options, $message, $e);
  }

}
