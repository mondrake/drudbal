<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Truncate.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Truncate extends QueryTruncate {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $dbal_extension = $this->connection->getDbalExtension();

    // Allow DBAL extension to process commands before a DDL TRUNCATE.
    if (!$this->connection->inTransaction()) {
      $dbal_extension->preTruncate($this->table);
    }

    // Process the truncate, either DDL or via DELETE.
    $result = $this->connection->query((string) $this, [], $this->queryOptions);

    // Allow DBAL extension to process commands after a DDL TRUNCATE.
    if (!$this->connection->inTransaction()) {
      $dbal_extension->postTruncate($this->table);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection->makeComment($this->comments);
    $dbal_connection = $this->connection->getDbalConnection();
    $prefixed_table = $this->connection->getPrefixedTableName($this->table);

    // In most cases, TRUNCATE is not a transaction safe statement as it is a
    // DDL statement which results in an implicit COMMIT. When we are in a
    // transaction, fallback to the slower, but transactional, DELETE.
    if ($this->connection->inTransaction()) {
      $dbal_query = $dbal_connection->createQueryBuilder()->delete($prefixed_table);
      return $comments . $dbal_query->getSQL();
    }
    else {
      $sql = $dbal_connection->getDatabasePlatform()->getTruncateTableSql($prefixed_table);
      return $comments . $sql;
    }
  }

}
