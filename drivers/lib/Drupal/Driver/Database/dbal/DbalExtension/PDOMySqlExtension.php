<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\Connection as DbalConnection;

/**
 * Driver specific methods for pdo_mysql.
 */
class PDOMySqlExtension extends AbstractMySqlExtension {

  /**
   * Constructs a PDOMySqlExtension object.
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
    $this->dbalConnection->getWrappedConnection()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [$statement_class, [$this->connection]]);
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
    if (!empty($this->statementClass)) {
      $this->getDbalConnection()->getWrappedConnection()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, ['PDOStatement', []]);
    }
    parent::destroy();
  }

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    parent::preConnectionOpen($connection_options, $dbal_connection_options);
    $dbal_connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by
      // default.
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's
      // dumb.
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ];
    if (defined('\PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      // An added connection option in PHP 5.5.21 to optionally limit SQL to a
      // single statement like mysqli.
      $dbal_connection_options['driverOptions'] += [\PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    // Match all SQLSTATE 23xxx errors.
    if (substr($e->getCode(), -6, -3) == '23') {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e->errorInfo[1] == 1153) {
      // If a max_allowed_packet error occurs the message length is truncated.
      // This should prevent the error from recurring if the exception is
      // logged to the database using dblog or the like.
      $message = Unicode::truncateBytes($e->getMessage(), self::MIN_MAX_ALLOWED_PACKET);
      throw new DatabaseExceptionWrapper($message, $e->getCode(), $e);
    }
    else {
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

}
