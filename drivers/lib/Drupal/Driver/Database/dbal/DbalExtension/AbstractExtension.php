<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Statement as DbalStatement;

/**
 * Abstract DBAL Extension.
 */
class AbstractExtension implements DbalExtensionInterface {

  /**
   * The DruDbal connection.
   *
   * @var \Drupal\Driver\Database\dbal\Connection
   */
  protected $connection;

  /**
   * The actual DBAL connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $dbalConnection;

  /**
   * The Statement class to use for this extension.
   *
   * @var \Drupal\Core\Database\StatementInterface
   */
  protected $statementClass;

  /**
   * Enables debugging.
   *
   * @var bool
   */
  protected $isDebugging = FALSE;

  /**
   * Constructs a DBAL extension object.
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
   * Sets debugging mode.
   *
   * @param bool $value
   *   The debugging mode.
   */
  public function setDebugging(bool $value): void {
    $this->isDebugging = $value;
  }

  /**
   * Gets debugging mode.
   *
   * @return bool
   *   The debugging mode.
   */
  public function getDebugging(): bool {
    return $this->isDebugging;
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function getDbalConnection() {
    return $this->dbalConnection;
  }

  /**
   * Database asset name resolution methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getDbTableName(string $drupal_prefix, string $drupal_table_name): string {
    return $drupal_prefix . $drupal_table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFullQualifiedTableName($drupal_table_name) {
    $options = $this->connection->getConnectionOptions();
    $prefix = $this->connection->tablePrefix($drupal_table_name);
    return $options['database'] . '.' . $this->getDbTableName($prefix, $drupal_table_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFieldName($field_name) {
    return $field_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbAlias($alias) {
    return $alias;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveAliases(?string $unaliased): string {
    return $unaliased;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbIndexName($context, DbalSchema $dbal_schema, $drupal_table_name, $index_name, array $table_prefix_info) {
    return $index_name;
  }

  /**
   * Returns a fully prefixed table name from Drupal's {table} syntax.
   *
   * @param string $drupal_table
   *   The table name in Drupal's syntax.
   *
   * @return string
   *   The fully prefixed table name to be used in the DBMS.
   */
  protected function tableName($drupal_table) {
    return $this->connection->getPrefixedTableName($drupal_table);
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionSupport(array &$connection_options = []) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionalDdlSupport(array &$connection_options = []) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function preCreateDatabase($database_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postCreateDatabase($database_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateMapConditionOperator($operator) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNextId($existing_id = 0) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryRange($query, $from, $count, array $args = [], array $options = []) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryTemporary($drupal_table_name, $query, array $args = [], array $options = []) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * PlatformSql delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date) : string {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFormatSql(string $field, string $format) : string {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetTimezoneOffset(string $offset) : void {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetFieldTimezoneOffsetSql(string &$field, int $offset) : void {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function onSelectPrefetchAllData() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNamedPlaceholdersSupport() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterStatement(&$query, array &$args) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFetch(DbalStatement $dbal_statement, $mode, $fetch_class) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateRowCount(DbalStatement $dbal_statement) {
    return $dbal_statement->rowCount();
  }

  /**
   * Select delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getForUpdateSQL() {
    return ' FOR UPDATE';
  }

  /**
   * Insert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getSequenceNameForInsert($drupal_table_name) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAddDefaultsExplicitlyOnInsert() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDefaultsOnlyInsertSql(&$sql, $drupal_table_name) {
    return FALSE;
  }

  /**
   * Truncate delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function preTruncate($drupal_table_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postTruncate($drupal_table_name) {
    return $this;
  }

  /**
   * Install\Tasks delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function delegateInstallConnectExceptionProcess(\Exception $e) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function runInstallTasks() {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * Schema delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function alterDefaultSchema(&$default_schema) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateListTableNames() {
    return $this->getDbalConnection()->getSchemaManager()->listTableNames();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTableExists(&$result, $drupal_table_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldExists(&$result, $drupal_table_name, $field_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterCreateTableOptions(DbalTable $dbal_table, DbalSchema $dbal_schema, array &$drupal_table_specs, $drupal_table_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnOptions($context, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStringForDefault($string) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postRenameTable(DbalSchema $dbal_schema, string $drupal_table_name, string $drupal_new_table_name): void {
    return;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropField(DbalSchema $dbal_schema, $drupal_table_name, $field_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name, $default) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetNoDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $table_full_name, $drupal_table_name, $drupal_index_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddPrimaryKey(DbalSchema $dbal_schema, $table_full_name, $drupal_table_name, array $drupal_field_specs) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddUniqueKey(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name, array $drupal_field_specs) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddIndex(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name, array $drupal_field_specs, array $indexes_spec) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropIndex(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetTableComment(DbalSchema $dbal_schema, $drupal_table_name) {
    throw new \RuntimeException("Table comments are not supported for '" . $this->dbalConnection->getDriver()->getName() . "'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetColumnComment(DbalSchema $dbal_schema, $drupal_table_name, $column) {
    if ($this->getDbalConnection()->getDatabasePlatform()->supportsInlineColumnComments()) {
      return $dbal_schema->getTable($this->tableName($drupal_table_name))->getColumn($column)->getComment();
    }
    else {
      throw new \RuntimeException("Column comments are not supported for '" . $this->dbalConnection->getDriver()->getName() . "'");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterSetTableComment(&$comment, $drupal_table_name, DbalSchema $dbal_schema, array $drupal_table_spec) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function alterSetColumnComment(&$comment, $dbal_type, array $drupal_field_specs, $field_name) {
    return $this;
  }

}
