<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\Table as DbalTable;

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
  public function delegateClientVersion();

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
   * @param string $database_name
   *   The name of the database to be created.
   *
   * @return $this
   */
  public function preCreateDatabase($database_name);

  /**
   * Executes actions after having created a database.
   *
   * Allows driver/db specific actions to be after creating a database.
   *
   * @param string $database_name
   *   The name of the database created.
   *
   * @return $this
   */
  public function postCreateDatabase($database_name);

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
  public function delegatePrepare($statement, array $params, array $driver_options = []);

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
   * @param string $drupal_table_name
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
  public function delegateQueryTemporary($drupal_table_name, $query, array $args = [], array $options = []);

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
   * Truncate delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function preTruncate($drupal_table_name);

  /**
   * {@inheritdoc}
   */
  public function postTruncate($drupal_table_name);

  /**
   * Install\Tasks delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function delegateInstallConnectExceptionProcess(\Exception $e);

  /**
   * Executes installation specific tasks for the database.
   *
   * @return array
   *   An array of pass/fail installation messages.
   */
  public function runInstallTasks();

  /**
   * Schema delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateTableExists(&$result, $drupal_table_name);

  /**
   * {@inheritdoc}
   */
  public function delegateFieldExists(&$result, $drupal_table_name, $field_name);

  /**
   * {@inheritdoc}
   */
  public function alterCreateTableOptions(DbalTable $dbal_table, DbalSchema $dbal_schema, array &$drupal_table_specs, $drupal_table_name);

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs);

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnOptions($context, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name);

  /**
   * {@inheritdoc}
   */
  public function getDbalEncodedStringForDDLSql($string);

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array $dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name);

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options);

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options);

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetDefault($drupal_table_name, $field_name, $default);

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetNoDefault($drupal_table_name, $field_name);

  /**
   * {@inheritdoc}
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $drupal_table_name, $index_name);

  /**
   * {@inheritdoc}
   */
  public function delegateAddPrimaryKey(DbalSchema $dbal_schema, $drupal_table_name, $drupal_field_specs);

  /**
   * {@inheritdoc}
   */
  public function delegateAddUniqueKey($drupal_table_name, $index_name, $drupal_field_specs);

  /**
   * {@inheritdoc}
   */
  public function delegateAddIndex($drupal_table_name, $index_name, array $drupal_field_specs, array $indexes_spec);

  /**
   * {@inheritdoc}
   */
  public function delegateGetComment(&$comment, DbalSchema $dbal_schema, $drupal_table_name, $column = NULL);

  /**
   * {@inheritdoc}
   */
  public function alterGetComment(&$comment, DbalSchema $dbal_schema, $drupal_table_name, $column = NULL);

  /**
   * {@inheritdoc}
   */
  public function alterSetTableComment(&$comment, $drupal_table_name, DbalSchema $dbal_schema, array $drupal_table_spec);

  /**
   * {@inheritdoc}
   */
  public function alterSetColumnComment(&$comment, $dbal_type, $drupal_field_specs, $field_name);

}
