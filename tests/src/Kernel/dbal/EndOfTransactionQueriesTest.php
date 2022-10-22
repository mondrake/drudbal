<?php

namespace Drupal\Tests\drudbal\Kernel\dbal;

use Drupal\KernelTests\Core\Cache\DriverSpecificEndOfTransactionQueriesTestBase;

/**
 * Tests that cache tag invalidation queries are delayed to the end of transactions.
 *
 * @group Cache
 */
class EndOfTransactionQueriesTest extends DriverSpecificEndOfTransactionQueriesTestBase {

  /**
   * {@inheritdoc}
   */
  protected function getDatabaseConnectionInfo() {
    $info = parent::getDatabaseConnectionInfo();
    // Override default database driver to one that does logging. Third-party
    // (non-core) database drivers can achieve the same test coverage by
    // subclassing this test class and overriding only this method.
    // @see \Drupal\database_statement_monitoring_test\LoggedStatementsTrait
    // @see \Drupal\drudbal\StatementMonitoring\dbal\Connection
    $info['default']['namespace'] = '\Drupal\drudbal\StatementMonitoring\dbal';
    return $info;
  }

}
