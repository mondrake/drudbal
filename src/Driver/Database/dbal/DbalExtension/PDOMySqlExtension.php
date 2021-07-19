<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;

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
      // Limit SQL to a single statement like mysqli.
      \PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE,
      // Convert numeric values to strings when fetching. In PHP 8.1,
      // \PDO::ATTR_EMULATE_PREPARES now behaves the same way as non emulated
      // prepares and returns integers. See https://externals.io/message/113294
      // for further discussion.
      \PDO::ATTR_STRINGIFY_FETCHES => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->getDbalConnection()->getWrappedConnection()->getServerVersion();
  }

  /**
   * Transaction delegated methods.
   *
   * PHP8 changed behaviour of transactions when a DDL statement is executed.
   * A commit is executed automatically in that case (like before), but now the
   * inTransaction() method returns FALSE, which was not doing before. Trying to
   * execute a commit/rollback now throws a PDOException. Since DBAL does not
   * take care of that, its internal transactions stack remains in unsync state
   * in that case. We therefore bypass DBAL here and go to the wrapped
   * connection methods directly.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateInTransaction(): bool {
    return $this->getDbalConnection()->getWrappedConnection()->getWrappedConnection()->inTransaction();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateBeginTransaction(): bool {
    return $this->getDbalConnection()->getWrappedConnection()->getWrappedConnection()->beginTransaction();;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateRollBack(): bool {
    if ($this->delegateInTransaction()) {
      return $this->getDbalConnection()->getWrappedConnection()->getWrappedConnection()->rollBack();
    }
    trigger_error('Rollback attempted when there is no active transaction. This can cause data integrity issues.', E_USER_WARNING);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateCommit(): bool {
    if ($this->delegateInTransaction()) {
      return $this->getDbalConnection()->getWrappedConnection()->getWrappedConnection()->commit();
    }
    return FALSE;
  }

}
