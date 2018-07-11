<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\Schema as DatabaseSchema;
use Drupal\Component\Utility\Unicode;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Column as DbalColumn;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\SchemaException as DbalSchemaException;
use Doctrine\DBAL\Types\Type as DbalType;

// @todo DBAL 2.6.0:
// Added support for column inline comments in SQLite, check status (the declaration of support + the fact that on add/change field it does not work)
// @todo DBAL 2.7:
// implemented mysql column-level collation, check

/**
 * DruDbal implementation of \Drupal\Core\Database\Schema.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Schema extends DatabaseSchema {

  /**
   * DBAL schema manager.
   *
   * @var \Doctrine\DBAL\Schema\AbstractSchemaManager
   */
  protected $dbalSchemaManager;

  /**
   * DBAL platform.
   *
   * @var \Doctrine\DBAL\Platforms\AbstractPlatform
   */
  protected $dbalPlatform;

  /**
   * Current DBAL schema.
   *
   * @var \Doctrine\DBAL\Schema\Schema
   */
  protected $dbalCurrentSchema;

  /**
   * The Dbal extension for the DBAL driver.
   *
   * @var \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   */
  protected $dbalExtension;

  /**
   * Constructs a Schema object.
   *
   * @var \Drupal\Driver\Database\dbal\Connection
   *   The DBAL driver Drupal database connection.
   */
  public function __construct(Connection $connection) {
    parent::__construct($connection);
    $this->dbalExtension = $this->connection->getDbalExtension();
    $this->dbalSchemaManager = $this->connection->getDbalConnection()->getSchemaManager();
    $this->dbalPlatform = $this->connection->getDbalConnection()->getDatabasePlatform();
    $this->dbalExtension->alterDefaultSchema($this->defaultSchema);
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
   * {@inheritdoc}
   */
  public function createTable($name, $table) {
    if ($this->tableExists($name)) {
      throw new SchemaObjectExistsException(t('Table @name already exists.', ['@name' => $name]));
    }

    // Check primary key does not have nullable fields.
    if (!empty($table['primary key']) && is_array($table['primary key'])) {
      $this->ensureNotNullPrimaryKey($table['primary key'], $table['fields']);
    }

    // Create table via DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $new_table = $to_schema->createTable($this->tableName($name));

    // Add table comment.
    if (!empty($table['description'])) {
      $comment = $this->connection->prefixTables($table['description']);
      $this->dbalExtension->alterSetTableComment($comment, $name, $to_schema, $table);
      $new_table->addOption('comment', $this->prepareComment($comment));
    }

    // Let DBAL extension alter the table options if required.
    $this->dbalExtension->alterCreateTableOptions($new_table, $to_schema, $table, $name);

    // Add columns.
    foreach ($table['fields'] as $field_name => $field) {
      $dbal_type = $this->getDbalColumnType($field);
      $new_table->addColumn($this->dbalExtension->getDbFieldName($field_name), $dbal_type, $this->getDbalColumnOptions('createTable', $field_name, $dbal_type, $field));
    }

    // Add primary key.
    if (!empty($table['primary key'])) {
      // @todo in MySql, this could still be a list of columns with length.
      // However we have to add here instead of separate calls to
      // ::addPrimaryKey to avoid failure when creating a table with an
      // autoincrement column.
      $new_table->setPrimaryKey($this->dbalGetFieldList($table['primary key']));
    }

    // Execute the table creation.
    $this->dbalExecuteSchemaChange($to_schema);

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
  public function getDbalColumnType(array $field) {
    $dbal_type = NULL;

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateGetDbalColumnType($dbal_type, $field)) {
      return $dbal_type;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }
    $map = $this->getFieldTypeMap();

    $key = $field['type'] . ':' . $field['size'];
    if (!isset($map[$key])) {
      throw new \InvalidArgumentException("There is no DBAL mapping for column type $key");
    }

    return $map[$key];
  }

  /**
   * Gets DBAL column options, given Drupal's field specs.
   *
   * @param string $context
   *   The context from where the method is called. Can be 'createTable',
   *   'addField', 'changeField'.
   * @param string $field_name
   *   The column name.
   * @param string $dbal_type
   *   The string identifier of the DBAL column type.
   * @param array $field
   *   A field description array, as specified in the schema documentation.
   *
   * @return array
   *   An array of DBAL column options, including the SQL column definition
   *   specification in the 'columnDefinition' option.
   */
  public function getDbalColumnOptions($context, $field_name, $dbal_type, array $field) {
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
        $options['default'] = $this->dbalExtension->getStringForDefault($field['default']);
      }
    }

    if (isset($field['type']) && $field['type'] == 'serial') {
      $options['autoincrement'] = TRUE;
      $options['notnull'] = TRUE;
    }

    if (!empty($field['description'])) {
      $comment = $this->connection->prefixTables($field['description']);
      $this->dbalExtension->alterSetColumnComment($comment, $dbal_type, $field, $field_name);
      $options['comment'] = $this->prepareComment($comment);
    }

    // Let DBAL extension alter the column options if required.
    $this->dbalExtension->alterDbalColumnOptions($context, $options, $dbal_type, $field, $field_name);

    // Get the column definition from DBAL, and trim the field name.
    $dbal_column = new DbalColumn($field_name, DbalType::getType($dbal_type), $options);
    $this->dbalExtension->setDbalPlatformColumnOptions($context, $dbal_column, $options, $dbal_type, $field, $field_name);
    $dbal_column_definition = substr($this->dbalPlatform->getColumnDeclarationSQL($field_name, $dbal_column->toArray()), strlen($field_name) + 1);

    // Let DBAL extension alter the column definition if required.
    $this->dbalExtension->alterDbalColumnDefinition($context, $dbal_column_definition, $options, $dbal_type, $field, $field_name);

    // Add the SQL column definiton as the 'columnDefinition' option.
    $options['columnDefinition'] = $dbal_column_definition;

    return $options;
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

    // DBAL Schema will drop the old table and create a new one, so we go for
    // using the manager instead, that allows in-place renaming.
    // @see https://github.com/doctrine/migrations/issues/17
    if ($this->dbalExtension->getDebugging()) {
      error_log('renameTable ' . $this->tableName($table) . ' to ' . $this->tableName($new_name));
    }
    $dbal_schema = $this->dbalSchema();
    $this->dbalSchemaManager->renameTable($this->tableName($table), $this->tableName($new_name));
    $this->dbalExtension->postRenameTable($dbal_schema, $table, $new_name);
    $this->dbalSchemaForceReload();
  }

  /**
   * {@inheritdoc}
   */
  public function dropTable($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    // We use the manager directly here, in some tests a table is added to a
    // different connection and its DBAL schema will be a different object, so
    // on drop it fails to find it.
    // @todo open a Drupal issue to fix SchemaTest::findTables?
    // @todo this will affect possibility to drop FKs in an orderly way, so
    // we would need to revise at later stage if we want the driver to support
    // a broader set of capabilities.
    $table_full_name = $this->tableName($table);
    $current_schema = $this->dbalSchema();
    $this->dbalSchemaManager->dropTable($table_full_name);

    // After dropping the table physically, still need to reflect it in the
    // DBAL schema.
    try {
      $current_schema->dropTable($table_full_name);
    }
    catch (DbalSchemaException $e) {
      if ($e->getCode() === DbalSchemaException::TABLE_DOESNT_EXIST) {
        // If the table is not in the DBAL schema, then we are good anyway.
        return TRUE;
      }
      throw $e;
    }

    $this->dbalExtension->postDropTable($current_schema, $table);
    return TRUE;
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

    // Fields that are part of a PRIMARY KEY must be added as NOT NULL.
    $is_primary_key = isset($keys_new['primary key']) && in_array($field, $keys_new['primary key'], TRUE);
    if ($is_primary_key) {
      $this->ensureNotNullPrimaryKey($keys_new['primary key'], [$field => $spec]);
    }

    $fixnull = FALSE;
    if (!empty($spec['not null']) && !isset($spec['default'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }

    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $dbal_table = $to_schema->getTable($this->tableName($table));

    // Drop primary key if it is due to be changed.
    if (!empty($keys_new['primary key']) && $dbal_table->hasPrimaryKey()) {
      $dbal_table->dropPrimaryKey();
      $this->dbalExecuteSchemaChange($to_schema);
      $current_schema = $this->dbalSchema();
      $to_schema = clone $current_schema;
      $dbal_table = $to_schema->getTable($this->tableName($table));
    }

    // Delegate to DBAL extension.
    $primary_key_processed_by_extension = FALSE;
    $dbal_type = $this->getDbalColumnType($spec);
    $dbal_column_options = $this->getDbalColumnOptions('addField', $field, $dbal_type, $spec);
    if ($this->dbalExtension->delegateAddField($primary_key_processed_by_extension, $this->dbalSchema(), $table, $field, $spec, $keys_new, $dbal_column_options)) {
      $this->dbalSchemaForceReload();
    }
    else {
      // DBAL extension did not pick up, proceed with DBAL.
      $dbal_table->addColumn($this->dbalExtension->getDbFieldName($field), $dbal_type, $dbal_column_options);
      // Manage change to primary key.
      if (!empty($keys_new['primary key'])) {
        // @todo in MySql, this could still be a list of columns with length.
        // However we have to add here instead of separate calls to
        // ::addPrimaryKey to avoid failure when creating a table with an
        // autoincrement column.
        $dbal_table->setPrimaryKey($this->dbalGetFieldList($keys_new['primary key']));
      }
      $this->dbalExecuteSchemaChange($to_schema);
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

    // Apply the initial value if set.
    if (isset($spec['initial'])) {
      $this->connection->update($table)
        ->fields([$field => $spec['initial']])
        ->execute();
    }
    if (isset($spec['initial_from_field'])) {
      if (isset($spec['initial'])) {
        $expression = 'COALESCE(' . $spec['initial_from_field'] . ', :default_initial_value)';
        $arguments = [':default_initial_value' => $spec['initial']];
      }
      else {
        $expression = $spec['initial_from_field'];
        $arguments = [];
      }
      $this->connection->update($table)
        ->expression($field, $expression, $arguments)
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

    // When dropping a field that is part of a primary key, delete the entire
    // primary key.
    $primary_key = $this->findPrimaryKeyColumns($table);
    if (count($primary_key) && in_array($field, $primary_key, TRUE)) {
      try {
        $this->dropPrimaryKey($table);
      }
      catch (DBALException $e) {
      }
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateDropField($this->dbalSchema(), $table, $field)) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->tableName($table))->dropColumn($this->dbalExtension->getDbFieldName($field));
    $this->dbalExecuteSchemaChange($to_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetDefault($table, $field, $default) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot set default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateFieldSetDefault($this->dbalSchema(), $table, $field, $this->escapeDefaultValue($default))) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    // @todo this may not work - need to see if ::escapeDefaultValue
    // provides a sensible output.
    $to_schema->getTable($this->tableName($table))->getColumn($this->dbalExtension->getDbFieldName($field))->setDefault($this->escapeDefaultValue($default));
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetNoDefault($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot remove default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateFieldSetNoDefault($this->dbalSchema(), $table, $field)) {
      $this->dbalSchemaForceReload();
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    // @todo this may not work - we need to 'DROP' the default, not set it
    // to null.
    $to_schema->getTable($this->tableName($table))->getColumn($this->dbalExtension->getDbFieldName($field))->setDefault(NULL);
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists($table, $name) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    $table_full_name = $this->tableName($table);

    // Delegate to DBAL extension.
    $result = FALSE;
    if ($this->dbalExtension->delegateIndexExists($result, $this->dbalSchema(), $table_full_name, $table, $name)) {
      return $result;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $index_full_name = $this->dbalExtension->getDbIndexName('indexExists', $this->dbalSchema(), $table, $name, $this->getPrefixInfo($table));
    return in_array($index_full_name, array_keys($this->dbalSchemaManager->listTableIndexes($table_full_name)));
    // @todo it would be preferred to do
    // return $this->dbalSchema()->getTable($this->tableName($table))->hasIndex($index_full_name);
    // but this fails on Drupal\KernelTests\Core\Entity\EntityDefinitionUpdateTest::testBaseFieldCreateUpdateDeleteWithoutData
  }

  /**
   * {@inheritdoc}
   */
  public function addPrimaryKey($table, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add primary key to table @table: table doesn't exist.", ['@table' => $table]));
    }
    $table_full_name = $this->tableName($table);
    if ($this->dbalSchema()->getTable($table_full_name)->hasPrimaryKey()) {
      throw new SchemaObjectExistsException(t("Cannot add primary key to table @table: primary key already exists.", ['@table' => $table]));
    }

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateAddPrimaryKey($this->dbalSchema(), $table_full_name, $table, $fields)) {
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($table_full_name)->setPrimaryKey($this->dbalGetFieldList($fields));
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function dropPrimaryKey($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    $table_full_name = $this->tableName($table);
    if (!$this->dbalSchema()->getTable($table_full_name)->hasPrimaryKey()) {
      return FALSE;
    }
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($table_full_name)->dropPrimaryKey();
    $this->dbalExecuteSchemaChange($to_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function findPrimaryKeyColumns($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    try {
      return $this->dbalSchema()->getTable($this->tableName($table))->getPrimaryKeyColumns();
    }
    catch (DBALException $e) {
      return [];
    }
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

    $table_full_name = $this->tableName($table);
    $index_full_name = $this->dbalExtension->getDbIndexName('addUniqueKey', $this->dbalSchema(), $table, $name, $this->getPrefixInfo($table));

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateAddUniqueKey($this->dbalSchema(), $table_full_name, $index_full_name, $table, $name, $fields)) {
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($table_full_name)->addUniqueIndex($this->dbalGetFieldList($fields), $index_full_name);
    $this->dbalExecuteSchemaChange($to_schema);
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

    $table_full_name = $this->tableName($table);
    $index_full_name = $this->dbalExtension->getDbIndexName('addIndex', $this->dbalSchema(), $table, $name, $this->getPrefixInfo($table));

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateAddIndex($this->dbalSchema(), $table_full_name, $index_full_name, $table, $name, $fields, $spec)) {
      return;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($table_full_name)->addIndex($this->dbalGetFieldList($fields), $index_full_name);
    $this->dbalExecuteSchemaChange($to_schema);
  }

  /**
   * {@inheritdoc}
   */
  public function dropIndex($table, $name) {
    if (!$this->indexExists($table, $name)) {
      return FALSE;
    }

    $table_full_name = $this->tableName($table);
    $index_full_name = $this->dbalExtension->getDbIndexName('dropIndex', $this->dbalSchema(), $table, $name, $this->getPrefixInfo($table));

    // Delegate to DBAL extension.
    if ($this->dbalExtension->delegateDropIndex($this->dbalSchema(), $table_full_name, $index_full_name, $table, $name)) {
      return TRUE;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    $current_schema = $this->dbalSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($table_full_name)->dropIndex($index_full_name);
    $this->dbalExecuteSchemaChange($to_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function changeField($table, $field, $field_new, $spec, $keys_new = []) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot change the definition of field @table.@name: field doesn't exist.", [
        '@table' => $table,
        '@name' => $field,
      ]));
    }
    if (($field != $field_new) && $this->fieldExists($table, $field_new)) {
      throw new SchemaObjectExistsException(t("Cannot rename field @table.@name to @name_new: target field already exists.", [
        '@table' => $table,
        '@name' => $field,
        '@name_new' => $field_new,
      ]));
    }
    if (isset($keys_new['primary key']) && in_array($field_new, $keys_new['primary key'], TRUE)) {
      $this->ensureNotNullPrimaryKey($keys_new['primary key'], [$field_new => $spec]);
    }

    $dbal_type = $this->getDbalColumnType($spec);
    $dbal_column_options = $this->getDbalColumnOptions('changeField', $field_new, $dbal_type, $spec);
    // DBAL is limited here, if we pass only 'columnDefinition' to
    // ::changeColumn the schema diff will not capture any change. We need to
    // fallback to platform specific syntax.
    // @see https://github.com/doctrine/dbal/issues/1033
    $primary_key_processed_by_extension = FALSE;
    if (!$this->dbalExtension->delegateChangeField($primary_key_processed_by_extension, $this->dbalSchema(), $table, $field, $field_new, $spec, $keys_new, $dbal_column_options)) {
      return;
    }
    // We need to reload the schema at next get.
    $this->dbalSchemaForceReload();  // @todo can we just replace the column object in the dbal schema??

    // New primary key.
    if (!empty($keys_new['primary key']) && !$primary_key_processed_by_extension) {
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
   *   The name of the table.
   * @param string $column
   *   (Optional) The name of the column.
   *
   * @return string|null
   *   The comment string or NULL if the comment is not supported.
   *
   * @todo remove once https://www.drupal.org/node/2879677 (Decouple getting
   *   table vs column comments in Schema) is in.
   */
  public function getComment($table, $column = NULL) {
    if ($column === NULL) {
      try {
        return $this->getTableComment($table);
      }
      catch (\RuntimeException $e) {
        return NULL;
      }
    }
    else {
      try {
        return $this->getColumnComment($table, $column);
      }
      catch (\RuntimeException$e) {
        return NULL;
      }
    }
  }

  /**
   * Retrieves a table comment.
   *
   * By default this is not supported. Drivers implementations should override
   * this method if returning comments is supported.
   *
   * @param string $table
   *   The name of the table.
   *
   * @return string|null
   *   The comment string.
   *
   * @throws \RuntimeExceptions
   *   When table comments are not supported.
   *
   * @todo remove docblock once https://www.drupal.org/node/2879677
   *   (Decouple getting table vs column comments in Schema) is in.
   */
  public function getTableComment($table) {
    return $this->dbalExtension->delegateGetTableComment($this->dbalSchema(), $table);
  }

  /**
   * Retrieves a column comment.
   *
   * By default this is not supported. Drivers implementations should override
   * this method if returning comments is supported.
   *
   * @param string $table
   *   The name of the table.
   * @param string $column
   *   The name of the column.
   *
   * @return string|null
   *   The comment string.
   *
   * @throws \RuntimeExceptions
   *   When table comments are not supported.
   *
   * @todo remove docblock once https://www.drupal.org/node/2879677
   *   (Decouple getting table vs column comments in Schema) is in.
   */
  public function getColumnComment($table, $column) {
    return $this->dbalExtension->delegateGetColumnComment($this->dbalSchema(), $table, $column);
  }

  /**
   * {@inheritdoc}
   */
  public function tableExists($table) {
    $result = NULL;
    if ($this->dbalExtension->delegateTableExists($result, $table)) {
      return $result;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    return $this->dbalSchemaManager->tablesExist([$this->tableName($table)]);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldExists($table, $column) {
    $result = NULL;
    if ($this->dbalExtension->delegateFieldExists($result, $table, $column)) {
      return $result;
    }

    // DBAL extension did not pick up, proceed with DBAL.
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    return in_array($this->dbalExtension->getDbFieldName($column), array_keys($this->dbalSchemaManager->listTableColumns($this->tableName($table))));
  }

  /**
   * Builds and returns the DBAL schema of the database.
   *
   * @return \Doctrine\DBAL\Schema\Schema
   *   The DBAL schema of the database.
   */
  protected function dbalSchema() {
    if ($this->dbalCurrentSchema === NULL) {
      $this->dbalSetCurrentSchema($this->dbalSchemaManager->createSchema());
    }
    return $this->dbalCurrentSchema;
  }

  /**
   * Sets the DBAL schema of the database.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema of the database.
   *
   * @return $this
   */
  protected function dbalSetCurrentSchema(DbalSchema $dbal_schema = NULL) {
    $this->dbalCurrentSchema = $dbal_schema;
    return $this;
  }

  /**
   * Forces a reload of the DBAL schema.
   *
   * @return $this
   */
  public function dbalSchemaForceReload() {
    return $this->dbalSetCurrentSchema(NULL);
  }

  /**
   * Executes the DDL statements required to change the schema.
   *
   * @param \Doctrine\DBAL\Schema\Schema $to_schema
   *   The destination DBAL schema.
   *
   * @return bool
   *   TRUE if no exceptions were raised.
   */
  protected function dbalExecuteSchemaChange(DbalSchema $to_schema) {
    foreach ($this->dbalSchema()->getMigrateToSql($to_schema, $this->dbalPlatform) as $sql) {
      if ($this->dbalExtension->getDebugging()) {
        error_log($sql);
      }
      $this->connection->getDbalConnection()->exec($sql);
    }
    $this->dbalSetCurrentSchema($to_schema);
    return TRUE;
  }

  /**
   * Gets the list of columns from Drupal field specs.
   *
   * Normalizes fields with length to field name only.
   *
   * @param array[] $fields
   *   An array of field description arrays, as specified in the schema
   *   documentation.
   *
   * @return string[]
   *   The list of columns.
   */
  public function dbalGetFieldList(array $fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $return[] = $this->dbalExtension->getDbFieldName($field[0]);
      }
      else {
        $return[] = $this->dbalExtension->getDbFieldName($field);
      }
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function findTables($table_expression) {
    $tables = [];
    foreach ($this->dbalExtension->delegateListTableNames() as $table_name) {
      $unprefixed_table_name = $this->dbalExtension->getDrupalTableName($this->connection->tablePrefix(), $table_name);
      if ($unprefixed_table_name !== FALSE && $unprefixed_table_name !== '') {
        $tables[$unprefixed_table_name] = $unprefixed_table_name;
      }
    }

    // Convert the table expression from its SQL LIKE syntax to a regular
    // expression and escape the delimiter that will be used for matching.
    $table_expression = str_replace(['%', '_'], ['.*?', '.'], preg_quote($table_expression, '/'));
    $tables = preg_grep('/^' . $table_expression . '$/i', $tables);

    return $tables;
  }

  /**
   * Get information about the table name and schema from the prefix.
   *
   * @todo double check we cannot avoid this
   *
   * @param string $table
   *   Name of table to look prefix up for. Defaults to 'default' because that's
   *   default key for prefix.
   * @param bool $add_prefix
   *   Boolean that indicates whether the given table name should be prefixed.
   *
   * @return array
   *   A keyed array with information about the schema, table name and prefix.
   */
  public function getPrefixInfoPublic($table = 'default', $add_prefix = TRUE) {
    return $this->getPrefixInfo($table, $add_prefix);
  }

}
