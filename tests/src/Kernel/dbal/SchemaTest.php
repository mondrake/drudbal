<?php

namespace Drupal\Tests\drudbal\Kernel\dbal;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;
use Drupal\drudbal\Driver\Database\dbal\Schema as DruDbalSchema;
use Drupal\KernelTests\Core\Database\DriverSpecificSchemaTestBase;

/**
 * Tests table creation and modification via the schema API.
 *
 * @group Database
 */
class SchemaTest extends DriverSpecificSchemaTestBase {

  /**
   * Returns the DruDbal connection.
   */
  private function connection(): DruDbalConnection {
    $connection = $this->connection;
    assert($connection instanceof DruDbalConnection);
    return $connection;
  }

  /**
   * Returns the DruDbal schema.
   */
  private function schema(): DruDbalSchema {
    $schema = $this->schema;
    assert($schema instanceof DruDbalSchema);
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function checkSchemaComment(string $description, string $table, string $column = NULL): void {
    $comment = $this->schema()->getComment($table, $column);
    // The schema comment truncation for mysql is different.
    if ($this->connection()->databaseType() === 'mysql') {
      $max_length = $column ? 255 : 60;
      $description = Unicode::truncate($description, $max_length, TRUE, TRUE);
    }
    $this->assertSame($description, $comment, 'The comment matches the schema description.');
  }

  /**
   * {@inheritdoc}
   */
  protected function assertCollation(): void {
    if ($this->connection()->databaseType() === 'mysql') {
      // Make sure that varchar fields have the correct collations.
      $columns = $this->connection()->query('SHOW FULL COLUMNS FROM {test_table}');
      $string_check = null;
      $string_ascii_check = null;
      foreach ($columns as $column) {
        if ($column->Field == 'test_field_string') {
          $string_check = $column->Collation;
        }
        if ($column->Field == 'test_field_string_ascii') {
          $string_ascii_check = $column->Collation;
        }
      }
      $this->assertMatchesRegularExpression('#^(utf8mb4_general_ci|utf8mb4_0900_ai_ci)$#', $string_check, 'test_field_string should have a utf8mb4_general_ci or a utf8mb4_0900_ai_ci collation, but it has not.');
      $this->assertSame('ascii_general_ci', $string_ascii_check, 'test_field_string_ascii should have a ascii_general_ci collation, but it has not.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tryInsertExpectsIntegrityConstraintViolationException(string $tableName): void {
    if ($this->connection()->databaseType() !== 'sqlite') {
      parent::tryInsertExpectsIntegrityConstraintViolationException($tableName);
    }
  }

  /**
   * Tests that indexes on string fields are limited to 191 characters on MySQL.
   *
   * @see \Drupal\mysql\Driver\Database\mysql\Schema::getNormalizedIndexes()
   */
  public function testIndexLength(): void {
    if ($this->connection()->databaseType() !== 'mysql') {
      $this->markTestSkipped('Only for MySql');
    }

    $table_specification = [
      'fields' => [
        'id'  => [
          'type' => 'int',
          'default' => NULL,
        ],
        'test_field_text'  => [
          'type' => 'text',
          'not null' => TRUE,
        ],
        'test_field_string_long'  => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'test_field_string_ascii_long'  => [
          'type' => 'varchar_ascii',
          'length' => 255,
        ],
        'test_field_string_short'  => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
      ],
      'indexes' => [
        'test_regular' => [
          'test_field_text',
          'test_field_string_long',
          'test_field_string_ascii_long',
          'test_field_string_short',
        ],
        'test_length' => [
          ['test_field_text', 128],
          ['test_field_string_long', 128],
          ['test_field_string_ascii_long', 128],
          ['test_field_string_short', 128],
        ],
        'test_mixed' => [
          ['test_field_text', 200],
          'test_field_string_long',
          ['test_field_string_ascii_long', 200],
          'test_field_string_short',
        ],
      ],
    ];
    $this->schema()->createTable('test_table_index_length', $table_specification);

    // Ensure expected exception thrown when adding index with missing info.
    $expected_exception_message = "MySQL needs the 'test_field_text' field specification in order to normalize the 'test_regular' index";
    $missing_field_spec = $table_specification;
    unset($missing_field_spec['fields']['test_field_text']);
    try {
      $this->schema()->addIndex('test_table_index_length', 'test_separate', [['test_field_text', 200]], $missing_field_spec);
      $this->fail('SchemaException not thrown when adding index with missing information.');
    }
    catch (SchemaException $e) {
      $this->assertEquals($expected_exception_message, $e->getMessage());
    }

    // Add a separate index.
    $this->schema()->addIndex('test_table_index_length', 'test_separate', [['test_field_text', 200]], $table_specification);
    $table_specification_with_new_index = $table_specification;
    $table_specification_with_new_index['indexes']['test_separate'] = [['test_field_text', 200]];

    // Ensure that the exceptions of addIndex are thrown as expected.
    try {
      $this->schema()->addIndex('test_table_index_length', 'test_separate', [['test_field_text', 200]], $table_specification);
      $this->fail('\Drupal\Core\Database\SchemaObjectExistsException exception missed.');
    }
    catch (SchemaObjectExistsException $e) {
      // Expected exception; just continue testing.
    }

    try {
      $this->schema()->addIndex('test_table_non_existing', 'test_separate', [['test_field_text', 200]], $table_specification);
      $this->fail('\Drupal\Core\Database\SchemaObjectDoesNotExistException exception missed.');
    }
    catch (SchemaObjectDoesNotExistException $e) {
      // Expected exception; just continue testing.
    }

    // Get index information.
    $results = $this->connection()->query('SHOW INDEX FROM {test_table_index_length}');
    $expected_lengths = [
      'test_regular' => [
        'test_field_text' => 191,
        'test_field_string_long' => 191,
        'test_field_string_ascii_long' => NULL,
        'test_field_string_short' => NULL,
      ],
      'test_length' => [
        'test_field_text' => 128,
        'test_field_string_long' => 128,
        'test_field_string_ascii_long' => 128,
        'test_field_string_short' => NULL,
      ],
      'test_mixed' => [
        'test_field_text' => 191,
        'test_field_string_long' => 191,
        'test_field_string_ascii_long' => 200,
        'test_field_string_short' => NULL,
      ],
      'test_separate' => [
        'test_field_text' => 191,
      ],
    ];

    // Count the number of columns defined in the indexes.
    $column_count = 0;
    foreach ($table_specification_with_new_index['indexes'] as $index) {
      foreach ($index as $field) {
        $column_count++;
      }
    }
    $test_count = 0;
    foreach ($results as $result) {
      $this->assertEquals($expected_lengths[$result->Key_name][$result->Column_name], $result->Sub_part, 'Index length matches expected value.');
      $test_count++;
    }
    $this->assertEquals($column_count, $test_count, 'Number of tests matches expected value.');
  }

  /**
   * {@inheritdoc}
   */
  public function testTableWithSpecificDataType(): void {
    $table_specification = [
      'description' => 'Schema table description.',
      'fields' => [
        'timestamp'  => [
          'mysql_type' => 'timestamp',
          'pgsql_type' => 'timestamp',
          'sqlite_type' => 'datetime',
          'oracle_type' => 'date',
          'not null' => FALSE,
          'default' => NULL,
        ],
      ],
    ];
    $this->schema->createTable('test_timestamp', $table_specification);
    $this->assertTrue($this->schema->tableExists('test_timestamp'));
  }

  /**
   * @covers \Drupal\drudbal\Driver\Database\dbal\Schema::introspectIndexSchema
   *
   * In this override, we need to change Oracle index names, since they cannot
   * exceed the 30 chars limit in Oracle 11.
   */
  public function testIntrospectIndexSchema(): void {
    $table_specification = [
      'fields' => [
        'id'  => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'test_field_1'  => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'test_field_2'  => [
          'type' => 'int',
          'default' => 0,
        ],
        'test_field_3'  => [
          'type' => 'int',
          'default' => 0,
        ],
        'test_field_4'  => [
          'type' => 'int',
          'default' => 0,
        ],
        'test_field_5'  => [
          'type' => 'int',
          'default' => 0,
        ],
      ],
      'primary key' => ['id', 'test_field_1'],
      'unique keys' => [
        'test_field_2' => ['test_field_2'],
        'test_field_3_test_field_4' => ['test_field_3', 'test_field_4'],
      ],
      'indexes' => [
        'test_field_4' => ['test_field_4'],
        'test_field_4_test_field_5' => ['test_field_4', 'test_field_5'],
      ],
    ];

    $table_name = strtolower($this->getRandomGenerator()->name());
    $this->schema()->createTable($table_name, $table_specification);

    unset($table_specification['fields']);

    $introspect_index_schema = new \ReflectionMethod(get_class($this->schema), 'introspectIndexSchema');
    $index_schema = $introspect_index_schema->invoke($this->schema, $table_name);

    // Oracle and SQLite are using a custom naming scheme for their indexes, so
    // we need to adjust the initial table specification.
    foreach ($table_specification['unique keys'] as $original_index_name => $columns) {
      unset($table_specification['unique keys'][$original_index_name]);
      $new_index_name = $this->connection()->getDbalExtension()->getDbIndexName('indexExists', $this->connection()->getDbalConnection()->createSchemaManager()->introspectSchema(), $table_name, $original_index_name);
      $table_specification['unique keys'][$new_index_name] = $columns;
    }

    foreach ($table_specification['indexes'] as $original_index_name => $columns) {
      unset($table_specification['indexes'][$original_index_name]);
      $new_index_name = $this->connection()->getDbalExtension()->getDbIndexName('indexExists', $this->connection()->getDbalConnection()->createSchemaManager()->introspectSchema(), $table_name, $original_index_name);
      $table_specification['indexes'][$new_index_name] = $columns;
    }

    $this->assertEquals($table_specification, $index_schema);
  }

  /**
   * Tests the findTables() method.
   */
  public function testFindTables(): void {
    // We will be testing with three tables.
    $test_schema = Database::getConnection()->schema();

    // Create the tables.
    $table_specification = [
      'description' => 'Test table.',
      'fields' => [
        'id'  => [
          'type' => 'int',
          'default' => NULL,
        ],
      ],
    ];
    $test_schema->createTable('test_1_table', $table_specification);
    $test_schema->createTable('test2', $table_specification);
    $test_schema->createTable('table_3_test', $table_specification);

    // Check the "all tables" syntax.
    $tables = $test_schema->findTables('%');
    sort($tables);
    $expected = [
      // The 'config' table is added by
      // \Drupal\KernelTests\KernelTestBase::containerBuild().
      'config',
      'table_3_test',
      'test2',
      'test_1_table',
    ];
    $this->assertEquals($expected, $tables, 'All tables were found.');

    // Check the restrictive syntax.
    $tables = $test_schema->findTables('test%');
    sort($tables);
    $expected = [
      'test2',
      'test_1_table',
    ];
    $this->assertEquals($expected, $tables, 'Two tables were found.');
  }

  /**
   * Tests handling of uppercase table names.
   */
  public function testUpperCaseTableName(): void {
    $table_name = 'UPPER_CASE';

    // Create the tables.
    $table_specification = [
      'description' => 'Test table.',
      'fields' => [
        'id'  => [
          'type' => 'int',
          'default' => NULL,
        ],
      ],
    ];
    $this->schema()->createTable($table_name, $table_specification);

    $this->assertTrue($this->schema()->tableExists($table_name), 'Table with uppercase table name exists');
    $this->assertContains($table_name, $this->schema()->findTables('%'));
    $this->assertTrue($this->schema()->dropTable($table_name), 'Table with uppercase table name dropped');
  }

  /**
   * Tests adding columns to an existing table with default and initial value.
   *
   * In this override, we need to change maximum precision in Oracle, that is
   * 38, differently from other core databases.
   */
  public function testSchemaAddFieldDefaultInitial(): void {
    // Test varchar types.
    foreach ([1, 32, 128, 256, 512] as $length) {
      $base_field_spec = [
        'type' => 'varchar',
        'length' => $length,
      ];
      $variations = [
        ['not null' => FALSE],
        ['not null' => FALSE, 'default' => '7'],
        ['not null' => FALSE, 'default' => substr('"thing"', 0, $length)],
        ['not null' => FALSE, 'default' => substr("\"'hing", 0, $length)],
        ['not null' => TRUE, 'initial' => 'd'],
        ['not null' => FALSE, 'default' => NULL],
        ['not null' => TRUE, 'initial' => 'd', 'default' => '7'],
      ];

      foreach ($variations as $variation) {
        $field_spec = $variation + $base_field_spec;
        $this->assertFieldAdditionRemoval($field_spec);
      }
    }

    // Test int and float types.
    foreach (['int', 'float'] as $type) {
      foreach (['tiny', 'small', 'medium', 'normal', 'big'] as $size) {
        $base_field_spec = [
          'type' => $type,
          'size' => $size,
        ];
        $variations = [
          ['not null' => FALSE],
          ['not null' => FALSE, 'default' => 7],
          ['not null' => TRUE, 'initial' => 1],
          ['not null' => TRUE, 'initial' => 1, 'default' => 7],
          ['not null' => TRUE, 'initial_from_field' => 'serial_column'],
          [
            'not null' => TRUE,
            'initial_from_field' => 'test_nullable_field',
            'initial'  => 100,
          ],
        ];

        foreach ($variations as $variation) {
          $field_spec = $variation + $base_field_spec;
          $this->assertFieldAdditionRemoval($field_spec);
        }
      }
    }

    // Test numeric types.
    switch ($this->connection()->databaseType()) {
      case 'oracle':
        $precisions = [1, 5, 10, 38];
        break;

      default:
        $precisions = [1, 5, 10, 40, 65];
    }
    foreach ($precisions as $precision) {
      foreach ([0, 2, 10, 30] as $scale) {
        // Skip combinations where precision is smaller than scale.
        if ($precision <= $scale) {
          continue;
        }

        $base_field_spec = [
          'type' => 'numeric',
          'scale' => $scale,
          'precision' => $precision,
        ];
        $variations = [
          ['not null' => FALSE],
          ['not null' => FALSE, 'default' => 7],
          ['not null' => TRUE, 'initial' => 1],
          ['not null' => TRUE, 'initial' => 1, 'default' => 7],
          ['not null' => TRUE, 'initial_from_field' => 'serial_column'],
        ];

        foreach ($variations as $variation) {
          $field_spec = $variation + $base_field_spec;
//global $xxx; $xxx=true; dump($field_spec);
          $this->assertFieldAdditionRemoval($field_spec);
        }
      }
    }
  }

  /**
   * Tests creating unsigned columns and data integrity thereof.
   *
   * In this override, we avoid testing insert on the serial column that in
   * Drupal core raises an exception, but not in Oracle where a trigger forces
   * the value to be next-in-sequence regardless of what is passed in.
   */
  public function testUnsignedColumns(): void {
    // First create the table with just a serial column.
    $table_name = 'unsigned_table';
    $table_spec = [
      'fields' => ['serial_column' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE]],
      'primary key' => ['serial_column'],
    ];
    $this->schema()->createTable($table_name, $table_spec);

    // Now set up columns for the other types.
    $types = ['int', 'float', 'numeric'];
    foreach ($types as $type) {
      $column_spec = ['type' => $type, 'unsigned' => TRUE];
      if ($type == 'numeric') {
        $column_spec += ['precision' => 10, 'scale' => 0];
      }
      $column_name = $type . '_column';
      $table_spec['fields'][$column_name] = $column_spec;
      $this->schema()->addField($table_name, $column_name, $column_spec);
    }

    // Finally, check each column and try to insert invalid values into them.
    foreach ($table_spec['fields'] as $column_name => $column_spec) {
      $this->assertTrue($this->schema()->fieldExists($table_name, $column_name), new FormattableMarkup('Unsigned @type column was created.', ['@type' => $column_spec['type']]));
      if ($column_name !== 'serial_column') {
        $this->assertFalse($this->tryUnsignedInsert($table_name, $column_name), new FormattableMarkup('Unsigned @type column rejected a negative value.', ['@type' => $column_spec['type']]));
      }
    }
  }

  /**
   * Tests handling with reserved keywords for naming tables, fields and more.
   */
  public function testReservedKeywordsForNaming(): void {
    $table_specification = [
      'description' => 'A test table with an ANSI reserved keywords for naming.',
      'fields' => [
        'primary' => [
          'description' => 'Simple unique ID.',
          'type' => 'int',
          'not null' => TRUE,
        ],
        'update' => [
          'description' => 'A column with reserved name.',
          'type' => 'varchar',
          'length' => 255,
        ],
        'insert' => [
          'description' => 'Another column with reserved name.',
          'type' => 'varchar',
          'length' => 255,
        ],
      ],
      'primary key' => ['primary'],
      'unique keys' => [
        'having' => ['update'],
      ],
      'indexes' => [
        'in' => ['primary', 'update'],
      ],
    ];

    // Creating a table.
    $table_name = 'select';
    $this->schema->createTable($table_name, $table_specification);
    $this->assertTrue($this->schema->tableExists($table_name));

    // Finding all tables.
    $tables = $this->schema->findTables('%');
    sort($tables);
    $this->assertEquals(['config', 'select'], $tables);

    // Renaming a table.
    $table_name_new = 'from';
    $this->schema->renameTable($table_name, $table_name_new);
    $this->assertFalse($this->schema->tableExists($table_name));
    $this->assertTrue($this->schema->tableExists($table_name_new));

    // Adding a field.
    $field_name = 'delete';
    $this->schema->addField($table_name_new, $field_name, ['type' => 'int', 'not null' => TRUE]);
    $this->assertTrue($this->schema->fieldExists($table_name_new, $field_name));

    // Dropping a primary key.
    $this->schema->dropPrimaryKey($table_name_new);

    // Adding a primary key.
    $this->schema->addPrimaryKey($table_name_new, [$field_name]);

    // Check the primary key columns.
    $find_primary_key_columns = new \ReflectionMethod(get_class($this->schema), 'findPrimaryKeyColumns');
    $this->assertEquals([$field_name], $find_primary_key_columns->invoke($this->schema, $table_name_new));

    // Dropping a primary key.
    $this->schema->dropPrimaryKey($table_name_new);

    // Changing a field.
    $field_name_new = 'where';
    $this->schema->changeField($table_name_new, $field_name, $field_name_new, ['type' => 'int', 'not null' => FALSE]);
    $this->assertFalse($this->schema->fieldExists($table_name_new, $field_name));
    $this->assertTrue($this->schema->fieldExists($table_name_new, $field_name_new));

    // Adding an unique key
    $unique_key_name = $unique_key_introspect_name = 'unique';
    $this->schema->addUniqueKey($table_name_new, $unique_key_name, [$field_name_new]);

    // Check the unique key columns.
    // @todo this differs from core in the sense that the index name must be
    //   recalculated via ::getDbIndexName().
    $introspect_index_schema = new \ReflectionMethod(get_class($this->schema), 'introspectIndexSchema');
    $dbUniqueIndexName = $this->connection()->getDbalExtension()->getDbIndexName('indexExists', $this->schema()->dbalSchema(), $table_name_new, $unique_key_name);
    $this->assertEquals([$field_name_new], $introspect_index_schema->invoke($this->schema, $table_name_new)['unique keys'][$dbUniqueIndexName]);

    // Dropping an unique key
    $this->schema->dropUniqueKey($table_name_new, $unique_key_name);

    // Dropping a field.
    $this->schema->dropField($table_name_new, $field_name_new);
    $this->assertFalse($this->schema->fieldExists($table_name_new, $field_name_new));

    // Adding an index.
    // @todo this differs from core in the sense that we use a  different column
    //   than 'update' - that would lead to duplicated index on Oracle.
    $index_name = $index_introspect_name = 'index';
    $this->schema->addIndex($table_name_new, $index_name, ['insert'], $table_specification);
    $this->assertTrue($this->schema->indexExists($table_name_new, $index_name));

    // Check the index columns.
    // @todo this differs from core in the sense that the index name must be
    //   recalculated via ::getDbIndexName().
    $dbIndexName = $this->connection()->getDbalExtension()->getDbIndexName('indexExists', $this->schema()->dbalSchema(), $table_name_new, $index_name);
    $this->assertEquals(['insert'], $introspect_index_schema->invoke($this->schema, $table_name_new)['indexes'][$dbIndexName]);

    // Dropping an index.
    $this->schema->dropIndex($table_name_new, $index_name);
    $this->assertFalse($this->schema->indexExists($table_name_new, $index_name));

    // Dropping a table.
    $this->schema->dropTable($table_name_new);
    $this->assertFalse($this->schema->tableExists($table_name_new));
  }

}
