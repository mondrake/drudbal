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
class SchemaBisTest extends DriverSpecificSchemaTestBase {

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
    $this->markTestSkipped('test');
  }

  /**
   * {@inheritdoc}
   */
  public function testTableWithSpecificDataType(): void {
    $this->markTestSkipped('test');
  }

  /**
   * @covers \Drupal\drudbal\Driver\Database\dbal\Schema::introspectIndexSchema
   *
   * In this override, we need to change Oracle index names, since they cannot
   * exceed the 30 chars limit in Oracle 11.
   */
  public function testIntrospectIndexSchema(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests the findTables() method.
   */
  public function testFindTables(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests handling of uppercase table names.
   */
  public function testUpperCaseTableName(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests adding columns to an existing table with default and initial value.
   *
   * In this override, we need to change maximum precision in Oracle, that is
   * 38, differently from other core databases.
   */
  public function testSchemaAddFieldDefaultInitial(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests creating unsigned columns and data integrity thereof.
   *
   * In this override, we avoid testing insert on the serial column that in
   * Drupal core raises an exception, but not in Oracle where a trigger forces
   * the value to be next-in-sequence regardless of what is passed in.
   */
  public function testUnsignedColumns(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests handling with reserved keywords for naming tables, fields and more.
   */
  public function testReservedKeywordsForNaming(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests database interactions.
   */
  public function testSchema(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests various schema changes' effect on the table's primary key.
   *
   * @param array $initial_primary_key
   *   The initial primary key of the test table.
   * @param array $renamed_primary_key
   *   The primary key of the test table after renaming the test field.
   *
   * @dataProvider providerTestSchemaCreateTablePrimaryKey
   *
   * @covers ::addField
   * @covers ::changeField
   * @covers ::dropField
   * @covers ::findPrimaryKeyColumns
   */
  public function testSchemaChangePrimaryKey(array $initial_primary_key, array $renamed_primary_key): void {
    $this->markTestSkipped('test');
  }

  /**
   * Provides test cases for SchemaTest::testSchemaCreateTablePrimaryKey().
   *
   * @return array
   *   An array of test cases for SchemaTest::testSchemaCreateTablePrimaryKey().
   */
  public function providerTestSchemaCreateTablePrimaryKey() {
    $tests = [];

    $tests['simple_primary_key'] = [
      'initial_primary_key' => ['test_field'],
      'renamed_primary_key' => ['test_field_renamed'],
    ];
    $tests['composite_primary_key'] = [
      'initial_primary_key' => ['test_field', 'other_test_field'],
      'renamed_primary_key' => ['test_field_renamed', 'other_test_field'],
    ];
    $tests['composite_primary_key_different_order'] = [
      'initial_primary_key' => ['other_test_field', 'test_field'],
      'renamed_primary_key' => ['other_test_field', 'test_field_renamed'],
    ];

    return $tests;
  }

  /**
   * Tests an invalid field specification as a primary key on table creation.
   */
  public function testInvalidPrimaryKeyOnTableCreation(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests converting an int to a serial when the int column has data.
   */
  public function testChangePrimaryKeyToSerial(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests adding an invalid field specification as a primary key.
   */
  public function testInvalidPrimaryKeyAddition(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests changing the primary key with an invalid field specification.
   */
  public function testInvalidPrimaryKeyChange(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests changing columns between types with default and initial values.
   */
  public function testSchemaChangeFieldDefaultInitial(): void {
    $this->markTestSkipped('test');
  }

  /**
   * @covers ::findPrimaryKeyColumns
   */
  public function testFindPrimaryKeyColumns(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests default values after altering table.
   */
  public function testDefaultAfterAlter(): void {
    $this->markTestSkipped('test');
  }

  /**
   * Tests changing a field length.
   */
  public function testChangeSerialFieldLength(): void {
    $specification = [
      'fields' => [
        'id' => [
          'type' => 'serial',
          'not null' => TRUE,
          'description' => 'Primary Key: Unique ID.',
        ],
        'text' => [
          'type' => 'text',
          'description' => 'A text field',
        ],
      ],
      'primary key' => ['id'],
    ];
    $this->schema->createTable('change_serial_to_big', $specification);

    // Increase the size of the field.
    $new_specification = [
      'size' => 'big',
      'type' => 'serial',
      'not null' => TRUE,
      'description' => 'Primary Key: Unique ID.',
    ];
    $this->schema->changeField('change_serial_to_big', 'id', 'id', $new_specification);
    $this->assertTrue($this->schema->fieldExists('change_serial_to_big', 'id'));

    // Test if we can actually add a big int.
    $id = $this->connection->insert('change_serial_to_big')->fields([
      'id' => 21474836470,
    ])->execute();

    $id_two = $this->connection->insert('change_serial_to_big')->fields([
      'text' => 'Testing for ID generation',
    ])->execute();

    $this->assertEquals($id + 1, $id_two);
  }

}
