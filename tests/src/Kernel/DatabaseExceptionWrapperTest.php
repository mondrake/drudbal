<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\Core\Database\DatabaseExceptionWrapperTest as DatabaseExceptionWrapperTestBase;
use Drupal\KernelTests\KernelTestBase;

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
   * Tests Connection::prepareStatement exceptions.
   */
  public function testPrepareStatement() {
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
