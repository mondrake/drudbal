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

  protected function beginClientTransaction(): bool {
    /** @var \Drupal\drudbal\Driver\Database\dbal\Connection $connection */
    $connection = $this->connection;
    return $connection->getDbalExtension()->delegateBeginTransaction();
  }

  protected function addClientSavepoint(string $name): bool {
    /** @var \Drupal\drudbal\Driver\Database\dbal\Connection $connection */
    $connection = $this->connection;
    return $connection->getDbalExtension()->delegateAddClientSavepoint($name);
  }

  protected function rollbackClientSavepoint(string $name): bool {
    /** @var \Drupal\drudbal\Driver\Database\dbal\Connection $connection */
    $connection = $this->connection;
    return $connection->getDbalExtension()->delegateRollbackClientSavepoint($name);
  }

  protected function releaseClientSavepoint(string $name): bool {
    /** @var \Drupal\drudbal\Driver\Database\dbal\Connection $connection */
    $connection = $this->connection;
    if ($connection->getDbalExtension()->delegateReleaseClientSavepoint($name)) {
      return TRUE;
    }
    // If the rollback failed, most likely the savepoint was not there
    // because the transaction is no longer active. In this case we rollback
    // to root and cleanup.
    $connection->getDbalExtension()->delegateRollBack();
    $this->voidClientTransaction();
    return TRUE;
  }

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
