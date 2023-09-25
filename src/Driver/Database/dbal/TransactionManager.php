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
    /** @var \Drupal\drudbal\Driver\Database\dbal\Connection $connection */
    $connection = $this->connection;
    return $connection->getDbalExtension()->delegateBeginTransaction();
  }

  /**
   * {@inheritdoc}
   */
  protected function rollbackClientTransaction(): bool {
    /** @var \Drupal\drudbal\Driver\Database\dbal\Connection $connection */
    $connection = $this->connection;
    $clientRollback = $connection->getDbalExtension()->delegateRollBack();
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
    /** @var \Drupal\drudbal\Driver\Database\dbal\Connection $connection */
    $connection = $this->connection;
    $clientCommit = $connection->getDbalExtension()->delegateCommit();
    $this->setConnectionTransactionState($clientCommit ?
      ClientConnectionTransactionState::Committed :
      ClientConnectionTransactionState::CommitFailed
    );
    return $clientCommit;
  }

}
