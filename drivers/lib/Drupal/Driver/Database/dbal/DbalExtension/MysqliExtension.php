<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\Mysqli\MysqliException;

/**
 * Driver specific methods for mysqli.
 */
class MysqliExtension extends AbstractMySqlExtension {

  /**
   * The Statement class to use for this extension.
   *
   * @var \Drupal\Core\Database\StatementInterface
   */
  protected $statementClass;

  /**
   * Constructs a MysqliExtension object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $drudbal_connection
   *   The Drupal database connection object for this extension.
   * @param \Doctrine\DBAL\Connection $dbal_connection
   *   The DBAL connection.
   * @param string $statement_class
   *   The StatementInterface class to be used.
   */
  public function __construct(DruDbalConnection $drudbal_connection, DbalConnection $dbal_connection, $statement_class) {
    $this->connection = $drudbal_connection;
    $this->dbalConnection = $dbal_connection;
    $this->statementClass = $statement_class;
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getWrappedResourceHandle()->get_client_info();
  }

  /**
   * {@inheritdoc}
   */
  public function prepare($statement, array $params, array $driver_options = []) {
    try {
      return new $this->statementClass($this->connection, $statement, $params, $driver_options);
    }
    catch (MysqliException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($message, \Exception $e) {
    if ($e instanceof \Exception) {
      throw $e;
    }
    if ($e instanceof DatabaseExceptionWrapper) {
      $e = $e->getPrevious();
    }
    // Match all SQLSTATE 23xxx errors.
    if (substr($e->getSqlState(), -6, -3) == '23') {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e->getErrorCode() == 1153) {
      // If a max_allowed_packet error occurs the message length is truncated.
      // This should prevent the error from recurring if the exception is
      // logged to the database using dblog or the like.
      $message = Unicode::truncateBytes($e->getMessage(), self::MIN_MAX_ALLOWED_PACKET);
      throw new DatabaseExceptionWrapper($message, $e->getSqlState(), $e);
    }
    else {
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

}
