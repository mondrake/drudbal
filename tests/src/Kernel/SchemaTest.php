<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Database\Database;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;
use Drupal\KernelTests\Core\Database\SchemaTest as SchemaTestBase;

/**
 * Tests table creation and modification via the schema API.
 *
 * @group Database
 */
class SchemaTest extends SchemaTestBase {

  /**
   * Returns the DruDbal connection.
   */
  private function connection(): DruDbalConnection {
    $connection = $this->connection;
    assert($connection instanceof DruDbalConnection);
    return $connection;
  }

  /**
   * Tests database interactions.
   */
  public function testSchema() {
    // Try creating a table.
    $table_specification = [
      'description' => 'Schema table description may contain "quotes" and could be long—very long indeed.',
      'fields' => [
        'id'  => [
          'type' => 'int',
          'default' => NULL,
        ],
        'test_field'  => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Schema table description may contain "quotes" and could be long—very long indeed. There could be "multiple quoted regions".',
        ],
        'test_field_string'  => [
          'type' => 'varchar',
          'length' => 20,
          'not null' => TRUE,
          'default' => "'\"funky default'\"",
          'description' => 'Schema column description for string.',
        ],
        'test_field_string_ascii'  => [
          'type' => 'varchar_ascii',
          'length' => 255,
          'description' => 'Schema column description for ASCII string.',
        ],
      ],
    ];
    $this->schema->createTable('test_table', $table_specification);

    // Assert that the table exists.
    $this->assertTrue($this->schema->tableExists('test_table'), 'The table exists.');

    // Assert that the table comment has been set.
    $this->checkSchemaComment($table_specification['description'], 'test_table');

    // Assert that the column comment has been set.
    $this->checkSchemaComment($table_specification['fields']['test_field']['description'], 'test_table', 'test_field');

    if ($this->connection()->databaseType() === 'mysql') {
      // Make sure that varchar fields have the correct collation.
      $columns = $this->connection()->query('SHOW FULL COLUMNS FROM {test_table}');
      foreach ($columns as $column) {
        if ($column->Field == 'test_field_string') {
          $string_check = ($column->Collation == 'utf8mb4_general_ci' || $column->Collation == 'utf8mb4_0900_ai_ci');
        }
        if ($column->Field == 'test_field_string_ascii') {
          $string_ascii_check = ($column->Collation == 'ascii_general_ci');
        }
      }
      $this->assertTrue(!empty($string_check), 'string field has the right collation.');
      $this->assertTrue(!empty($string_ascii_check), 'ASCII string field has the right collation.');
    }

    // An insert without a value for the column 'test_table' should fail.
    $this->assertFalse($this->tryInsert(), 'Insert without a default failed.');

    // Add a default value to the column.
    $this->schema->changeField('test_table', 'test_field', 'test_field', ['type' => 'int', 'not null' => TRUE, 'default' => 0]);
    // The insert should now succeed.
    $this->assertTrue($this->tryInsert(), 'Insert with a default succeeded.');

    // Remove the default.
    $this->schema->changeField('test_table', 'test_field', 'test_field', ['type' => 'int', 'not null' => TRUE]);
    // The insert should fail again.
    $this->assertFalse($this->tryInsert(), 'Insert without a default failed.');

    // Test for fake index and test for the boolean result of indexExists().
    $index_exists = $this->schema->indexExists('test_table', 'test_field');
    $this->assertFalse($index_exists, 'Fake index does not exist');
    // Add index.
    $this->schema->addIndex('test_table', 'test_field', ['test_field'], $table_specification);
    // Test for created index and test for the boolean result of indexExists().
    $index_exists = $this->schema->indexExists('test_table', 'test_field');
    $this->assertTrue($index_exists, 'Index created.');

    // Rename the table.
    $this->assertNull($this->schema->renameTable('test_table', 'test_table2'));

    // Index should be renamed.
    $index_exists = $this->schema->indexExists('test_table2', 'test_field');
    $this->assertTrue($index_exists, 'Index was renamed.');

    // We need the default so that we can insert after the rename.
    $this->schema->changeField('test_table2', 'test_field', 'test_field', ['type' => 'int', 'not null' => TRUE, 'default' => 0]);
    $this->assertFalse($this->tryInsert(), 'Insert into the old table failed.');
    $this->assertTrue($this->tryInsert('test_table2'), 'Insert into the new table succeeded.');

    // We should have successfully inserted exactly two rows.
    $count = $this->connection()->query('SELECT COUNT(*) FROM {test_table2}')->fetchField();
    $this->assertEquals(2, $count, 'Two fields were successfully inserted.');

    // Try to drop the table.
    $this->schema->dropTable('test_table2');
    $this->assertFalse($this->schema->tableExists('test_table2'), 'The dropped table does not exist.');

    // Recreate the table.
    $this->schema->createTable('test_table', $table_specification);
    $this->schema->changeField('test_table', 'test_field', 'test_field', ['type' => 'int', 'not null' => TRUE, 'default' => 0]);
    $this->schema->addField('test_table', 'test_serial', ['type' => 'int', 'not null' => TRUE, 'default' => 0, 'description' => 'Added column description.']);

    // Assert that the column comment has been set.
    $this->checkSchemaComment('Added column description.', 'test_table', 'test_serial');

    // Change the new field to a serial column.
    $this->schema->changeField('test_table', 'test_serial', 'test_serial', ['type' => 'serial', 'not null' => TRUE, 'description' => 'Changed column description.'], ['primary key' => ['test_serial']]);

    // Assert that the column comment has been set.
    $this->checkSchemaComment('Changed column description.', 'test_table', 'test_serial');
    $this->assertTrue($this->tryInsert(), 'Insert with a serial succeeded.');
    $max1 = $this->connection()->query('SELECT MAX([test_serial]) FROM {test_table}')->fetchField();
    $this->assertTrue($this->tryInsert(), 'Insert with a serial succeeded.');
    $max2 = $this->connection()->query('SELECT MAX([test_serial]) FROM {test_table}')->fetchField();
    $this->assertTrue($max2 > $max1, 'The serial is monotone.');

    $count = $this->connection()->query('SELECT COUNT(*) FROM {test_table}')->fetchField();
    $this->assertEquals(2, $count, 'There were two rows.');

    // Test adding a serial field to an existing table.
    $this->schema->dropTable('test_table');
    $this->schema->createTable('test_table', $table_specification);
    $this->schema->changeField('test_table', 'test_field', 'test_field', ['type' => 'int', 'not null' => TRUE, 'default' => 0]);
    $this->schema->addField('test_table', 'test_serial', ['type' => 'serial', 'not null' => TRUE], ['primary key' => ['test_serial']]);

    // Test the primary key columns.
    $method = new \ReflectionMethod(get_class($this->schema), 'findPrimaryKeyColumns');
    $method->setAccessible(TRUE);
    $this->assertSame(['test_serial'], $method->invoke($this->schema, 'test_table'));

    $this->assertTrue($this->tryInsert(), 'Insert with a serial succeeded.');
    $max1 = $this->connection()->query('SELECT MAX([test_serial]) FROM {test_table}')->fetchField();
    $this->assertTrue($this->tryInsert(), 'Insert with a serial succeeded.');
    $max2 = $this->connection()->query('SELECT MAX([test_serial]) FROM {test_table}')->fetchField();
    $this->assertTrue($max2 > $max1, 'The serial is monotone.');

    $count = $this->connection()->query('SELECT COUNT(*) FROM {test_table}')->fetchField();
    $this->assertEquals(2, $count, 'There were two rows.');

    // Test adding a new column and form a composite primary key with it.
    $this->schema->addField('test_table', 'test_composite_primary_key', ['type' => 'int', 'not null' => TRUE, 'default' => 0], ['primary key' => ['test_serial', 'test_composite_primary_key']]);

    // Test the primary key columns.
    $this->assertSame(['test_serial', 'test_composite_primary_key'], $method->invoke($this->schema, 'test_table'));

    // Test renaming of keys and constraints.
    $this->schema->dropTable('test_table');
    $table_specification = [
      'fields' => [
        'id'  => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'test_field'  => [
          'type' => 'int',
          'default' => 0,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'test_field' => ['test_field'],
      ],
    ];

    // PostgreSQL has a max identifier length of 63 characters, MySQL has 64 and
    // SQLite does not have any limit. Use the lowest common value and create a
    // table name as long as possible in order to cover edge cases around
    // identifier names for the table's primary or unique key constraints.
    $table_name = strtolower($this->getRandomGenerator()->name(63 - strlen($this->getDatabasePrefix())));
    $this->schema->createTable($table_name, $table_specification);

    $this->assertIndexOnColumns($table_name, ['id'], 'primary');
    $this->assertIndexOnColumns($table_name, ['test_field'], 'unique');

    $new_table_name = strtolower($this->getRandomGenerator()->name(63 - strlen($this->getDatabasePrefix())));
    $this->assertNull($this->schema->renameTable($table_name, $new_table_name));

    // Test for renamed primary and unique keys.
    $this->assertIndexOnColumns($new_table_name, ['id'], 'primary');
    $this->assertIndexOnColumns($new_table_name, ['test_field'], 'unique');

    // For PostgreSQL, we also need to check that the sequence has been renamed.
    // The initial name of the sequence has been generated automatically by
    // PostgreSQL when the table was created, however, on subsequent table
    // renames the name is generated by Drupal and can not be easily
    // re-constructed. Hence we can only check that we still have a sequence on
    // the new table name.
    if ($this->connection()->databaseType() == 'pgsql') {
      $sequence_exists = (bool) $this->connection()->query("SELECT pg_get_serial_sequence('{" . $new_table_name . "}', 'id')")->fetchField();
      $this->assertTrue($sequence_exists, 'Sequence was renamed.');

      // Rename the table again and repeat the check.
      $another_table_name = strtolower($this->getRandomGenerator()->name(63 - strlen($this->getDatabasePrefix())));
      $this->schema->renameTable($new_table_name, $another_table_name);

      $sequence_exists = (bool) $this->connection()->query("SELECT pg_get_serial_sequence('{" . $another_table_name . "}', 'id')")->fetchField();
      $this->assertTrue($sequence_exists, 'Sequence was renamed.');
    }

    // Use database specific data type and ensure that table is created.
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
    try {
      $this->schema->createTable('test_timestamp', $table_specification);
    }
    catch (\Exception $e) {
    }
    $this->assertTrue($this->schema->tableExists('test_timestamp'), 'Table with database specific datatype was created.');
  }

  /**
   * @covers \Drupal\drudbal\Driver\Database\dbal\Schema::introspectIndexSchema
   *
   * In this override, we need to change Oracle index names, since they cannot
   * exceed the 30 chars limit in Oracle 11.
   */
  public function testIntrospectIndexSchema() {
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
    $this->schema->createTable($table_name, $table_specification);

    unset($table_specification['fields']);

    $introspect_index_schema = new \ReflectionMethod(get_class($this->schema), 'introspectIndexSchema');
    $introspect_index_schema->setAccessible(TRUE);
    $index_schema = $introspect_index_schema->invoke($this->schema, $table_name);

    // Oracle is using a custom naming scheme for its indexes, so
    // we need to adjust the initial table specification.
    if ($this->connection()->databaseType() === 'oracle') {
      foreach ($table_specification['unique keys'] as $original_index_name => $columns) {
        unset($table_specification['unique keys'][$original_index_name]);
        $new_index_name = $this->connection()->getDbalExtension()->getDbIndexName('indexExists', $this->connection()->getDbalConnection()->createSchemaManager()->createSchema(), $table_name, $original_index_name);
        $table_specification['unique keys'][$new_index_name] = $columns;
      }

      foreach ($table_specification['indexes'] as $original_index_name => $columns) {
        unset($table_specification['indexes'][$original_index_name]);
        $new_index_name = $this->connection()->getDbalExtension()->getDbIndexName('indexExists', $this->connection()->getDbalConnection()->createSchemaManager()->createSchema(), $table_name, $original_index_name);
        $table_specification['indexes'][$new_index_name] = $columns;
      }
    }

    $this->assertEquals($table_specification, $index_schema);
  }

  /**
   * Tests the findTables() method.
   */
  public function testFindTables() {
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
  public function testUpperCaseTableName() {
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
    $this->schema->createTable($table_name, $table_specification);

    $this->assertTrue($this->schema->tableExists($table_name), 'Table with uppercase table name exists');
    $this->assertContains($table_name, $this->schema->findTables('%'));
    $this->assertTrue($this->schema->dropTable($table_name), 'Table with uppercase table name dropped');
  }

  /**
   * Tests adding columns to an existing table with default and initial value.
   *
   * In this override, we need to change maximum precision in Oracle, that is
   * 38, differently from other core databases.
   */
  public function testSchemaAddFieldDefaultInitial() {
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
  public function testUnsignedColumns() {
    // First create the table with just a serial column.
    $table_name = 'unsigned_table';
    $table_spec = [
      'fields' => ['serial_column' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE]],
      'primary key' => ['serial_column'],
    ];
    $this->schema->createTable($table_name, $table_spec);

    // Now set up columns for the other types.
    $types = ['int', 'float', 'numeric'];
    foreach ($types as $type) {
      $column_spec = ['type' => $type, 'unsigned' => TRUE];
      if ($type == 'numeric') {
        $column_spec += ['precision' => 10, 'scale' => 0];
      }
      $column_name = $type . '_column';
      $table_spec['fields'][$column_name] = $column_spec;
      $this->schema->addField($table_name, $column_name, $column_spec);
    }

    // Finally, check each column and try to insert invalid values into them.
    foreach ($table_spec['fields'] as $column_name => $column_spec) {
      $this->assertTrue($this->schema->fieldExists($table_name, $column_name), new FormattableMarkup('Unsigned @type column was created.', ['@type' => $column_spec['type']]));
      if ($column_name !== 'serial_column') {
        $this->assertFalse($this->tryUnsignedInsert($table_name, $column_name), new FormattableMarkup('Unsigned @type column rejected a negative value.', ['@type' => $column_spec['type']]));
      }
    }
  }

}
