<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Truncate.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Truncate extends QueryTruncate {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // Allow DBAL extension to process commands before a DDL TRUNCATE.
    if (!$this->connection->inTransaction()) {
      $this->connection->getDbalExtension()->preTruncate($this->table);
    }

    // Process the truncate, either DDL or via DELETE.
    $stmt = $this->connection->prepareStatement((string) $this, $this->queryOptions, TRUE);
    try {
      $stmt->execute([], $this->queryOptions);
      $result = $stmt->rowCount();
    }
    catch (\Exception $e) {
      $this->connection->exceptionHandler()->handleExecutionException($e, $stmt, [], $this->queryOptions);
    }

    // Allow DBAL extension to process commands after a DDL TRUNCATE.
    if (!$this->connection->inTransaction()) {
      $this->connection->getDbalExtension()->postTruncate($this->table);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection->makeComment($this->comments);
    $prefixed_table = $this->connection->getPrefixedTableName($this->table, TRUE);

    // In most cases, TRUNCATE is not a transaction safe statement as it is a
    // DDL statement which results in an implicit COMMIT. When we are in a
    // transaction, fallback to the slower, but transactional, DELETE.
    if ($this->connection->inTransaction()) {
      $dbal_query = $this->connection->getDbalConnection()->createQueryBuilder()->delete($prefixed_table);
      return $comments . $dbal_query->getSQL();
    }
    else {
      $sql = $this->connection->getDbalConnection()->getDatabasePlatform()->getTruncateTableSql($prefixed_table);
      return $comments . $sql;
    }
  }

}
