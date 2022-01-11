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

}
