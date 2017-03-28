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
 */
class Schema extends DatabaseSchema {

  /**
   * Maximum length of a table comment in MySQL.
   */
  const COMMENT_MAX_TABLE = 60;

  /**
   * Maximum length of a column comment in MySQL.
   */
  const COMMENT_MAX_COLUMN = 255;

  /**
   * @var array
   *   List of MySQL string types.
   */
  protected $mysqlStringTypes = [
    'VARCHAR',
    'CHAR',
    'TINYTEXT',
    'MEDIUMTEXT',
    'LONGTEXT',
    'TEXT',
  ];

  /**
   * Current connection DBAL schema.
   *
   * @todo
   */
  protected $dbalSchemaManager;

  public function __construct($connection) {
    parent::__construct($connection);
    $this->dbalSchemaManager = $this->connection->getDbalConnection()->getSchemaManager();
  }

  /**
   * Get information about the table and database name from the prefix.
   *
   * @return
   *   A keyed array with information about the database, table name and prefix.
   */
  protected function getPrefixInfo($table = 'default', $add_prefix = TRUE) {
    $info = ['prefix' => $this->connection->tablePrefix($table)];
    if ($add_prefix) {
      $table = $info['prefix'] . $table;
    }
    if (($pos = strpos($table, '.')) !== FALSE) {
      $info['database'] = substr($table, 0, $pos);
      $info['table'] = substr($table, ++$pos);
    }
    else {
      $info['database'] = $this->connection->getDbalConnection()->getDatabase();
      $info['table'] = $table;
    }
    return $info;
  }

  /**
   * Create a new table from a Drupal table definition.
   *
   * @param $name
   *   The name of the table to create.
   * @param $table
   *   A Schema API table definition array.
   *
   * @throws \Drupal\Core\Database\SchemaObjectExistsException
   *   If the specified table already exists.
   */
  public function createTable($name, $table) {
    if ($this->tableExists($name)) {
      throw new SchemaObjectExistsException(t('Table @name already exists.', ['@name' => $name]));
    }

    $info = $this->connection->getConnectionOptions();

    // Provide defaults if needed.
    $table += [
      'mysql_engine' => 'InnoDB',
      'mysql_character_set' => 'utf8mb4',
    ];

    // Create table via DBAL.
    $schema = new DbalSchema;
    $new_table = $schema->createTable($this->getPrefixInfo($name)['table']);
    $new_table->addOption('charset', $table['mysql_character_set']); // @todo abstract
    $new_table->addOption('engine', $table['mysql_engine']); // @todo abstract
    $info['collation'] = 'utf8mb4_unicode_ci'; // @todo abstract

    if (!empty($info['collation'])) {
      $new_table->addOption('collate', $info['collation']); // @todo abstract
    }

    // Add table comment.
    if (!empty($table['description'])) {
      $new_table->addOption('comment', $this->prepareComment($table['description'], self::COMMENT_MAX_TABLE)); // @todo abstract
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
    $sql_statements = $schema->toSql($this->connection->getDbalConnection()->getDatabasePlatform());
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return

    // Add unique keys.
    if (!empty($table['unique keys'])) {
      foreach ($table['unique keys'] as $key => $fields) {
        $this->addUniqueKey($name, $key, $fields); // @todo if length limited?
      }
    }

    // Add indexes.
    if (!empty($table['indexes'])) {
      $indexes = $this->getNormalizedIndexes($table);
      foreach ($indexes as $index => $fields) {
        $this->addIndex($name, $index, $fields, $table); // @todo if length limited?
      }
    }
  }

  /**
   * Set database-engine specific properties for a field.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function processField($field) {

    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }

    // Set the correct database-engine specific datatype.
    // In case one is already provided, force it to uppercase.
    if (isset($field['mysql_type'])) {
      $field['mysql_type'] = Unicode::strtoupper($field['mysql_type']);
    }
    else {
      $map = $this->getFieldTypeMap();
      $field['mysql_type'] = $map[$field['type'] . ':' . $field['size']];
    }

    if (isset($field['type']) && $field['type'] == 'serial') {
      $field['auto_increment'] = TRUE;
    }

    return $field;
  }

  /**
   * Gets DBAL column type, given Drupal's field specs.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function getDbalColumnType($field) {
    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }
    $map = $this->getDbalFieldTypeMap();
    // @todo check if exception should be raised if no key in array.
    return $map[$field['type'] . ':' . $field['size']];
  }

  /**
   * Gets DBAL column options, given Drupal's field specs.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function getDbalColumnDefinition($field_name, $dbal_type, $field) {
    $platform = $this->connection->getDbalConnection()->getDatabasePlatform();

    $options = [];
/*    $sql = "`" . $name . "` " . $spec['mysql_type'];

    if (in_array($spec['mysql_type'], $this->mysqlStringTypes)) {
      if (isset($spec['length'])) {
        $sql .= '(' . $spec['length'] . ')';
      }
    }
*/
    $options['type'] = DbalType::getType($dbal_type);

    if (isset($field['type']) && $field['type'] == 'varchar_ascii') {
      $options['charset'] = 'ascii'; // @todo mysql specific
      $options['collation'] = 'ascii_general_ci'; // @todo mysql specific
    }

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

    // $spec['default'] can be NULL, so we explicitly check for the key here.
    if (array_key_exists('default', $field)) {
      $options['default'] = $field['default'];
    }

    if ($field['type'] == 'serial') {
      $options['autoincrement'] = TRUE;
    }

    if (!empty($field['description'])) {
      $options['comment'] = $this->prepareComment($field['description'], self::COMMENT_MAX_COLUMN); // @todo abstract from mysql??
    }

    // Get the column definition from DBAL, and trim the field name.
    $dbal_column_definition = substr($platform->getColumnDeclarationSQL($field_name, $options), strlen($field_name) + 1);

    // @todo mysql specific
    if (isset($field['type']) && $field['type'] == 'varchar_ascii') {
      $dbal_column_definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 CHARACTER SET ascii', $dbal_column_definition);
    }
    if (isset($field['binary']) && $field['binary']) {
      $dbal_column_definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 BINARY', $dbal_column_definition);
    }

    return $dbal_column_definition;
  }

  public function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip. This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    // $map does not use drupal_static as its value never changes.
    static $map = [
      'varchar_ascii:normal' => 'VARCHAR',

      'varchar:normal'  => 'VARCHAR',
      'char:normal'     => 'CHAR',

      'text:tiny'       => 'TINYTEXT',
      'text:small'      => 'TINYTEXT',
      'text:medium'     => 'MEDIUMTEXT',
      'text:big'        => 'LONGTEXT',
      'text:normal'     => 'TEXT',

      'serial:tiny'     => 'TINYINT',
      'serial:small'    => 'SMALLINT',
      'serial:medium'   => 'MEDIUMINT',
      'serial:big'      => 'BIGINT',
      'serial:normal'   => 'INT',

      'int:tiny'        => 'TINYINT',
      'int:small'       => 'SMALLINT',
      'int:medium'      => 'MEDIUMINT',
      'int:big'         => 'BIGINT',
      'int:normal'      => 'INT',

      'float:tiny'      => 'FLOAT',
      'float:small'     => 'FLOAT',
      'float:medium'    => 'FLOAT',
      'float:big'       => 'DOUBLE',
      'float:normal'    => 'FLOAT',

      'numeric:normal'  => 'DECIMAL',

      'blob:big'        => 'LONGBLOB',
      'blob:normal'     => 'BLOB',
    ];
    return $map;
  }

  public function getDbalFieldTypeMap() {
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
   * Gets normalized indexes from a table specification.
   *
   * Shortens indexes to 191 characters if they apply to utf8mb4-encoded
   * fields, in order to comply with the InnoDB index limitation of 756 bytes.
   *
   * @param array $spec
   *   The table specification.
   *
   * @return array
   *   List of shortened indexes.
   *
   * @throws \Drupal\Core\Database\SchemaException
   *   Thrown if field specification is missing.
   */
  protected function getNormalizedIndexes(array $spec) {
    $indexes = isset($spec['indexes']) ? $spec['indexes'] : [];
    foreach ($indexes as $index_name => $index_fields) {
      foreach ($index_fields as $index_key => $index_field) {
        // Get the name of the field from the index specification.
        $field_name = is_array($index_field) ? $index_field[0] : $index_field;
        // Check whether the field is defined in the table specification.
        if (isset($spec['fields'][$field_name])) {
          // Get the MySQL type from the processed field.
          $mysql_field = $this->processField($spec['fields'][$field_name]);
          if (in_array($mysql_field['mysql_type'], $this->mysqlStringTypes)) {
            // Check whether we need to shorten the index.
            if ((!isset($mysql_field['type']) || $mysql_field['type'] != 'varchar_ascii') && (!isset($mysql_field['length']) || $mysql_field['length'] > 191)) {
              // Limit the index length to 191 characters.
              $this->shortenIndex($indexes[$index_name][$index_key]);
            }
          }
        }
        else {
          throw new SchemaException("MySQL needs the '$field_name' field specification in order to normalize the '$index_name' index");
        }
      }
    }
    return $indexes;
  }

  /**
   * Helper function for normalizeIndexes().
   *
   * Shortens an index to 191 characters.
   *
   * @param array $index
   *   The index array to be used in createKeySql.
   *
   * @see Drupal\Core\Database\Driver\mysql\Schema::createKeySql()
   * @see Drupal\Core\Database\Driver\mysql\Schema::normalizeIndexes()
   */
  protected function shortenIndex(&$index) {
    if (is_array($index)) {
      if ($index[1] > 191) {
        $index[1] = 191;
      }
    }
    else {
      $index = [$index, 191];
    }
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

  public function renameTable($table, $new_name) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot rename @table to @table_new: table @table doesn't exist.", ['@table' => $table, '@table_new' => $new_name]));
    }
    if ($this->tableExists($new_name)) {
      throw new SchemaObjectExistsException(t("Cannot rename @table to @table_new: table @table_new already exists.", ['@table' => $table, '@table_new' => $new_name]));
    }

    $info = $this->getPrefixInfo($new_name);
    return $this->connection->query('ALTER TABLE {' . $table . '} RENAME TO `' . $info['table'] . '`');
  }

  public function dropTable($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    try {
      $this->dbalSchemaManager->dropTable($this->getPrefixInfo($table)['table']);
      return TRUE;
    }
    catch (\Exception $e) {
      debug($e->message);
      return FALSE;
    }
  }

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
    $dbal_table = $to_schema->getTable($this->getPrefixInfo($table)['table']);
    $dbal_table->addColumn($field, $dbal_type, ['columnDefinition' => $this->getDbalColumnDefinition($field, $dbal_type, $spec)]);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->connection->getDbalConnection()->getDatabasePlatform());
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
      $indexes = $this->getNormalizedIndexes($keys_new['indexes']);
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

  public function dropField($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      return FALSE;
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    $to_schema = clone $current_schema;
    $to_schema->getTable($this->getPrefixInfo($table)['table'])->dropColumn($field);
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->connection->getDbalConnection()->getDatabasePlatform());
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return

    return TRUE;
  }

  public function fieldSetDefault($table, $field, $default) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot set default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    $this->connection->query('ALTER TABLE {' . $table . '} ALTER COLUMN `' . $field . '` SET DEFAULT ' . $this->escapeDefaultValue($default));
  }

  public function fieldSetNoDefault($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot remove default value of field @table.@field: field doesn't exist.", ['@table' => $table, '@field' => $field]));
    }

    $this->connection->query('ALTER TABLE {' . $table . '} ALTER COLUMN `' . $field . '` DROP DEFAULT');
  }

  public function indexExists($table, $name) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    try {
      // @todo is it right to use array_keys to find the names, or shall the name
      // property of each index object be used?
      $indexes = array_keys($this->dbalSchemaManager->listTableIndexes($this->getPrefixInfo($table)['table']));
      return in_array($name, $indexes);
    }
    catch (\Exception $e) {
      debug($e->message);
      return FALSE;
    }
  }

  public function addPrimaryKey($table, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add primary key to table @table: table doesn't exist.", ['@table' => $table]));
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    if ($current_schema->getTable($this->getPrefixInfo($table)['table'])->hasPrimaryKey()) {
      throw new SchemaObjectExistsException(t("Cannot add primary key to table @table: primary key already exists.", ['@table' => $table]));
    }

    // @todo DBAL does not support creating indexes with column lenghts: https://github.com/doctrine/dbal/pull/2412
    if (($idx_cols = $this->dbalResolveIndexColumnNames($fields)) !== FALSE) {
      $to_schema = clone $current_schema;
      $to_schema->getTable($this->getPrefixInfo($table)['table'])->setPrimaryKey($idx_cols);
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->connection->getDbalConnection()->getDatabasePlatform());
      $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return
    }
    else {
//debug('*** LEGACY *** ' . 'ALTER TABLE {' . $table . '} ADD PRIMARY KEY (' . $this->createKeySql($fields) . ')');
      $this->connection->query('ALTER TABLE {' . $table . '} ADD PRIMARY KEY (' . $this->createKeySql($fields) . ')');
    }
  }

  public function dropPrimaryKey($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    $current_schema = $this->dbalSchemaManager->createSchema();
    if (!$current_schema->getTable($this->getPrefixInfo($table)['table'])->hasPrimaryKey()) {
      return FALSE;
    }

    $to_schema = clone $current_schema;
    $to_schema->getTable($this->getPrefixInfo($table)['table'])->dropPrimaryKey();
    $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->connection->getDbalConnection()->getDatabasePlatform());
    $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return

    return TRUE;
  }

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
      $to_schema->getTable($this->getPrefixInfo($table)['table'])->addUniqueIndex($idx_cols, $name);
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->connection->getDbalConnection()->getDatabasePlatform());
      $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return
    }
    else {
//debug('*** LEGACY *** ' . 'ALTER TABLE {' . $table . '} ADD UNIQUE KEY `' . $name . '` (' . $this->createKeySql($fields) . ')');
      $this->connection->query('ALTER TABLE {' . $table . '} ADD UNIQUE KEY `' . $name . '` (' . $this->createKeySql($fields) . ')');
    }
  }

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
    $indexes = $this->getNormalizedIndexes($spec);

    // @todo DBAL does not support creating indexes with column lenghts: https://github.com/doctrine/dbal/pull/2412
    if (($idx_cols = $this->dbalResolveIndexColumnNames($indexes[$name])) !== FALSE) {
      $current_schema = $this->dbalSchemaManager->createSchema();
      $to_schema = clone $current_schema;
      $to_schema->getTable($this->getPrefixInfo($table)['table'])->addIndex($idx_cols, $name);
      $sql_statements = $current_schema->getMigrateToSql($to_schema, $this->connection->getDbalConnection()->getDatabasePlatform());
      $this->dbalExecuteSchemaChange($sql_statements); // @todo manage return
    }
    else {
//debug('*** LEGACY *** ' . 'ALTER TABLE {' . $table . '} ADD INDEX `' . $name . '` (' . $this->createKeySql($indexes[$name]) . ')');
      $this->connection->query('ALTER TABLE {' . $table . '} ADD INDEX `' . $name . '` (' . $this->createKeySql($indexes[$name]) . ')');
    }
  }

  public function dropIndex($table, $name) {
    if (!$this->indexExists($table, $name)) {
      return FALSE;
    }

    try {
      $this->dbalSchemaManager->dropIndex($name, $this->getPrefixInfo($table)['table']);
      return TRUE;
    }
    catch (\Exception $e) {
      debug($e->message);
      return FALSE;
    }
  }

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
    $this->connection->query($sql); // @todo manage exceptions

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
      $indexes = $this->getNormalizedIndexes($keys_new['indexes']);
      foreach ($indexes as $index => $fields) {
        $this->addIndex($table, $index, $fields, $keys_new);
      }
    }
  }

  public function prepareComment($comment, $length = NULL) {
    // Truncate comment to maximum comment length.
    if (isset($length)) {
      // Add table prefixes before truncating.
      $comment = Unicode::truncate($this->connection->prefixTables($comment), $length, TRUE, TRUE);
    }
    // Remove semicolons to avoid triggering multi-statement check.
    $comment = strtr($comment, [';' => '.']);  // @todo abstract from mysql??
    return $comment;
  }

  /**
   * Retrieve a table or column comment.
   */
  public function getComment($table, $column = NULL) {
    $table_info = $this->getPrefixInfo($table);
    $dbal_table = $this->dbalSchemaManager->createSchema()->getTable($this->getPrefixInfo($table)['table']);
    if (isset($column)) {
      return $dbal_table->getColumn($column)->getComment(); // @todo manage exception
    }
    // @todo DBAL is limited here, table comments cannot be retrieved from
    // introspected schema. We need to fallback to platform specific syntax.
    // @see https://github.com/doctrine/dbal/issues/1335
    $condition = new Condition('AND'); // @todo use DBAL queryBuilder
    $condition->condition('table_schema', $table_info['database']);
    $condition->condition('table_name', $table_info['table'], '=');
    $comment = $this->connection->query("SELECT table_comment FROM information_schema.tables WHERE " . (string) $condition, $condition->arguments())->fetchField();
    // Work-around for MySQL 5.0 bug http://bugs.mysql.com/bug.php?id=11379
    return preg_replace('/; InnoDB free:.*$/', '', $comment);
  }

  public function tableExists($table) {
    try {
      return $this->dbalSchemaManager->tablesExist([$this->getPrefixInfo($table)['table']]);
    }
    catch (\Exception $e) {
      debug($e->message); // @todo check!
      return FALSE;
    }
  }

  public function fieldExists($table, $column) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    try {
      // @todo is it right to use array_keys to find the names, or shall the name
      // property of each index object be used?
      $columns = array_keys($this->dbalSchemaManager->listTableColumns($this->getPrefixInfo($table)['table']));
      return in_array($column, $columns);
    }
    catch (\Exception $e) {
      debug($e->message); // @todo check!
      return FALSE;
    }
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

}
