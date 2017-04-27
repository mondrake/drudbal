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
use Doctrine\DBAL\Exception\ConnectionException as DbalExceptionConnectionException;
use Doctrine\DBAL\DriverManager as DBALDriverManager;
use Doctrine\DBAL\SQLParserUtils;

/**
 * Driver specific methods for mysqli.
 */
class Mysqli extends PDOMySql {

  /**
   * The DruDbal connection.
   *
   * @var @todo
   */
  protected $statementClass;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DruDbalConnection $drudbal_connection, DbalConnection $dbal_connection, $statement_class) {
    $this->connection = $drudbal_connection;
    $this->dbalConnection = $dbal_connection;
    $this->statementClass = $statement_class;
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    try {
      static::preConnectionOpen($connection_options);
      $options = array_diff_key($connection_options, [
        'namespace' => NULL,
        'prefix' => NULL,
// @todo remap
// @todo advanced_options are written to settings.php
        'driver' => NULL,
        'database' => NULL,
        'username' => NULL,
        'password' => NULL,
        'host' => NULL,
        'port' => NULL,
        'dbal_url' => NULL,
        'dbal_driver' => NULL,
        'advanced_options' => NULL,
      ]);
      $options['dbname'] = $connection_options['database'];
      $options['user'] = $connection_options['username'];
      $options['password'] = $connection_options['password'];
      $options['host'] = $connection_options['host'];
      $options['port'] = isset($connection_options['port']) ? $connection_options['port'] : NULL;
      $options['url'] = isset($connection_options['dbal_url']) ? $connection_options['dbal_url'] : NULL;
      $options['driver'] = $connection_options['dbal_driver'];
      $dbal_connection = DBALDriverManager::getConnection($options);
      static::postConnectionOpen($dbal_connection, $connection_options);
//var_export($dbal_connection->getSchemaManager()->listTableNames());
//var_export($dbal_connection->getDriver()->getDatabasePlatform()->getName());
//var_export($dbal_connection->getWrappedConnection()->getServerVersion());
//var_export($dbal_connection->getDriver()->getName());
    }
    catch (DbalConnectionException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
    return $dbal_connection;
  }

  /**
   * @todo
   */
  protected static function preConnectionOpen(array &$connection_options = []) {
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
    // Allow PDO options to be overridden.
    $connection_options += [
      'driverOptions' => [],
    ];
    $connection_options['driverOptions'] += [
//      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by default.
//      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
//      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's dumb.
//      \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ];
    if (defined('\PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      // An added connection option in PHP 5.5.21 to optionally limit SQL to a
      // single statement like mysqli.
//      $connection_options['driverOptions'] += [\PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE];
    }
  }

  /**
   * @todo
   */
  protected static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options = []) {
    // Force MySQL to use the UTF-8 character set. Also set the collation, if a
    // certain one has been set; otherwise, MySQL defaults to
    // 'utf8mb4_general_ci' for utf8mb4.
    if (!empty($connection_options['collation'])) {
      $dbal_connection->exec('SET NAMES ' . $connection_options['charset'] . ' COLLATE ' . $connection_options['collation']);
    }
    else {
      $dbal_connection->exec('SET NAMES ' . $connection_options['charset']);
    }

    // Set MySQL init_commands if not already defined.  Default Drupal's MySQL
    // behavior to conform more closely to SQL standards.  This allows Drupal
    // to run almost seamlessly on many different kinds of database systems.
    // These settings force MySQL to behave the same as postgresql, or sqlite
    // in regards to syntax interpretation and invalid data handling.  See
    // https://www.drupal.org/node/344575 for further discussion. Also, as MySQL
    // 5.5 changed the meaning of TRADITIONAL we need to spell out the modes one
    // by one.
    $connection_options += [
      'init_commands' => [],
    ];
    $connection_options['init_commands'] += [
      'sql_mode' => "SET sql_mode = 'ANSI,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,ONLY_FULL_GROUP_BY'",
    ];
    // Execute initial commands.
    foreach ($connection_options['init_commands'] as $sql) {
      $dbal_connection->exec($sql);
    }
  }

  /**
   * @todo
   */
  public function prepare($statement, array $params, array $driver_options = []) {
    return new $this->statementClass($this->connection, $statement, $params, $driver_options);
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
      $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
      // Match all SQLSTATE 23xxx errors.
      if (substr($e->getCode(), -6, -3) == '23') {
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

}
