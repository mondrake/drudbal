<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Transaction as TransactionBase;

class Transaction extends TransactionBase {

  public function __construct(Connection $connection, $name = NULL) {
dump([__METHOD__, 1, $connection->transactionDepth(), $connection->getDbalConnection()->getNativeConnection()->inTransaction(), \Drupal\Core\Utility\Error::formatBacktrace(debug_backtrace())]);
    parent::__construct($connection, $name);
  }

  public function __destruct() {
dump([__METHOD__, 1, $this->connection->transactionDepth(), $this->connection->getDbalConnection()->getNativeConnection()->inTransaction(), \Drupal\Core\Utility\Error::formatBacktrace(debug_backtrace())]);
    parent::__destruct();
  }

}
