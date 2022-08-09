<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Result as DbalResult;
use Doctrine\DBAL\Schema\Column as DbalColumn;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Statement as DbalStatement;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;
use Drupal\drudbal\Driver\Database\dbal\Statement\StatementWrapper;

/**
 * Abstract DBAL Extension.
 */
class AbstractExtension implements DbalExtensionInterface {

  /**
   * The DruDbal connection.
   *
   * @var \Drupal\drudbal\Driver\Database\dbal\Connection
   */
  protected $connection;

  /**
   * The Statement class to use for this extension.
   *
   * @var \Drupal\Core\Database\StatementInterface
   */
  protected $statementClass = StatementWrapper::class;

  /**
   * Enables debugging.
   *
   * @var bool
   */
  protected static $isDebugging = FALSE;

  /**
   * Constructs a DBAL extension object.
   *
   * @param \Drupal\drudbal\Driver\Database\dbal\Connection $drudbal_connection
   *   The Drupal database connection object for this extension.
   */
  public function __construct(DruDbalConnection $drudbal_connection) {
    $this->connection = $drudbal_connection;
  }

  /**
   * Destructs a DBAL extension object.
   */
  public function __destruct() {
    $this->connection = NULL;
  }

  /**
   * Sets debugging mode.
   *
   * @param bool $value
   *   The debugging mode.
   */
  public function setDebugging(bool $value): void {
    static::$isDebugging = $value;
  }

  /**
   * Gets debugging mode.
   *
   * @return bool
   *   The debugging mode.
   */
  public function getDebugging(): bool {
    return static::$isDebugging;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function getDbalConnection(): DbalConnection {
    return $this->connection->getDbalConnection();
  }

  /**
   * {@inheritdoc}
   */
  public function getStatementClass(): string {
    return $this->statementClass;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbServerPlatform(bool $strict = FALSE): string {
    throw new \LogicException("Method " . __METHOD__ . "() not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function getDbServerVersion(): string {
    throw new \LogicException("Method " . __METHOD__ . "() not implemented.");
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
  public function getDrupalTableName(string $prefix, string $db_table_name): ?string {
    $prefix_length = strlen($prefix);

    // Take into account tables that have an individual prefix.
    if ($prefix && substr($db_table_name, 0, $prefix_length) !== $prefix) {
      // This table name does not start the default prefix, which means that
      // it is not managed by Drupal so it should be excluded from the result.
      return NULL;
    }

    // Remove the prefix from the returned tables.
    return substr($db_table_name, $prefix_length);
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
  public function getDbFieldName($field_name, bool $quoted = TRUE) {
    if ($quoted && $field_name !== NULL && $field_name !== '' && substr($field_name, 0, 1) !== '"') {
      return '"' . str_replace('.', '"."', $field_name) . '"';
    }
    else {
      return $field_name;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDbAlias($alias, bool $quoted = TRUE) {
    if ($quoted && $alias !== NULL && $alias !== '' && substr($alias, 0, 1) !== '"') {
      return '"' . str_replace('.', '"."', $alias) . '"';
    }
    else {
      return $alias;
    }
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
  public function getDbIndexName(string $context, DbalSchema $dbal_schema, string $drupal_table_name, string $drupal_index_name): string {
    return $drupal_index_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalIndexName(string $drupal_table_name, string $db_index_name): string {
    return $db_index_name;
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
  public function delegateAttachDatabase(string $database): void {
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionalDdlSupport(array &$connection_options = []): bool {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
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
  public function delegateNextId(int $existing_id = 0): int {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryRange($query, $from, $count, array $args = [], array $options = []) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateHasJson(): bool {
    try {
      return (bool) $this->connection->query("SELECT JSON_TYPE('1')");
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Transaction delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateInTransaction(): bool {
    return $this->getDbalConnection()->isTransactionActive();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateBeginTransaction(): bool {
    return $this->getDbalConnection()->beginTransaction();;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateRollBack(): bool {
    return $this->getDbalConnection()->rollBack();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateCommit(): bool {
    return $this->getDbalConnection()->commit();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * DrudbalDateSql delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date): string {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFormatSql(string $field, string $format): string {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetTimezoneOffset(string $offset): void {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetFieldTimezoneOffsetSql(string &$field, int $offset): void {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * Statement delegated methods.
   */

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
  public function processFetchedRecord(array $record): array {
    return $record;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateRowCount(DbalResult $dbal_result) {
    return $dbal_result->rowCount();
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
   * {@inheritdoc}
   */
  public function alterFullQualifiedTableName(string $full_db_table_name): string {
    return $full_db_table_name;
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
   * Upsert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function hasNativeUpsert(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateUpsertSql(string $drupal_table_name, string $key, array $insert_fields, array $insert_values, string $comments = ''): string {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
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
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
  }

  /**
   * {@inheritdoc}
   */
  public function runInstallTasks(): array {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
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
  public function delegateColumnNameList(array $columns) {
    return $columns;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateListTableNames() {
    return $this->getDbalConnection()->createSchemaManager()->listTableNames();
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
  public function setDbalPlatformColumnOptions($context, DbalColumn $dbal_column, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStringForDefault($string) {
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
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
  public function postDropTable(DbalSchema $dbal_schema, string $drupal_table_name): void  {
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
  public function initAddedField(string $drupal_table_name, string $drupal_field_name, array $drupal_field_specs): void {
    if (isset($drupal_field_specs['initial_from_field'])) {
      if (isset($drupal_field_specs['initial'])) {
        $expression = "COALESCE([{$drupal_field_specs['initial_from_field']}], :default_initial_value)";
        $arguments = [':default_initial_value' => $drupal_field_specs['initial']];
      }
      else {
        $expression = "[{$drupal_field_specs['initial_from_field']}]";
        $arguments = [];
      }
      $this->connection->update($drupal_table_name)
        ->expression($drupal_field_name, $expression, $arguments)
        ->execute();
    }
    elseif (isset($drupal_field_specs['initial'])) {
      $this->connection->update($drupal_table_name)
        ->fields([$drupal_field_name => $drupal_field_specs['initial']])
        ->execute();
    }
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
    throw new \LogicException("Method " . __METHOD__ . " not implemented.");
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
  public function preprocessIndexFields(DbalSchema $dbal_schema, string $table_full_name, string $index_full_name, string $drupal_table_name, string $drupal_index_name, array $drupal_field_specs, array $indexes_spec): array {
    return $drupal_field_specs;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropPrimaryKey(bool &$primary_key_dropped_by_extension, string &$primary_key_asset_name, DbalSchema $dbal_schema, string $drupal_table_name): bool {
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
    return $dbal_schema->getTable($this->connection->getPrefixedTableName($drupal_table_name))->getComment();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetColumnComment(DbalSchema $dbal_schema, $drupal_table_name, $column) {
    if (
      $this->getDbalConnection()->getDatabasePlatform()->supportsInlineColumnComments() ||
      $this->getDbalConnection()->getDatabasePlatform()->supportsCommentOnStatement()
    ) {
      return $dbal_schema->getTable($this->connection->getPrefixedTableName($drupal_table_name))->getColumn($column)->getComment();
    }
    else {
      throw new \RuntimeException("Column comments are not supported");
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
