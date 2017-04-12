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
    $comments = $this->connection->makeComment($this->comments);
    $dbal_connection = $this->connection->getDbalConnection();
    $dbal_extension = $this->connection->getDbalExtension();
    $prefixed_table = $this->connection->getPrefixedTableName($this->table);

    // In most cases, TRUNCATE is not a transaction safe statement as it is a
    // DDL statement which results in an implicit COMMIT. When we are in a
    // transaction, fallback to the slower, but transactional, DELETE.
    if ($this->connection->inTransaction()) {
      $dbal_query = $dbal_connection->createQueryBuilder()->delete($prefixed_table);
      return $this->connection->query($comments . $dbal_query->getSQL(), [], $this->queryOptions);
    }
    else {
      $sql = $dbal_connection->getDatabasePlatform()->getTruncateTableSql($prefixed_table);
      $dbal_extension->preTruncate($prefixed_table);
      $result = $this->connection->query($comments . $sql, [], $this->queryOptions);
      $dbal_extension->postTruncate($prefixed_table);
      return $result;
    }
  }

}
