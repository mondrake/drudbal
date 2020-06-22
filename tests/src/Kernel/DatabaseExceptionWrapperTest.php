<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Doctrine\DBAL\DBALException;

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
    $this->expectException(DBALException::class);
    $stmt = Database::getConnection()->prepareStatement('bananas', []);
    $stmt->execute();
  }

}
