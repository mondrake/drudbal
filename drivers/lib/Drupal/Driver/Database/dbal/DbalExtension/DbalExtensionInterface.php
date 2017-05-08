<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;


/**
 * Provides an interface for Dbal extensions.
 */
interface DbalExtensionInterface {

  /**
   * @todo
   */
  public function destroy();

  /**
   * Gets the DBAL connection.
   *
   * @return \Doctrine\DBAL\Connection
   *   The DBAL connection.
   */
  public function getDbalConnection();

  /**
   * Connection delegated methods.
   */

  /**
   * Prepares opening the DBAL connection.
   *
   * Allows driver/db specific options to be set before opening the DBAL
   * connection.
   *
   * @param array $connection_options
   *   An array of connection options, valid for Drupal database connections.
   * @param array $dbal_connection_options
   *   An array of connection options, valid for DBAL database connections.
   *
   * @see \Drupal\Core\Database\Connection
   * @see \Doctrine\DBAL\Connection
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options);

  /**
   * Executes actions after having opened the DBAL connection.
   *
   * Allows driver/db specific options to be executed once the DBAL connection
   * has been opened.
   *
   * @param \Doctrine\DBAL\Connection $dbal_connection
   *   The DBAL connection.
   * @param array $connection_options
   *   An array of connection options, valid for Drupal database connections.
   * @param array $dbal_connection_options
   *   An array of connection options, valid for DBAL database connections.
   *
   * @see \Drupal\Core\Database\Connection
   * @see \Doctrine\DBAL\Connection
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options);

  /**
   * Returns the version of the database client.
   *
   * This is the low-level database client version, like mysqlind in MySQL
   * drivers.
   *
   * @return string
   *   A string with the database client information.
   */
  public function clientVersion();

  /**
   * Informs the Connection on whether transactions are supported.
   *
   * @param array $connection_options
   *   An array of connection options, valid for Drupal database connections.
   *
   * @return bool
   *   TRUE if transactions are supported, FALSE otherwise.
   */
  public function delegateTransactionSupport(array &$connection_options = []);

  /**
   * Informs the Connection on whether DDL transactions are supported.
   *
   * @param array $connection_options
   *   An array of connection options, valid for Drupal database connections.
   *
   * @return bool
   *   TRUE if DDL transactions are supported, FALSE otherwise.
   */
  public function delegateTransactionalDDLSupport(array &$connection_options = []);

  /**
   * Prepares creating a database.
   *
   * Allows driver/db specific actions to be taken before creating a database.
   *
   * @param string $database
   *   The name of the database to be created.
   *
   * @return $this
   */
  public function preCreateDatabase($database);

  /**
   * Executes actions after having created a database.
   *
   * Allows driver/db specific actions to be after creating a database.
   *
   * @param string $database
   *   The name of the database to be created.
   *
   * @return $this
   */
  public function postCreateDatabase($database);

  /**
   * Retrieves an unique ID.
   *
   * @param $existing_id
   *   (optional) Watermark ID.
   *
   * @return
   *   An integer number larger than any number returned by earlier calls and
   *   also larger than the $existing_id if one was passed in.
   */
  public function delegateNextId($existing_id = 0);

  /**
   * Extension level handling of DBALExceptions thrown by Connection::query().
   *
   * @param string $message
   *   The message to be re-thrown.
   * @param \Exception $e
   *   The exception thrown by query().
   *
   * @throws \Drupal\Core\Database\IntegrityConstraintViolationException
   *   When a integrity constraint was violated in the query.
   * @throws \Drupal\Core\Database\DatabaseExceptionWrapper
   *   For any other error.
   */
  public function delegateQueryExceptionProcess($message, \Exception $e);

  /**
   * Runs a limited-range query.
   *
   * @param string $query
   *   A string containing an SQL query.
   * @param int $from
   *   The first result row to return.
   * @param int $count
   *   The maximum number of result rows to return.
   * @param array $args
   *   (optional) An array of values to substitute into the query at placeholder
   *    markers.
   * @param array $options
   *   (optional) An array of options on the query.
   *
   * @return \Drupal\Core\Database\StatementInterface
   *   A database query result resource, or NULL if the query was not executed
   *   correctly.
   */
  public function delegateQueryRange($query, $from, $count, array $args = [], array $options = []);

  /**
   * Runs a SELECT query and stores its results in a temporary table.
   *
   * @param string $tablename
   *   A string with the Drupal name of the table to be generated.
   * @param string $query
   *   A string containing a normal SELECT SQL query.
   * @param array $args
   *   (optional) An array of values to substitute into the query at placeholder
   *   markers.
   * @param array $options
   *   (optional) An associative array of options to control how the query is
   *   run. See the documentation for DatabaseConnection::defaultOptions() for
   *   details.
   *
   * @return \Drupal\Core\Database\StatementInterface
   *   A database query result resource, or NULL if the query was not executed
   *   correctly.
   */
  public function delegateQueryTemporary($tablename, $query, array $args = [], array $options = []);

  /**
   * Handles exceptions thrown by Connection::popCommittableTransactions().
   *
   * @param \Doctrine\DBAL\Exception\DriverException $e
   *   The exception thrown by query().
   *
   * @throws \Drupal\Core\Database\TransactionCommitFailedException
   *   When commit fails.
   * @throws \Exception
   *   For any other error.
   */
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e);

  /**
   * @todo
   */
  public function prepare($statement, array $params, array $driver_options = []);

}
