<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Drupal\Core\Database\TransactionNoActiveException;

/**
 * Driver specific methods for pdo_mysql.
 */
class PDOMySqlExtension extends AbstractMySqlExtension {

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    parent::preConnectionOpen($connection_options, $dbal_connection_options);
    $dbal_connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by
      // default.
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's
      // dumb.
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ];
    if (defined('\PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      // An added connection option in PHP 5.5.21 to optionally limit SQL to a
      // single statement like mysqli.
      $dbal_connection_options['driverOptions'] += [\PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->getDbalConnection()->getWrappedConnection()->getServerVersion();
  }

  /**
   * Transaction delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateRollBack(): void {
    if ($this->getDbalConnection()->getWrappedConnection()->getWrappedConnection()->inTransaction()) {
      $this->getDbalConnection()->rollBack();
      // On PHP 7 \PDO::inTransaction() will return TRUE and \PDO::rollback()
      // does not throw an exception; the following code is unreachable.

      // If \Drupal\Core\Database\Connection::rollBack() would throw an
      // exception then continue to throw an exception.
      //      if (!$this->connection->inTransaction()) {
      //        throw new TransactionNoActiveException();
      //      }
      // A previous rollback to an earlier savepoint may mean that the savepoint
      // in question has already been accidentally committed.
      //      if (!isset($this->transactionLayers[$savepoint_name])) {
      //        throw new TransactionNoActiveException();
      //      }

      //      trigger_error('Rollback attempted when there is no active transaction. This can cause data integrity issues.', E_USER_WARNING);
      //      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateCommit(): void {
    if ($this->getDbalConnection()->getWrappedConnection()->getWrappedConnection()->inTransaction()) {
      // On PHP 7 \PDO::inTransaction() will return TRUE and \PDO::commit()
      // does not throw an exception.
      $this->getDbalConnection()->commit();
    }
//    else {
      // Process the post-root (non-nested) transaction commit callbacks. The
      // following code is copied from
      // \Drupal\Core\Database\Connection::doCommit()
//      if (!empty($this->rootTransactionEndCallbacks)) {
//        $callbacks = $this->rootTransactionEndCallbacks;
//        $this->rootTransactionEndCallbacks = [];
//        foreach ($callbacks as $callback) {
//          call_user_func($callback, $success);
//        }
//      }
//    }
  }

}
