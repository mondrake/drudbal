<?php

namespace Drupal\Tests\drudbal\Kernel\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\KernelTests\Core\Database\DriverSpecificKernelTestBase;

/**
 * Tests exceptions thrown by queries.
 *
 * @group Database
 */
class DatabaseExceptionWrapperTest extends DriverSpecificKernelTestBase {

  /**
   * Tests Connection::prepareStatement exception on execution.
   */
  public function testPrepareStatementFailOnExecution() {
    $this->expectException(DatabaseExceptionWrapper::class);
    $stmt = $this->connection->prepareStatement('bananas', []);
    $stmt->execute();
  }

}
