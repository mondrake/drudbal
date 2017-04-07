<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\Schema as DatabaseSchema;
use Drupal\Component\Utility\Unicode;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\SchemaException as DBALSchemaException;
use Doctrine\DBAL\Types\Type as DbalType;

/**
 * DruDbal implementation of \Drupal\Core\Database\Schema.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver extension
 * specific code in
 * Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name] classes and
 * execution handed over to there.
 */
class Schema extends DatabaseSchema {

  /**
   * Current connection DBAL schema manager.
   *
   * @var \Doctrine\DBAL\Schema\AbstractSchemaManager
   */
  protected $dbalSchemaManager;

  /**
   * Current connection DBAL platform.
   *
   * @var \Doctrine\DBAL\Platforms\AbstractPlatform
   */
  protected $dbalPlatform;

  /**
   * The DruDbal extension for the DBAL driver.
   *
   * @var \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   */
  protected $dbalExt;

  /**
   * Constructs a Schema object.
   *
   * @var \Drupal\Driver\Database\dbal\Connection
   *   The DBAL driver Drupal database connection.
   */
  public function __construct(Connection $connection) {
    parent::__construct($connection);
    $this->dbalExt = $this->connection->getDruDbalDriver();
    $this->dbalSchemaManager = $this->connection->getDbalConnection()->getSchemaManager();
    $this->dbalPlatform = $this->connection->getDbalConnection()->getDatabasePlatform();
  }

  /**
   * {@inheritdoc}
   */
  public function createTable($name, $table) {
    if ($this->tableExists($name)) {
      throw new SchemaObjectExistsException(t('Table @name already exists.', ['@name' => $name]));
    }

    // Create table via DBAL.
    $schema = new DbalSchema;
    $new_table = $schema->createTable($this->dbalExt->pfxTable($name));

    // Delegate adding options to DBAL driver extension.
    $this->dbalExt->delegateCreateTableSetOptions($new_table, $schema, $table, $name);

    // Add table comment.
    if (!empty($table['description'])) {
      $comment = $this->connection->prefixTables($table['description']);
      $comment = $this->dbalExt->alterSetTableComment($comment, $name, $schema, $table);
      $new_table->addOption('comment', $this->prepareComment($comment));
    }

    // Add columns.
    foreach ($table['fields'] as $field_name => $field) {
      $dbal_type = $this->getDbalColumnType($field);
      $new_column = $new_table->addColumn($field_name, $dbal_type, ['columnDefinition' => $this->getDbalColumnDefinition($field_name, $dbal_type, $field)]);
    }

    // Add primary key.
    if (!empty($table['primary key'])) {
      // @todo in MySql, this could still be a list of columns with length.
      // However we have to add here instead of separate calls to
      // ::addPrimaryKey to avoid failure when creating a table with an
      // autoincrement column.
      $new_table->setPrimaryKey($this->dbalResolveIndexColumnList($table['primary key']));
    }

    // Execute the table creation.
    $sql_statements = $schema->toSql($this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);

    // Add unique keys.
    if (!empty($table['unique keys'])) {
      foreach ($table['unique keys'] as $key => $fields) {
        $this->addUniqueKey($name, $key, $fields);
      }
    }

    // Add indexes.
    if (!empty($table['indexes'])) {
      foreach ($table['indexes'] as $index => $fields) {
        $this->addIndex($name, $index, $fields, $table);
      }
    }
  }

  /**
   * Gets DBAL column type, given Drupal's field specs.
   *
   * @param array $field
   *   A field description array, as specified in the schema documentation.
   *
   * @return string
   *   The string identifier of the DBAL column type.
   */
  protected function getDbalColumnType(array $field) {
    $dbal_type = NULL;

    // Delegate to DBAL driver extension.
    if ($this->dbalExt->delegateGetDbalColumnType($dbal_type, $field)) {
      return $dbal_type;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }
    $map = $this->getFieldTypeMap();
    // @todo check if exception should be raised if no key in array.
    $dbal_type = $map[$field['type'] . ':' . $field['size']];
    return $dbal_type;
  }

  /**
   * Gets DBAL column options, given Drupal's field specs.
   *
   * @param string $field_name
   *   The column name.
   * @param string $dbal_type
   *   The string identifier of the DBAL column type.
   * @param array $field
   *   A field description array, as specified in the schema documentation.
   *
   * @return string
   *   The SQL column definition specification, for use in the
   *   'columnDefinition' DBAL column option.
   */
  protected function getDbalColumnDefinition($field_name, $dbal_type, array $field) {
    $options = [];

    $options['type'] = DbalType::getType($dbal_type);

    if (isset($field['length'])) {
      $options['length'] = $field['length'];
    }

    if (isset($field['precision']) && isset($field['scale'])) {
      $options['precision'] = $field['precision'];
      $options['scale'] = $field['scale'];
    }

    if (!empty($field['unsigned'])) {
      $options['unsigned'] = $field['unsigned'];
    }

    if (!empty($field['not null'])) {
      $options['notnull'] = (bool) $field['not null'];
    }
    else {
      $options['notnull'] = FALSE;
    }

    // $field['default'] can be NULL, so we explicitly check for the key here.
    if (array_key_exists('default', $field)) {
      if (is_null($field['default'])) {
        if ((isset($field['not null']) && (bool) $field['not null'] === FALSE) || !isset($field['not null'])) {
          $options['notnull'] = FALSE;
        }
      }
      else {
        $options['default'] = $this->dbalExt->encodeDefaultValue($field['default']);
      }
    }

    if (isset($field['type']) && $field['type'] == 'serial') {
      $options['autoincrement'] = TRUE;
      $options['notnull'] = TRUE;
    }

    if (!empty($field['description'])) {
      $comment = $this->connection->prefixTables($field['description']);
      $comment = $this->dbalExt->alterSetColumnComment($comment, $dbal_type, $field, $field_name);
      $options['comment'] = $this->prepareComment($comment);
    }

    // Let DBAL driver extension alter the column options if required.
    $this->dbalExt->alterDbalColumnOptions($options, $dbal_type, $field, $field_name);

    // Get the column definition from DBAL, and trim the field name.
    $dbal_column_definition = substr($this->dbalPlatform->getColumnDeclarationSQL($field_name, $options), strlen($field_name) + 1);

    // Let DBAL driver extension alter the column definition if required.
    $this->dbalExt->alterDbalColumnDefinition($dbal_column_definition, $options, $dbal_type, $field, $field_name);

    return $dbal_column_definition;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip. This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    // $map does not use drupal_static as its value never changes.
    static $map = [
      'varchar_ascii:normal' => 'string',

      'varchar:normal'  => 'string',
      'char:normal'     => 'string',

      'text:tiny'       => 'text',
      'text:small'      => 'text',
      'text:medium'     => 'text',
      'text:big'        => 'text',
      'text:normal'     => 'text',

      'serial:tiny'     => 'smallint',
      'serial:small'    => 'smallint',
      'serial:medium'   => 'integer',
      'serial:big'      => 'bigint',
      'serial:normal'   => 'integer',

      'int:tiny'        => 'smallint',
      'int:small'       => 'smallint',
      'int:medium'      => 'integer',
      'int:big'         => 'bigint',
      'int:normal'      => 'integer',

      'float:tiny'      => 'float',
      'float:small'     => 'float',
      'float:medium'    => 'float',
      'float:big'       => 'float',
      'float:normal'    => 'float',

      'numeric:normal'  => 'decimal',

      'blob:big'        => 'blob',
      'blob:normal'     => 'blob',
    ];
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function renameTable($table, $new_name) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot rename @table to @table_new: table @table doesn't exist.", ['@table' => $table, '@table_new' => $new_name]));
    }
    if ($this->tableExists($new_name)) {
      throw new SchemaObjectExistsException(t("Cannot rename @table to @table_new: table @table_new already exists.", ['@table' => $table, '@table_new' => $new_name]));
    }

    $this->dbalSchemaManager->renameTable($this->dbalExt->pfxTable($table), $this->dbalExt->pfxTable($new_name));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dropTable($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    $this->dbalSchemaManager->dropTable($this->dbalExt->pfxTable($table));
  }

  /**
   * {@inheritdoc}
   */
  public function addField($table, $field, $spec, $keys_new = []) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add field @table.@field: table doesn't exist.", ['@field' => $field, '@table' => $table]));
    }
    if ($this->fieldExists($table, $field)) {
      throw new SchemaObjectExistsException(t("Cannot add field @table.@field: field already exists.", ['@field' => $field, '@table' => $table]));
    }

    $fixnull = FALSE;
    if (!empty($spec['not null']) && !isset($spec['default'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    $dbal_table = $to_schema->getTable($this->dbalExt->pfxTable($table));

    // Drop primary key if it is due to be changed.
    if (!empty($keys_new['primary key']) && $dbal_table->hasPrimaryKey()) {
      $dbal_table->dropPrimaryKey();
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
      $this->dbalExecuteSchemaChange($sql_statements);
      $current_schema = clone $to_schema;
    }

    // Delegate to DBAL driver extension.
    $primary_key_processed_by_driver = FALSE;
    $dbal_type = $this->getDbalColumnType($spec);
    $dbal_column_definition = $this->getDbalColumnDefinition($field, $dbal_type, $spec);
    if (!$this->dbalExt->delegateAddField($primary_key_processed_by_driver, $table, $field, $spec, $keys_new, $dbal_column_definition)) {
      // DBAL driver extension did not pick up, proceed with DBAL.
      $dbal_table->addColumn($field, $dbal_type, ['columnDefinition' => $dbal_column_definition]);
      // Manage change to primary key.
      if (!empty($keys_new['primary key'])) {
        // @todo in MySql, this could still be a list of columns with length.
        // However we have to add here instead of separate calls to
        // ::addPrimaryKey to avoid failure when creating a table with an
        // autoincrement column.
        $dbal_table->setPrimaryKey($this->dbalResolveIndexColumnList($keys_new['primary key']));
      }
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
      $this->dbalExecuteSchemaChange($sql_statements);
    }

    // Add unique keys.
    if (!empty($keys_new['unique keys'])) {
      foreach ($keys_new['unique keys'] as $key => $fields) {
        $this->addUniqueKey($table, $key, $fields);
      }
    }

    // Add indexes.
    if (!empty($keys_new['indexes'])) {
      foreach ($keys_new['indexes'] as $index => $fields) {
        $this->addIndex($table, $index, $fields, $keys_new);
      }
    }

    if (isset($spec['initial'])) {
      $this->connection->update($table)
        ->fields([$field => $spec['initial']])
        ->execute();
    }
    if (isset($spec['initial_from_field'])) {
      $this->connection->update($table)
        ->expression($field, $spec['initial_from_field'])
        ->execute();
    }
    if ($fixnull) {
      $spec['not null'] = TRUE;
      $this->changeField($table, $field, $field, $spec);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function dropField($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      return FALSE;
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->dbalExt->pfxTable($table))->dropColumn($field);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetDefault($table, $field, $default) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot set default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    // Delegate to DBAL driver extension.
    if ($this->dbalExt->delegateFieldSetDefault($table, $field, $this->escapeDefaultValue($default))) {
      return;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    // @todo this may not work - need to see if ::escapeDefaultValue
    // provides a sensible output.
    $to_schema->getTable($this->dbalExt->pfxTable($table))->getColumn($field)->setDefault($this->escapeDefaultValue($default));
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetNoDefault($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot remove default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    // Delegate to DBAL driver extension.
    if ($this->dbalExt->delegateFieldSetNoDefault($table, $field)) {
      return;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    // @todo this may not work - we need to 'DROP' the default, not set it
    // to null.
    $to_schema->getTable($this->dbalExt->pfxTable($table))->getColumn($field)->setDefault(NULL);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists($table, $name) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    // Delegate to DBAL driver extension.
    $result = FALSE;
    if ($this->dbalExt->delegateIndexExists($result, $table, $name)) {
      return $result;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    return in_array($name, array_keys($this->dbalSchemaManager->listTableIndexes($this->dbalExt->pfxTable($table))));
  }

  /**
   * {@inheritdoc}
   */
  public function addPrimaryKey($table, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add primary key to table @table: table doesn't exist.", ['@table' => $table]));
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    if ($current_schema->getTable($this->dbalExt->pfxTable($table))->hasPrimaryKey()) {
      throw new SchemaObjectExistsException(t("Cannot add primary key to table @table: primary key already exists.", ['@table' => $table]));
    }

    // Delegate to DBAL driver extension.
    if ($this->dbalExt->delegateAddPrimaryKey($current_schema, $table, $fields)) {
      return;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->dbalExt->pfxTable($table))->setPrimaryKey($this->dbalResolveIndexColumnList($fields));
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);
  }

  /**
   * {@inheritdoc}
   */
  public function dropPrimaryKey($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    if (!$current_schema->getTable($this->dbalExt->pfxTable($table))->hasPrimaryKey()) {
      return FALSE;
    }

    $to_schema = clone $current_schema;
    $to_schema->getTable($this->dbalExt->pfxTable($table))->dropPrimaryKey();
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function addUniqueKey($table, $name, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add unique key @name to table @table: table doesn't exist.", ['@table' => $table, '@name' => $name]));
    }
    if ($this->indexExists($table, $name)) {
      throw new SchemaObjectExistsException(t("Cannot add unique key @name to table @table: unique key already exists.", ['@table' => $table, '@name' => $name]));
    }

    // Delegate to DBAL driver extension.
    if ($this->dbalExt->delegateAddUniqueKey($table, $name, $fields)) {
      return;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->dbalExt->pfxTable($table))->addUniqueIndex($this->dbalResolveIndexColumnList($fields), $name);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);
  }

  /**
   * {@inheritdoc}
   */
  public function dropUniqueKey($table, $name) {
    return $this->dropIndex($table, $name);
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex($table, $name, $fields, array $spec) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add index @name to table @table: table doesn't exist.", ['@table' => $table, '@name' => $name]));
    }
    if ($this->indexExists($table, $name)) {
      throw new SchemaObjectExistsException(t("Cannot add index @name to table @table: index already exists.", ['@table' => $table, '@name' => $name]));
    }

    // Delegate to DBAL driver extension.
    if ($this->dbalExt->delegateAddIndex($table, $name, $fields, $spec)) {
      return;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->dbalExt->pfxTable($table))->addIndex($this->dbalResolveIndexColumnList($fields), $name);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements);
  }

  /**
   * {@inheritdoc}
   */
  public function dropIndex($table, $name) {
    if (!$this->indexExists($table, $name)) {
      return FALSE;
    }
    $this->dbalSchemaManager->dropIndex($name, $this->dbalExt->pfxTable($table));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function changeField($table, $field, $field_new, $spec, $keys_new = []) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot change the definition of field @table.@name: field doesn't exist.", ['@table' => $table, '@name' => $field]));
    }
    if (($field != $field_new) && $this->fieldExists($table, $field_new)) {
      throw new SchemaObjectExistsException(t("Cannot rename field @table.@name to @name_new: target field already exists.", ['@table' => $table, '@name' => $field, '@name_new' => $field_new]));
    }

    $dbal_type = $this->getDbalColumnType($spec);
    $dbal_column_definition = $this->getDbalColumnDefinition($field_new, $dbal_type, $spec);
    // DBAL is limited here, if we pass only 'columnDefinition' to
    // ::changeColumn the schema diff will not capture any change. We need to
    // fallback to platform specific syntax.
    // @see https://github.com/doctrine/dbal/issues/1033
    $primary_key_processed_by_driver = FALSE;
    if (!$this->dbalExt->delegateChangeField($primary_key_processed_by_driver, $table, $field, $field_new, $spec, $keys_new, $dbal_column_definition)) {
      return;
    }

    // New primary key.
    if (!empty($keys_new['primary key']) && !$primary_key_processed_by_driver) {
      // Drop the existing one before altering the table.
      $this->dropPrimaryKey($table);
      $this->addPrimaryKey($table, $keys_new['primary key']);
    }

    // Add unique keys.
    if (!empty($keys_new['unique keys'])) {
      foreach ($keys_new['unique keys'] as $key => $fields) {
        $this->addUniqueKey($table, $key, $fields);
      }
    }

    // Add indexes.
    if (!empty($keys_new['indexes'])) {
      foreach ($keys_new['indexes'] as $index => $fields) {
        $this->addIndex($table, $index, $fields, $keys_new);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function prepareComment($comment, $length = NULL) {
    // Truncate comment to maximum comment length.
    if (isset($length)) {
      // Add table prefixes before truncating.
      $comment = Unicode::truncate($comment, $length, TRUE, TRUE);
    }
    // Remove semicolons to avoid triggering multi-statement check.
    $comment = strtr($comment, [';' => '.']);
    return $comment;
  }

  /**
   * Retrieves a table or column comment.
   *
   * @param string $table
   *   The table name.
   * @param string $column
   *   (optional) The column name. If NULL, the table comment will be
   *   retrieved.
   *
   * @return string
   *   The retrieved comment.
   */
  public function getComment($table, $column = NULL) {
    $dbal_schema = $this->dbalSchemaManager->createSchema();
    $comment = NULL;

    // Delegate to DBAL driver extension.
    if ($this->dbalExt->delegateGetComment($comment, $dbal_schema, $table, $column)) {
      return $comment;
    }

    // DBAL driver extension did not pick up, proceed with DBAL.
    if (isset($column)) {
      $comment = $dbal_schema->getTable($this->dbalExt->pfxTable($table))->getColumn($column)->getComment();
      // Let DBAL driver extension cleanup the comment if necessary.
      $this->dbalExt->alterGetComment($comment, $dbal_schema, $table, $column);
      return $comment;
    }
    // DBAL cannot retrieve table comments from introspected schema. DBAL
    // driver extension should have processed it already.
    // @see https://github.com/doctrine/dbal/issues/1335
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function tableExists($table) {
    return $this->dbalSchemaManager->tablesExist([$this->dbalExt->pfxTable($table)]);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldExists($table, $column) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    return in_array($column, array_keys($this->dbalSchemaManager->listTableColumns($this->dbalExt->pfxTable($table))));
  }

  /**
   * Executes a number of DDL SQL statements.
   *
   * @param string[] $sql_statements
   *   The DDL SQL statements to execute.
   */
  protected function dbalExecuteSchemaChange(array $sql_statements) {
    foreach ($sql_statements as $sql) {
      $this->connection->getDbalConnection()->exec($sql);
    }
  }

  /**
   * Gets the list of columns to be used for index manipulation operations.
   *
   * @param array[] $fields
   *   An array of field description arrays, as specified in the schema
   *   documentation.
   *
   * @return string[]|false
   *   The list of columns, or FALSE if it cannot be determined (e.g. because
   *   there are column leghts specified, that DBAL cannot process).
   *
   * @see https://github.com/doctrine/dbal/pull/2412
   */
  protected function dbalResolveIndexColumnList(array $fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        return FALSE;
      }
      else {
        $return[] = $field;
      }
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function findTables($table_expression) {
    $individually_prefixed_tables = $this->connection->getUnprefixedTablesMap();
    $default_prefix = $this->connection->tablePrefix();
    $default_prefix_length = strlen($default_prefix);

    $tables = [];
    foreach ($this->dbalSchemaManager->listTableNames() as $table_name) {
      // Take into account tables that have an individual prefix.
      if (isset($individually_prefixed_tables[$table_name])) {
        $prefix_length = strlen($this->connection->tablePrefix($individually_prefixed_tables[$table_name]));
      }
      elseif ($default_prefix && substr($table_name, 0, $default_prefix_length) !== $default_prefix) {
        // This table name does not start the default prefix, which means that
        // it is not managed by Drupal so it should be excluded from the result.
        continue;
      }
      else {
        $prefix_length = $default_prefix_length;
      }

      // Remove the prefix from the returned tables.
      $unprefixed_table_name = substr($table_name, $prefix_length);

      // The pattern can match a table which is the same as the prefix. That
      // will become an empty string when we remove the prefix, which will
      // probably surprise the caller, besides not being a prefixed table. So
      // remove it.
      if (!empty($unprefixed_table_name)) {
        $tables[$unprefixed_table_name] = $unprefixed_table_name;
      }
    }

    // Convert the table expression from its SQL LIKE syntax to a regular
    // expression and escape the delimiter that will be used for matching.
    $table_expression = str_replace(['%', '_'], ['.*?', '.'], preg_quote($table_expression, '/'));
    $tables = preg_grep('/^' . $table_expression . '$/i', $tables);

    return $tables;
  }

}
