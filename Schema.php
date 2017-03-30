<?php

namespace Drupal\Driver\Database\drubal;

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
 * DRUBAL implementation of \Drupal\Core\Database\Schema.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver specific code
 * in Drupal\Driver\Database\drubal\DBALDriver\[driver_name] classes and
 * execution handed over to there.
 */
class Schema extends DatabaseSchema {

  /**
   * Current connection DBAL schema.
   *
   * @todo
   */
  protected $dbalSchemaManager;

  /**
   * Current connection DBAL platform.
   *
   * @todo
   */
  protected $dbalPlatform;

  /**
   * @todo
   */
  protected $drubalDriver;

  public function __construct($connection) {
    parent::__construct($connection);
    $this->drubalDriver = $this->connection->getDrubalDriver();
    $this->dbalSchemaManager = $this->connection->getDbalConnection()->getSchemaManager();
    $this->dbalPlatform = $this->connection->getDbalConnection()->getDatabasePlatform();
  }

  /**
   * @todo
   */
  protected function getPrefixedTableName($table) {
    return $this->drubalDriver->getPrefixInfo($table)['table'];
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
    $new_table = $schema->createTable($this->getPrefixedTableName($name));

    // Delegate adding options to driver.
    $this->drubalDriver->delegateCreateTableSetOptions($new_table, $schema, $table, $name);

    // Add table comment.
    if (!empty($table['description'])) {
      $comment = $this->connection->prefixTables($table['description']);
      $comment = $this->drubalDriver->alterSetTableComment($comment, $name, $schema, $table);
      $new_table->addOption('comment', $this->prepareComment($comment));
    }

    // Add columns.
    foreach ($table['fields'] as $field_name => $field) {
      $dbal_type = $this->getDbalColumnType($field);
      $new_column = $new_table->addColumn($field_name, $dbal_type, ['columnDefinition' => $this->getDbalColumnDefinition($field_name, $dbal_type, $field)]);
    }

    // Add primary key.
    if (!empty($table['primary key'])) {
      $new_table->setPrimaryKey($table['primary key']); // @todo if length limited?
    }

    // Execute the table creation.
    $sql_statements = $schema->toSql($this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return

    // Add unique keys.
    if (!empty($table['unique keys'])) {
      foreach ($table['unique keys'] as $key => $fields) {
        $this->addUniqueKey($name, $key, $fields); // @todo if length limited?
      }
    }

    // Add indexes.
    if (!empty($table['indexes'])) {
      $indexes = $this->drubalDriver->getNormalizedIndexes($table);
      foreach ($indexes as $index => $fields) {
        $this->addIndex($name, $index, $fields, $table); // @todo if length limited?
      }
    }
  }

  /**
   * Gets DBAL column type, given Drupal's field specs.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function getDbalColumnType($field) {
    $dbal_type = NULL;

    // Delegate to driver.
    if ($this->drubalDriver->delegateGetDbalColumnType($dbal_type, $field)) {
      return $dbal_type;
    }

    // Driver did not pick up, proceed with DBAL.
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
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function getDbalColumnDefinition($field_name, $dbal_type, $field) {
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
        $options['default'] = this->drubalDriver->encodeDefaultValue($field['default']);
      }
    }

    if (isset($field['type']) && $field['type'] == 'serial') {
      $options['autoincrement'] = TRUE;
    }

    if (!empty($field['description'])) {
      $comment = $this->connection->prefixTables($field['description']);
      $comment = $this->drubalDriver->alterSetColumnComment($comment, $dbal_type, $field, $field_name);
      $options['comment'] = $this->prepareComment($comment);
    }

    // Let driver alter the column options if required.
    $this->drubalDriver->alterDbalColumnOptions($options, $dbal_type, $field, $field_name);

    // Get the column definition from DBAL, and trim the field name.
    $dbal_column_definition = substr($this->dbalPlatform->getColumnDeclarationSQL($field_name, $options), strlen($field_name) + 1);

    // Let driver alter the column definition if required.
    $this->drubalDriver->alterDbalColumnDefinition($dbal_column_definition, $options, $dbal_type, $field, $field_name);

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

  protected function createKeySql($fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $return[] = '`' . $field[0] . '`(' . $field[1] . ')';
      }
      else {
        $return[] = '`' . $field . '`';
      }
    }
    return implode(', ', $return);
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

    $info = $this->drubalDriver->getPrefixInfo($new_name);
    return $this->connection->query('ALTER TABLE {' . $table . '} RENAME TO `' . $info['table'] . '`');
  }

  /**
   * {@inheritdoc}
   */
  public function dropTable($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    $this->dbalSchemaManager->dropTable($this->getPrefixedTableName($table));
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
    $dbal_type = $this->getDbalColumnType($spec);
    $to_schema = clone $current_schema;
    $dbal_table = $to_schema->getTable($this->getPrefixedTableName($table));
    $dbal_table->addColumn($field, $dbal_type, ['columnDefinition' => $this->getDbalColumnDefinition($field, $dbal_type, $spec)]);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return

    // Manage change to primary key.
    if (!empty($keys_new['primary key'])) {
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
      $indexes = $this->drubalDriver->getNormalizedIndexes($keys_new['indexes']);
      foreach ($indexes as $index => $fields) {
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
    $to_schema->getTable($this->getPrefixedTableName($table))->dropColumn($field);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetDefault($table, $field, $default) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot set default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->getPrefixedTableName($table))->getColumn($field)->setDefault($this->escapeDefaultValue($default)); // @todo use dbalEncodeQuotes instead??
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSetNoDefault($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot remove default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    $this->connection->query('ALTER TABLE {' . $table . '} ALTER COLUMN `' . $field . '` DROP DEFAULT');
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists($table, $name) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    try {
      // @todo PRIMARY is mysql ONLY
      if ($name == 'PRIMARY') {
        $current_schema = $this->dbalSchemaManager->createSchema();
        return $current_schema->getTable($this->getPrefixedTableName($table))->hasPrimaryKey();
      }
      else {
        $indexes = array_keys($this->dbalSchemaManager->listTableIndexes($this->getPrefixedTableName($table)));
        return in_array($name, $indexes);
      }
    }
    catch (\Exception $e) {
      debug($e->message);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addPrimaryKey($table, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add primary key to table @table: table doesn't exist.", ['@table' => $table]));
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    if ($current_schema->getTable($this->getPrefixedTableName($table))->hasPrimaryKey()) {
      throw new SchemaObjectExistsException(t("Cannot add primary key to table @table: primary key already exists.", ['@table' => $table]));
    }

    // @todo DBAL does not support creating indexes with column lenghts: https://github.com/doctrine/dbal/pull/2412
    if (($idx_cols = $this->dbalResolveIndexColumnNames($fields)) !== FALSE) {
      $to_schema = clone $current_schema;
      $to_schema->getTable($this->getPrefixedTableName($table))->setPrimaryKey($idx_cols);
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
      $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return
    }
    else {
//debug('*** LEGACY *** ' . 'ALTER TABLE {' . $table . '} ADD PRIMARY KEY (' . $this->createKeySql($fields) . ')');
      $this->connection->query('ALTER TABLE {' . $table . '} ADD PRIMARY KEY (' . $this->createKeySql($fields) . ')');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function dropPrimaryKey($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    if (!$current_schema->getTable($this->getPrefixedTableName($table))->hasPrimaryKey()) {
      return FALSE;
    }

    $to_schema = clone $current_schema;
    $to_schema->getTable($this->getPrefixedTableName($table))->dropPrimaryKey();
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return

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

    // @todo DBAL does not support creating indexes with column lenghts: https://github.com/doctrine/dbal/pull/2412
    if (($idx_cols = $this->dbalResolveIndexColumnNames($fields)) !== FALSE) {
      $current_schema = $this->dbalSchemaManager->createSchema();
      $to_schema = clone $current_schema;
      $to_schema->getTable($this->getPrefixedTableName($table))->addUniqueIndex($idx_cols, $name);
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
      $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return
    }
    else {
//debug('*** LEGACY *** ' . 'ALTER TABLE {' . $table . '} ADD UNIQUE KEY `' . $name . '` (' . $this->createKeySql($fields) . ')');
      $this->connection->query('ALTER TABLE {' . $table . '} ADD UNIQUE KEY `' . $name . '` (' . $this->createKeySql($fields) . ')');
    }
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

    $spec['indexes'][$name] = $fields;
    $indexes = $this->drubalDriver->getNormalizedIndexes($spec);

    // @todo DBAL does not support creating indexes with column lenghts: https://github.com/doctrine/dbal/pull/2412
    if (($idx_cols = $this->dbalResolveIndexColumnNames($indexes[$name])) !== FALSE) {
      $current_schema = $this->dbalSchemaManager->createSchema();
      $to_schema = clone $current_schema;
      $to_schema->getTable($this->getPrefixedTableName($table))->addIndex($idx_cols, $name);
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->dbalPlatform);
      $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return
    }
    else {
//debug('*** LEGACY *** ' . 'ALTER TABLE {' . $table . '} ADD INDEX `' . $name . '` (' . $this->createKeySql($indexes[$name]) . ')');
      $this->connection->query('ALTER TABLE {' . $table . '} ADD INDEX `' . $name . '` (' . $this->createKeySql($indexes[$name]) . ')');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function dropIndex($table, $name) {
    if (!$this->indexExists($table, $name)) {
      return FALSE;
    }
    $this->dbalSchemaManager->dropIndex($name, $this->getPrefixedTableName($table));
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
    // @todo DBAL is limited here, if we pass only 'columnDefinition' to
    // ::changeColumn the schema diff will not capture any change. We need to
    // fallback to platform specific syntax.
    // @see https://github.com/doctrine/dbal/issues/1033
    $sql = 'ALTER TABLE {' . $table . '} CHANGE `' . $field . '` `' . $field_new . '` ' . $dbal_column_definition;
    if (!empty($keys_new['primary key'])) {
      $keys_sql = $this->createKeysSql(['primary key' => $keys_new['primary key']]);
      $sql .= ', ADD ' . $keys_sql[0];
    }
    $this->connection->query($sql); // @todo manage exceptions

    // New primary key.
    if (!empty($keys_new['primary key'])) {
      // Drop the existing one before altering the table. @todo might have been added in platform specific command
//      $this->dropPrimaryKey($table);
//      $this->addPrimaryKey($table, $keys_new['primary key']);
    }

    // Add unique keys.
    if (!empty($keys_new['unique keys'])) {
      foreach ($keys_new['unique keys'] as $key => $fields) {
        $this->addUniqueKey($table, $key, $fields);
      }
    }

    // Add indexes.
    if (!empty($keys_new['indexes'])) {
      $indexes = $this->drubalDriver->getNormalizedIndexes($keys_new['indexes']);
      foreach ($indexes as $index => $fields) {
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
    $comment = strtr($comment, [';' => '.']);  // @todo abstract from mysql??
    return $comment;
  }

  /**
   * Retrieve a table or column comment.
   *
   * @todo
   */
  public function getComment($table, $column = NULL) {
    $dbal_schema = $this->dbalSchemaManager->createSchema();
    $comment = NULL;

    // Delegate to driver.
    if ($this->drubalDriver->delegateGetComment($comment, $dbal_schema, $table, $column)) {
      return $comment;
    }

    // Driver did not pick up, proceed with DBAL.
    if (isset($column)) {
      $raw_comment = $dbal_schema->getTable($this->getPrefixedTableName($table))->getColumn($column)->getComment(); // @todo manage exception
      // Let driver cleanup the comment if necessary.
      return $this->drubalDriver->alterGetComment($raw_comment, $dbal_schema, $table, $column);
    }
    // DBAL cannot retrieve table comments from introspected schema. Driver
    // should have processed already.
    // @see https://github.com/doctrine/dbal/issues/1335
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function tableExists($table) {
    return $this->dbalSchemaManager->tablesExist([$this->getPrefixedTableName($table)]);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldExists($table, $column) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    return in_array($column, array_keys($this->dbalSchemaManager->listTableColumns($this->getPrefixedTableName($table))));
  }

  /**
   * @todo temp while some method alter the current dbalSchema and others not.
   */
  protected function dbalExecuteSchemaChange($sql_statements, $do = TRUE, $debug = FALSE) {
    try {
      foreach ($sql_statements as $sql) {
        if ($debug) debug($sql);
        if ($do) $this->connection->getDbalConnection()->exec($sql);
      }
    }
    catch (DBALException $e) {  // @todo more granular exception??
debug($e->getMessage());
      return FALSE;
    }
  }

  protected function dbalResolveIndexColumnNames($fields) {
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

  protected function createKeysSql($spec) {
    $keys = [];

    if (!empty($spec['primary key'])) {
      $keys[] = 'PRIMARY KEY (' . $this->createKeySql($spec['primary key']) . ')';
    }
    if (!empty($spec['unique keys'])) {
      foreach ($spec['unique keys'] as $key => $fields) {
        $keys[] = 'UNIQUE KEY `' . $key . '` (' . $this->createKeySql($fields) . ')';
      }
    }
    if (!empty($spec['indexes'])) {
      $indexes = $this->drubalDriver->getNormalizedIndexes($spec);
      foreach ($indexes as $index => $fields) {
        $keys[] = 'INDEX `' . $index . '` (' . $this->createKeySql($fields) . ')';
      }
    }

    return $keys;
  }

}
