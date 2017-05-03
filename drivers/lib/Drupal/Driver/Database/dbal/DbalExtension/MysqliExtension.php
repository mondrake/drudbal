<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Mysqli\MysqliException;
use Doctrine\DBAL\Exception\ConnectionException as DbalExceptionConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\DriverManager as DBALDriverManager;
use Doctrine\DBAL\SQLParserUtils;


/**
 * Driver specific methods for mysqli.
 */
class MysqliExtension extends AbstractMySqlExtension {

  /**
   * @var @todo
   */
  protected $statementClass;

  /**
   * Constructs a MysqliExtension object.
   */
  public function __construct(DruDbalConnection $drudbal_connection, DbalConnection $dbal_connection, $statement_class) {
    $this->connection = $drudbal_connection;
    $this->dbalConnection = $dbal_connection;
    $this->statementClass = $statement_class;
  }

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    if (isset($connection_options['_dsn_utf8_fallback']) && $connection_options['_dsn_utf8_fallback'] === TRUE) {
      // Only used during the installer version check, as a fallback from utf8mb4.
      $charset = 'utf8';
    }
    else {
      $charset = 'utf8mb4';
    }

    // Character set is added to dsn to ensure PDO uses the proper character
    // set when escaping. This has security implications. See
    // https://www.drupal.org/node/1201452 for further discussion.
    $connection_options['charset'] = $charset;
    $dbal_connection_options['charset'] = $charset;
/*
    $connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by default.
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's dumb.
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ];
    if (defined('\PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      // An added connection option in PHP 5.5.21 to optionally limit SQL to a
      // single statement like mysqli.
      $connection_options['driverOptions'] += [\PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE];
    }
*/
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getWrappedResourceHandle()->get_client_info();
  }

  /**
   * @todo
   */
  public function destroy() {
    // @todo
  }

  /**
   * @todo
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
   * Wraps and re-throws any DBALException thrown by static::query().
   *
   * @param \Exception $e
   *   The exception thrown by query().
   * @param $query
   *   The query executed by query().
   * @param array $args
   *   An array of arguments for the prepared statement.
   * @param array $options
   *   An associative array of options to control how the query is run.
   *
   * @return @todo
   *
   * @throws \Drupal\Core\Database\DatabaseExceptionWrapper
   */
  public function handleQueryException(\Exception $e, $query, array $args = [], $options = []) {
    if ($options['throw_exception']) {
      // Wrap the exception in another exception, because PHP does not allow
      // overriding Exception::getMessage(). Its message is the extra database
      // debug information.
      if ($query instanceof StatementInterface) {
        $query_string = $query->getQueryString();
      }
      elseif (is_string($query)) {
        $query_string = $query;
      }
      else {
        $query_string = NULL;
      }
//var_export([$e->getMessage(), $e->getErrorCode(), $e->getSqlState()]);die;
      $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
      if ($e instanceof DatabaseExceptionWrapper) {
        $e = $e->getPrevious();
      }
      // Match all SQLSTATE 23xxx errors.
      if (substr($e->getSqlState(), -6, -3) == '23') {
        throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
      }
/*      elseif ($e->errorInfo[1] == 1153) {
        // If a max_allowed_packet error occurs the message length is truncated.
        // This should prevent the error from recurring if the exception is
        // logged to the database using dblog or the like.
        $message = Unicode::truncateBytes($e->getMessage(), self::MIN_MAX_ALLOWED_PACKET);
        throw new DatabaseExceptionWrapper($message, $e->getCode(), $e);
      }
*/      else {
        throw new DatabaseExceptionWrapper($message, 0, $e);
      }
    }

    return NULL;
  }

  public function releaseSavepoint($name) {
    try {
      $this->dbalConnection->exec('RELEASE SAVEPOINT ' . $name);
      return 'ok';
    }
    catch (DriverException $e) {
      // In MySQL (InnoDB), savepoints are automatically committed
      // when tables are altered or created (DDL transactions are not
      // supported). This can cause exceptions due to trying to release
      // savepoints which no longer exist.
      //
      // To avoid exceptions when no actual error has occurred, we silently
      // succeed for MySQL error code 1305 ("SAVEPOINT does not exist").
      if ($e->getErrorCode() == '1305') {
        // We also have to explain to PDO that the transaction stack has
        // been cleaned-up.
        try {
          $this->dbalConnection->commit();
        }
        catch (\Exception $e) {
          throw new TransactionCommitFailedException();
        }
        // If one SAVEPOINT was released automatically, then all were.
        // Therefore, clean the transaction stack.
        return 'all';
      }
      else {
        throw $e;
      }
    }
  }

}
