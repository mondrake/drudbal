<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests the temporary query functionality.
 *
 * @group Database
 */
class QueryTemporaryTest extends DatabaseTestBase {

  /**
   * Confirms that temporary tables work.
   */
  public function testTemporaryQuery() {
    // Add a new target to the connection, by cloning the current connection.
    $connection_info = Database::getConnectionInfo();
    Database::addConnectionInfo('default', 'temp_query', $connection_info['default']);

    $connection = Database::getConnection('temp_query');

    // Now try to run two temporary queries in the same request.
    $table_name_test = $connection->queryTemporary('SELECT [name] FROM {test}', []);
    $table_name_task = $connection->queryTemporary('SELECT [pid] FROM {test_task}', []);

    $this->assertEquals($connection->select('test')->countQuery()->execute()->fetchField(), $connection->select($table_name_test)->countQuery()->execute()->fetchField(), 'A temporary table was created successfully in this request.');
    $this->assertEquals($connection->select('test_task')->countQuery()->execute()->fetchField(), $connection->select($table_name_task)->countQuery()->execute()->fetchField(), 'A second temporary table was created successfully in this request.');

    // Check that leading whitespace and comments do not cause problems
    // in the modified query.
    $sql = "
      -- Let's select some rows into a temporary table
      SELECT [name] FROM {test}
    ";
    $table_name_test = $connection->queryTemporary($sql, []);
    $this->assertEquals($connection->select('test')->countQuery()->execute()->fetchField(), $connection->select($table_name_test)->countQuery()->execute()->fetchField(), 'Leading white space and comments do not interfere with temporary table creation.');

    $connection = NULL;
    Database::closeConnection('temp_query');
  }

  /**
   * Confirms updating to NULL.
   */
  public function testSimpleNullUpdate() {
    $this->ensureSampleDataNull();
    $num_updated = $this->connection->update('test_null')
      ->fields(['age' => NULL])
      ->condition('name', 'Kermit')
      ->execute();
    $this->assertIdentical($num_updated, 1, 'Updated 1 record.');
dump($this->connection->query('SELECT * FROM {test_null}')->fetchAll());
    $saved_age = $this->connection->query('SELECT [age] FROM {test_null} WHERE [name] = :name', [':name' => 'Kermit'])->fetchField();
    $this->assertNull($saved_age, 'Updated name successfully.');
  }

  /**
   * Tests the Schema::indexExists() method.
   */
  public function testDBIndexExists() {
    $this->assertTrue($this->connection->schema()->indexExists('test', 'ages'), 'Returns true for existent index.');
    $this->assertFalse($this->connection->schema()->indexExists('test', 'no_such_index'), 'Returns false for nonexistent index.');
  }

}
