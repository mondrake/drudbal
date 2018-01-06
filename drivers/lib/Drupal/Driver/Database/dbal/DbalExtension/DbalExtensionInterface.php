<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Statement as DbalStatement;

/**
 * Provides an interface for Dbal extensions.
 */
interface DbalExtensionInterface {

  /**
   * Destroys this Extension object.
   *
   * Handed over from the Connection object when it gets destroyed too.
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
   * Database asset name resolution methods.
   */

  /**
   * Get the database table name, resolving platform specific constraints.
   *
   * @param string $drupal_prefix
   *   A string with the Drupal prefix for the tables.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   *
   * @return string
   *   The database table name.
   */
  public function getDbTableName(string $drupal_prefix, string $drupal_table_name): string;

  /**
   * Get the database table name, including the schema prefix.
   *
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   *
   * @return string
   *   The database table name, including the schema prefix.
   */
  public function getDbFullQualifiedTableName($drupal_table_name);

  /**
   * Get the database field name, resolving platform specific constraints.
   *
   * @param string $field_name
   *   The name of the field in question.
   *
   * @return string
   *   The database field name.
   */
  public function getDbFieldName($field_name);

  /**
   * Get a valid alias, resolving platform specific constraints.
   *
   * @param string $alias
   *   An alias.
   *
   * @return string
   *   The alias usable in the DBMS.
   */
  public function getDbAlias($alias);

  /**
   * Replaces unconstrained alias in a string.
   *
   * @param string $unaliased
   *   A string containing unconstrained aliases.
   *
   * @return string
   *   The string with aliases usable in the DBMS.
   */
  public function resolveAliases(?string $unaliased): string;

  /**
   * Calculates an index name.
   *
   * @param string $context
   *   The context from where the method is called. Can be 'indexExists',
   *   'addUniqueKey', 'addIndex', 'dropIndex'.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $index_name
   *   A string with the Drupal name of the index.
   * @param array $table_prefix_info
   *   A keyed array with information about the schema, table name and prefix.
   *
   * @return string
   *   A string with the name of the index to be used in the DBMS.
   */
  public function getDbIndexName($context, DbalSchema $dbal_schema, $drupal_table_name, $index_name, array $table_prefix_info);

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
  public function delegateTransactionalDdlSupport(array &$connection_options = []);

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
   * Gets any special processing requirements for the condition operator.
   *
   * @param string $operator
   *   The condition operator, such as "IN", "BETWEEN", etc. Case-sensitive.
   *
   * @return array
   *   The extra handling directives for the specified operator, or NULL.
   *
   * @see \Drupal\Core\Database\Connection
   */
  public function delegateMapConditionOperator($operator);

  /**
   * Retrieves an unique ID.
   *
   * @param int $existing_id
   *   (optional) Watermark ID.
   *
   * @return int
   *   An integer number larger than any number returned by earlier calls and
   *   also larger than the $existing_id if one was passed in.
   */
  public function delegateNextId($existing_id = 0);

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
   * @return string
   *   A return code identifying the result of the exception handling.
   *
   * @throws \Drupal\Core\Database\TransactionCommitFailedException
   *   When commit fails.
   * @throws \Exception
   *   For any other error.
   *
   * @todo convert return to int, and add a const for 'all'
   */
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e);

  /**
   * PlatformSql delegated methods.
   */

  /**
   * Returns a native database expression for a given field.
   *
   * @param string $field
   *   The query field that will be used in the expression.
   * @param bool $string_date
   *   For certain databases, date format functions vary depending on string or
   *   numeric storage.
   *
   * @return string
   *   An expression representing a date field with timezone.
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date) : string;

  /**
   * Creates a native database date formatting.
   *
   * @param string $field
   *   An appropriate query expression pointing to the date field.
   * @param string $format
   *   A format string for the result. For example: 'Y-m-d H:i:s'.
   *
   * @return string
   *   A string representing the field formatted as a date as specified by
   *   $format.
   */
  public function delegateGetDateFormatSql(string $field, string $format) : string;

  /**
   * Set the database to the given timezone.
   *
   * @param string $offset
   *   The timezone.
   */
  public function delegateSetTimezoneOffset(string $offset) : void;

  /**
   * Applies the given offset to the given field.
   *
   * @param string &$field
   *   The date field in a string format.
   * @param int $offset
   *   The timezone offset in seconds.
   */
  public function delegateSetFieldTimezoneOffsetSql(string &$field, int $offset) : void;

  /**
   * Statement delegated methods.
   */

  /**
   * Informs the Statement on whether all data needs to be fetched on SELECT.
   *
   * @return bool
   *   TRUE if data has to be prefetched, FALSE otherwise.
   */
  public function onSelectPrefetchAllData();

  /**
   * Informs the Statement on whether named placeholders are supported.
   *
   * @return bool
   *   TRUE if named placeholders are supported, FALSE otherwise.
   */
  public function delegateNamedPlaceholdersSupport();

  /**
   * Alters the SQL query and its arguments before preparing the statement.
   *
   * @param string $query
   *   A string containing an SQL query. Passed by reference.
   * @param array $args
   *   (optional) An array of values to substitute into the query at placeholder
   *   markers. Passed by reference.
   *
   * @return $this
   */
  public function alterStatement(&$query, array &$args);

  /**
   * Fetches the next row from a result set.
   *
   * See http://php.net/manual/pdo.constants.php for the definition of the
   * constants used.
   *
   * @param \Doctrine\DBAL\Statement $dbal_statement
   *   The DBAL statement.
   * @param int $mode
   *   One of the PDO::FETCH_* constants.
   * @param string $fetch_class
   *   The class to be used for returning row results if \PDO::FETCH_CLASS
   *   is specified for $mode.
   *
   * @return mixed
   *   A result, formatted according to $mode.
   */
  public function delegateFetch(DbalStatement $dbal_statement, $mode, $fetch_class);

  /**
   * Returns the number of rows affected by the last SQL statement.
   *
   * @param \Doctrine\DBAL\Statement $dbal_statement
   *   The DBAL statement.
   *
   * @return int
   *   The number of rows affected by the last DELETE, INSERT, or UPDATE
   *   statement.
   */
  public function delegateRowCount(DbalStatement $dbal_statement);

  /**
   * Select delegated methods.
   */

  /**
   * Returns the SQL snippet that can be used for 'FOR UPDATE' selects.
   *
   * @return string|null
   *   The SQL string, or NULL if it is not supported.
   */
  public function getForUpdateSQL();

  /**
   * Insert delegated methods.
   */

  /**
   * Returns the name of the sequence to be checked for last insert id.
   *
   * @param string $drupal_table_name
   *   The Drupal name of the table.
   *
   * @return string|null
   *   The name of the sequence, or NULL if it is not needed.
   */
  public function getSequenceNameForInsert($drupal_table_name);

  /**
   * Determines if an INSERT query should explicity add default fields.
   *
   * Some DBMS accept using the 'default' keyword when entering default values
   * for fields.
   *
   * @return bool
   *   TRUE if the the 'default' keyword shall be used for INSERTing default
   *   for fields, FALSE otherwise.
   */
  public function getAddDefaultsExplicitlyOnInsert();

  /**
   * Returns SQL syntax for INSERTing a row with only default fields.
   *
   * @param string $sql
   *   The SQL string to be processed. Passed by reference.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   *
   * @return bool
   *   TRUE if the extension is returning an SQL string the check, FALSE if it
   *   has to be handled by DBAL.
   */
  public function delegateDefaultsOnlyInsertSql(&$sql, $drupal_table_name);

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
   * Alters the database default schema name.
   *
   * @param string $default_schema
   *   The default schema name. Passed by reference.
   *
   * @return $this
   */
  public function alterDefaultSchema(&$default_schema);

  /**
   * Returns a list of all tables in the current database.
   *
   * @return string[]
   *   An array of table names (as retrieved from the DBMS).
   */
  public function delegateListTableNames();

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
  public function getStringForDefault($string);

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
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name);

  /**
   * Post processes a table rename.
   *
   * Needed for example to rename a table's indexes.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the old Drupal name of the table.
   * @param string $drupal_new_table_name
   *   A string with the new Drupal name of the table.
   */
  public function postRenameTable(DbalSchema $dbal_schema, string $drupal_table_name, string $drupal_new_table_name): void;

  /**
   * Adds a new field to a table.
   *
   * @param bool $primary_key_processed_by_extension
   *   Passed by reference. TRUE if the extension also processed adding the
   *   primary key for the table, FALSE otherwise.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
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
  public function delegateAddField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options);

  /**
   * Drops a field from a table.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $field_name
   *   The name of the field.
   *
   * @return bool
   *   TRUE if the extension dropped the field, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateDropField(DbalSchema $dbal_schema, $drupal_table_name, $field_name);

  /**
   * Change a field definition.
   *
   * @param bool $primary_key_processed_by_extension
   *   Passed by reference. TRUE if the extension also processed adding the
   *   primary key for the table, FALSE otherwise.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
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
  public function delegateChangeField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options);

  /**
   * Sets the default value for a field.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
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
  public function delegateFieldSetDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name, $default);

  /**
   * Set a field to have no default value.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $field_name
   *   The name of the field.
   *
   * @return bool
   *   TRUE if the extension changed the default, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateFieldSetNoDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name);

  /**
   * Checks if an index exists.
   *
   * @param bool $result
   *   The result of the index existence check. Passed by reference.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $table_full_name
   *   The name of the table.
   * @param string $drupal_table_name
   *   The Drupal name of the table.
   * @param string $drupal_index_name
   *   The Drupal name of the index.
   *
   * @return bool
   *   TRUE if the extension managed the check, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $table_full_name, $drupal_table_name, $drupal_index_name);

  /**
   * Adds a primary key.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $table_full_name
   *   The name of the table.
   * @param string $drupal_table_name
   *   The Drupal name of the table.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   *
   * @return bool
   *   TRUE if the extension added the primary key, FALSE if it has to be
   *   handled by DBAL.
   */
  public function delegateAddPrimaryKey(DbalSchema $dbal_schema, $table_full_name, $drupal_table_name, array $drupal_field_specs);

  /**
   * Adds a unique key.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $table_full_name
   *   The name of the table.
   * @param string $index_full_name
   *   The name of the index.
   * @param string $drupal_table_name
   *   The Drupal name of the table.
   * @param string $drupal_index_name
   *   The Drupal name of the index.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   *
   * @return bool
   *   TRUE if the extension added the unique key, FALSE if it has to be
   *   handled by DBAL.
   */
  public function delegateAddUniqueKey(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name, array $drupal_field_specs);

  /**
   * Adds an index.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $table_full_name
   *   The name of the table.
   * @param string $index_full_name
   *   The name of the index.
   * @param string $drupal_table_name
   *   The Drupal name of the table.
   * @param string $drupal_index_name
   *   The Drupal name of the index.
   * @param array $drupal_field_specs
   *   The field specification array, as taken from a schema definition.
   * @param array $indexes_spec
   *   The table specification for the table, containing the index
   *   specification.
   *
   * @return bool
   *   TRUE if the extension added the index, FALSE if it has to be handled by
   *   DBAL.
   */
  public function delegateAddIndex(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name, array $drupal_field_specs, array $indexes_spec);

  /**
   * Drops an index.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $table_full_name
   *   The name of the table.
   * @param string $index_full_name
   *   The name of the index.
   * @param string $drupal_table_name
   *   The Drupal name of the table.
   * @param string $drupal_index_name
   *   The Drupal name of the index.
   *
   * @return bool
   *   TRUE if the extension dropped the index, FALSE if it has to be handled
   *   by DBAL.
   */
  public function delegateDropIndex(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name);

  /**
   * Retrieves a table comment.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   *
   * @return string
   *   The table comment.
   *
   * @throws \RuntimeExceptions
   *   When table comments are not supported.
   */
  public function delegateGetTableComment(DbalSchema $dbal_schema, $drupal_table_name);

  /**
   * Retrieves a column comment.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param string $column
   *   A string with the name of the column.
   *
   * @return string
   *   The column comment.
   *
   * @throws \RuntimeExceptions
   *   When column comments are not supported.
   */
  public function delegateGetColumnComment(DbalSchema $dbal_schema, $drupal_table_name, $column);

  /**
   * Alters a table comment to be written to the database.
   *
   * @param string $comment
   *   The comment. Passed by reference.
   * @param string $drupal_table_name
   *   A string with the Drupal name of the table.
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param array $drupal_table_spec
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
