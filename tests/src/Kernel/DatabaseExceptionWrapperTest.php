<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests exceptions thrown by queries.
 *
 * @overridesTestClass \Drupal\KernelTests\Core\Database\DatabaseExceptionWrapperTest
 * @group Database
 */
class DatabaseExceptionWrapperTest extends KernelTestBase {

  /**
   * Tests deprecation of Connection::prepare.
   */
  public function testPrepare() {
    $this->merkTestSkipped('It\'s deprecated, Jim.');
  }

  /**
   * Tests deprecation of Connection::prepareQuery.
   */
  public function testPrepareQuery() {
    $this->merkTestSkipped('It\'s deprecated, Jim.');
  }

  /**
   * Tests Connection::prepareStatement exceptions.
   */
  public function testPrepareStatement() {
    $this->expectException(\PDOException::class);
    $stmt = Database::getConnection()->prepareStatement('bananas', []);
    $stmt->execute();
  }

  /**
   * Tests the expected database exception thrown for inexistent tables.
   */
  public function testQueryThrowsDatabaseExceptionWrapperException() {
    $connection = Database::getConnection();
    try {
      $connection->query('SELECT * FROM {does_not_exist}');
      $this->fail('Expected PDOException, none was thrown.');
    }
    catch (DatabaseExceptionWrapper $e) {
      $this->pass('Expected DatabaseExceptionWrapper was thrown.');
    }
    catch (\Exception $e) {
      $this->fail("Thrown exception is not a DatabaseExceptionWrapper:\n" . (string) $e);
    }
  }

}
