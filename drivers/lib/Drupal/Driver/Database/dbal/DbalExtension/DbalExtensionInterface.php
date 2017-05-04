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
   * This is the low-level database client version, like mysqlind in mysql
   * drivers.
   */
  public function clientVersion();

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
