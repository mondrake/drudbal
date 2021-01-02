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
    $connection = Database::getConnection();

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
  }

}
