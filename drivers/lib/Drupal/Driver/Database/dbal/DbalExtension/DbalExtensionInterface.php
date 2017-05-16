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
   * Prepares a statement for execution and returns a statement object.
   *
   * @param string $statement
   *   This must be a valid SQL statement for the target database server.
   * @param array $params
   *   An array of arguments for the prepared statement. If the prepared
   *   statement uses ? placeholders, this array must be an indexed array.
   *   If it contains named placeholders, it must be an associative array.
   * @param array $driver_options
   *   (optional) This array holds one or more key=>value pairs to set
   *   attribute values for the StatementInterface object that this method
   *   returns.
   *
   * @return \Drupal\Core\Database\StatementInterface|false
   *   If the database server successfully prepares the statement, returns a
   *   StatementInterface object.
   *   If the database server cannot successfully prepare the statement,
   *   returns FALSE or throes a DatabaseExceptionWrapper exception.
   *
   * @throws \Drupal\Core\Database\DatabaseExceptionWrapper
   */
  public function delegatePrepare($statement, array $params, array $driver_options = []);

  /**
   * Handles a DBALExceptions thrown by Connection::query().
   *
   * @param string $query
   *   A string containing the failing SQL query.
   * @param array $args
   *   An array of values to substitute into the query at placeholder markers.
   * @param array $options
   *   An array of options on the query.
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
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e);

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
   * Prepares truncating a table.
   *
   * Allows driver/db specific actions to be taken before truncating a table.
   *
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table to be truncated.
   *
   * @return $this
   */
  public function preTruncate($drupal_table_name);

  /**
   * Executes actions after having truncated a table.
   *
   * Allows driver/db specific actions to be after truncating a table.
   *
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table that was truncated.
   *
   * @return $this
   */
  public function postTruncate($drupal_table_name);

  /**
   * Install\Tasks delegated methods.
   */

  /**
   * Handles exceptions thrown by Install/Tasks::connect().
   *
   * @param \Exception $e
   *   The exception thrown by connect().
   *
   * @return array
   *   An array of pass/fail installation messages.
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
   * Checks if a table exists.
   *
   * @param bool $result
   *   The result of the table existence check. Passed by reference.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   *
   * @return bool
   *   TRUE if the extension managed the check, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateTableExists(&$result, $drupal_table_name);

  /**
   * Checks if a table field exists.
   *
   * @param bool $result
   *   The result of the field existence check. Passed by reference.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $field_name
   *   A string with the name of the field.
   *
   * @return bool
   *   TRUE if the extension managed the check, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateFieldExists(&$result, $drupal_table_name, $field_name);

  /**
   * Alters the DBAL create table options.
   *
   * @param \Doctrine\DBAL\Schema\Table $dbal_table
   *   The DBAL table object being created.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param array $drupal_table_specs
   *   A Drupal Schema API table definition array. Passed by reference.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   *
   * @return $this
   */
  public function alterCreateTableOptions(DbalTable $dbal_table, DbalSchema $dbal_schema, array &$drupal_table_specs, $drupal_table_name);

  /**
   * Maps a Drupal field to a DBAL type.
   *
   * @param string $dbal_type
   *   The DBAL type related to the field specified. Passed by reference.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   *
   * @return bool
   *   TRUE if the extension managed the mapping, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs);

  /**
   * Alters the DBAL column options.
   *
   * @param string $context
   *   The context from where the method is called. Can be 'createTable',
   *   'addField', 'changeField'.
   * @param array $dbal_column_options
   *   An array of DBAL column options, including the SQL column definition
   *   specification in the 'columnDefinition' option. Passed by reference.
   * @param string $dbal_type
   *   The DBAL type related to the field specified.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   * @param string $field_name
   *   The name of the field.
   *
   * @return $this
   */
  public function alterDbalColumnOptions($context, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name);

  /**
   * Gets a string valid to be used in a DBAL DDL statement.
   *
   * @param string $string
   *   The input string.
   *
   * @return string
   *   A string valid to be used in a DBAL DDL statement.
   */
  public function getDbalEncodedStringForDDLSql($string);

  /**
   * Alters the DBAL column definition.
   *
   * @param string $context
   *   The context from where the method is called. Can be 'createTable',
   *   'addField', 'changeField'.
   * @param string $dbal_column_definition
   *   The column definition string in the SQL syntax of the database in use.
   *   Passed by reference.
   * @param array $dbal_column_options
   *   An array of DBAL column options, including the SQL column definition
   *   specification in the 'columnDefinition' option.
   * @param string $dbal_type
   *   The DBAL type related to the field specified.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   * @param string $field_name
   *   The name of the field.
   *
   * @return $this
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array $dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name);

  /**
   * Adds a new field to a table.
   *
   * @param bool $primary_key_processed_by_extension
   *   Passed by reference. TRUE if the extension also processed adding the
   *   primary key for the table, FALSE otherwise.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $field_name
   *   The name of the field.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   * @param array $keys_new_specs
   *   Keys and indexes specification to be created on the table along with
   *   adding the field. The format is the same as a table specification but
   *   without the 'fields' element. If you are adding a type 'serial' field,
   *   you MUST specify at least one key or index including it in this array.
   * @param array $dbal_column_options
   *   An array of DBAL column options, including the SQL column definition
   *   specification in the 'columnDefinition' option.
   *
   * @return bool
   *   TRUE if the extension added the field, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateAddField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options);

  /**
   * Change a field definition.
   *
   * @param bool $primary_key_processed_by_extension
   *   Passed by reference. TRUE if the extension also processed adding the
   *   primary key for the table, FALSE otherwise.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $field_name
   *   The name of the field.
   * @param string $field_new_name
   *   The new name of the field.
   * @param array $drupal_field_new_specs
   *   The field new specification array, as taken from a schema definition.
   * @param array $keys_new_specs
   *   Keys and indexes specification to be created on the table along with
   *   chaning the field. The format is the same as a table specification but
   *   without the 'fields' element.
   * @param array $dbal_column_options
   *   An array of DBAL column options, including the SQL column definition
   *   specification in the 'columnDefinition' option.
   *
   * @return bool
   *   TRUE if the extension changed the field, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options);

  /**
   * Sets the default value for a field.
   *
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $field_name
   *   The name of the field.
   * @param string $default
   *   Default value to be set.
   *
   * @return bool
   *   TRUE if the extension changed the default, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateFieldSetDefault($drupal_table_name, $field_name, $default);

  /**
   * Set a field to have no default value.
   *
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $field_name
   *   The name of the field.
   *
   * @return bool
   *   TRUE if the extension changed the default, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateFieldSetNoDefault($drupal_table_name, $field_name);

  /**
   * Checks if an index exists.
   *
   * @param bool $result
   *   The result of the index existence check. Passed by reference.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $index_name
   *   A string with the name of the index.
   *
   * @return bool
   *   TRUE if the extension managed the check, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $drupal_table_name, $index_name);

  /**
   * Adds a primary key.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   *
   * @return bool
   *   TRUE if the extension added the primary key, FALSE if it has to be
   *   handled by DBAL.
   */
  public function delegateAddPrimaryKey(DbalSchema $dbal_schema, $drupal_table_name, $drupal_field_specs);

  /**
   * Adds a unique key.
   *
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $index_name
   *   A string with the name of the index.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   *
   * @return bool
   *   TRUE if the extension added the unique key, FALSE if it has to be
   *   handled by DBAL.
   */
  public function delegateAddUniqueKey($drupal_table_name, $index_name, $drupal_field_specs);

  /**
   * Adds an index.
   *
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $index_name
   *   A string with the name of the index.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   * @param array $indexes_spec
   *   The table specification for the table, containing the index
   *   specification.
   *
   * @return bool
   *   TRUE if the extension added the unique key, FALSE if it has to be
   *   handled by DBAL.
   */
  public function delegateAddIndex($drupal_table_name, $index_name, array $drupal_field_specs, array $indexes_spec);

  /**
   * Retrieves a table or column comment.
   *
   * @param string $comment
   *   The comment. Passed by reference.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $column
   *   A string with the name of the column.
   *
   * @return bool
   *   TRUE if the extension managed the to get the comment, FALSE if it has
   *   to be handled by DBAL.
   */
  public function delegateGetComment(&$comment, DbalSchema $dbal_schema, $drupal_table_name, $column = NULL);

  /**
   * Alters a table or column comment retrieved from the database.
   *
   * @param string $comment
   *   The comment. Passed by reference.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $column
   *   A string with the name of the column.
   *
   * @return $this
   */
  public function alterGetComment(&$comment, DbalSchema $dbal_schema, $drupal_table_name, $column = NULL);

  /**
   * Alters a table comment to be written to the database.
   *
   * @param string $comment
   *   The comment. Passed by reference.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param array $drupal_table_specs
   *   A Drupal Schema API table definition array.
   *
   * @return $this
   */
  public function alterSetTableComment(&$comment, $drupal_table_name, DbalSchema $dbal_schema, array $drupal_table_spec);

  /**
   * Alters a column comment to be written to the database.
   *
   * @param string $comment
   *   The comment. Passed by reference.
   * @param string $dbal_type
   *   The DBAL type related to the field specified.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   * @param string $field_name
   *   The name of the field.
   *
   * @return $this
   */
  public function alterSetColumnComment(&$comment, $dbal_type, array $drupal_field_specs, $field_name);

}
