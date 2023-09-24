<?php

declare(strict_types=1);

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Transaction\ClientConnectionTransactionState;
use Drupal\Core\Database\Transaction\TransactionManagerBase;

/**
 * DruDbal implementation of the TransactionManager.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class TransactionManager extends TransactionManagerBase {

  /**
   * {@inheritdoc}
   */
  protected function beginClientTransaction(): bool {
    return $this->connection->getDbalConnection()->getNativeConnection()->beginTransaction();
  }

  /**
   * {@inheritdoc}
   */
  protected function rollbackClientTransaction(): bool {
    $clientRollback = $this->connection->getDbalConnection()->getNativeConnection()->rollBack();
    $this->setConnectionTransactionState($clientRollback ?
      ClientConnectionTransactionState::RolledBack :
      ClientConnectionTransactionState::RollbackFailed
    );
    return $clientRollback;
  }

  /**
   * {@inheritdoc}
   */
  protected function commitClientTransaction(): bool {
    $clientCommit = $this->connection->getDbalConnection()->getNativeConnection()->commit();
    $this->setConnectionTransactionState($clientCommit ?
      ClientConnectionTransactionState::Committed :
      ClientConnectionTransactionState::CommitFailed
    );
    return $clientCommit;
  }

}