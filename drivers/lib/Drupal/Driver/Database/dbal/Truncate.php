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
   * Executes the TRUNCATE operation.
   *
   * @return
   *   Return value is dependent on the database type.
   */
  public function execute() {
    $comments = $this->connection->makeComment($this->comments);
// @todo comments? any test?
// @todo what to do with $this->queryOptions??
// @todo what shall we return here??

    $dbal_connection = $this->connection->getDbalConnection();
    $prefixed_table = $this->connection->getDbalExtension()->pfxTable($this->table);
    // In most cases, TRUNCATE is not a transaction safe statement as it is a
    // DDL statement which results in an implicit COMMIT. When we are in a
    // transaction, fallback to the slower, but transactional, DELETE.
    if ($this->connection->inTransaction()) {
      $dbal_query = $dbal_connection->createQueryBuilder()->delete($prefixed_table);
      $sql = (string) $dbal_query;
      if (!empty($comments)) {
        $sql = $comments . $sql;
      }
      return $dbal_connection->executeUpdate($sql);
    }
    else {
      $db_platform = $dbal_connection->getDatabasePlatform();
      $dbal_connection->query('SET FOREIGN_KEY_CHECKS=0'); // @todo platform independent??
      $sql = $db_platform->getTruncateTableSql($prefixed_table);
      if (!empty($comments)) {
        $sql = $comments . $sql;
      }
      $result = $dbal_connection->executeUpdate($sql);
      $dbal_connection->query('SET FOREIGN_KEY_CHECKS=1'); // @todo platform independent??
      return $result;
    }
  }

}
