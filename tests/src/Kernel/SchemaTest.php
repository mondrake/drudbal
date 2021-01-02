<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\Core\Database\SchemaTest as SchemaTestBase;

/**
 * Tests table creation and modification via the schema API.
 *
 * @group Database
 */
class SchemaTest extends SchemaTestBase {

  /**
   * Tests the findTables() method.
   */
  public function testFindTables() {
    // We will be testing with three tables, two of them using the default
    // prefix and the third one with an individually specified prefix.
    // Set up a new connection with different connection info.
    $connection_info = Database::getConnectionInfo();

    // Add per-table prefix to the second table.
    $new_connection_info = $connection_info['default'];
    $new_connection_info['prefix']['test_2_table'] = $new_connection_info['prefix']['default'] . '_shared_';
    Database::addConnectionInfo('test', 'default', $new_connection_info);
    Database::setActiveConnection('test');
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
    $test_schema->createTable('test_2_table', $table_specification);
    $test_schema->createTable('table_3_test', $table_specification);

    // Check the "all tables" syntax.
    $tables = $test_schema->findTables('%');
    sort($tables);
    $expected = [
      // The 'config' table is added by
      // \Drupal\KernelTests\KernelTestBase::containerBuild().
      'config',
      'table_3_test',
      'test_1_table',
      // This table uses a per-table prefix, yet it is returned as un-prefixed.
      'test_2_table',
    ];
    $this->assertEquals($expected, $tables);

    // Check the restrictive syntax.
    $tables = $test_schema->findTables('test_%');
    sort($tables);
    $expected = [
      'test_1_table',
      'test_2_table',
    ];
    $this->assertEquals($expected, $tables);

    // Go back to the initial connection.
    Database::setActiveConnection('default');
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

}
