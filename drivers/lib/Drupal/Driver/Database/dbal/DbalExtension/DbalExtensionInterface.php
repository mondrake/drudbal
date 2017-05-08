<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use \Doctrine\DBAL\Connection as DbalConnection;

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
   * @todo
   */
  public function prepare($statement, array $params, array $driver_options = []);

  /**
   * Re-throws query exceptions.
   *
   * @param string $message
   *   The message to be re-thrown.
   * @param \Exception $e
   *   The exception thrown by the query.
   *
   * @throws \Drupal\Core\Database\DatabaseExceptionWrapper
   */
  public function delegateQueryExceptionProcess($message, \Exception $e);

}
