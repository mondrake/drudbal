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
    $this->connection = $connection;
dump([__METHOD__, 1, $connection->transactionDepth(), \Drupal\Core\Utility\Error::formatBacktrace(debug_backtrace())]);
    // If there is no transaction depth, then no transaction has started. Name
    // the transaction 'drupal_transaction'.
    if (!$depth = $connection->transactionDepth()) {
      $this->name = 'drupal_transaction';
    }
    // Within transactions, savepoints are used. Each savepoint requires a
    // name. So if no name is present we need to create one.
    elseif (!$name) {
      $this->name = 'savepoint_' . $depth;
    }
    else {
      $this->name = $name;
    }
dump([__METHOD__, 2, $connection->transactionDepth()]);
    $this->connection->pushTransaction($this->name);
dump([__METHOD__, 3, $connection->transactionDepth()]);
  }

}
