<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\Core\Database\DatabaseExceptionWrapperTest as DatabaseExceptionWrapperTestBase;

/**
 * Tests exceptions thrown by queries.
 *
 * @overridesTestClass \Drupal\KernelTests\Core\Database\DatabaseExceptionWrapperTest
 * @group Database
 */
class DatabaseExceptionWrapperTest extends DatabaseExceptionWrapperTestBase {

  /**
   * Tests deprecation of Connection::prepare.
   */
  public function testPrepare() {
    $this->markTestSkipped('It\'s deprecated, Jim.');
  }

  /**
   * Tests deprecation of Connection::prepareQuery.
   */
  public function testPrepareQuery() {
    $this->markTestSkipped('It\'s deprecated, Jim.');
  }

  /**
   * Tests Connection::prepareStatement exceptions on execution.
   */
  public function testPrepareStatementFailOnExecution() {
    if (in_array(Database::getConnection()->driver(), ['mysql', 'sqlite'])) {
      $this->expectException(\PDOException::class);
    }
    else {
      $this->expectException(DatabaseExceptionWrapper::class);
    }
    $stmt = Database::getConnection()->prepareStatement('bananas', []);
    $stmt->execute();
  }

  /**
   * Tests deprecation of Connection::handleQueryException.
   */
  public function testHandleQueryExceptionDeprecation(): void {
    $this->markTestSkipped('It\'s deprecated, Jim.');
  }

}
