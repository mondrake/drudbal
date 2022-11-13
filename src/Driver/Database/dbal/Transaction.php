<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Transaction as TransactionBase;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Merge.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Transaction extends TransactionBase {

  public function __construct(Connection $connection, $name = NULL) {
dump([__METHOD__, 1, $connection->transactionDepth(), \Drupal\Core\Utility\Error::formatBacktrace(debug_backtrace())]);
    parent::__construct($connection, $name);
  }

  public function __destruct() {
dump([__METHOD__, 1, $connection->transactionDepth(), \Drupal\Core\Utility\Error::formatBacktrace(debug_backtrace())]);
    parent::__destruct();
  }

  public function name() {
dump([__METHOD__, 1]);
    return parent::name();
  }

  public function rollBack() {
dump([__METHOD__, 1, $connection->transactionDepth(), \Drupal\Core\Utility\Error::formatBacktrace(debug_backtrace())]);
    return parent::rollBack();
  }

}
