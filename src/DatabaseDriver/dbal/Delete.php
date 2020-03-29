<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Delete as QueryDelete;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Delete.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Delete extends QueryDelete {

  /**
   * A DBAL query builder object.
   *
   * @var \Doctrine\DBAL\Query\QueryBuilder
   */
  protected $dbalQuery;

  /**
   * {@inheritdoc}
   */
  public function execute() {
    return $this->connection->query((string) $this, $this->dbalQuery->getParameters(), $this->queryOptions);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection->makeComment($this->comments);
    $this->compileDbalQuery();
    return $comments . $this->dbalQuery->getSQL();
  }

  /**
   * Builds the query via DBAL Query Builder.
   */
  protected function compileDbalQuery() {
    // Need to pass the quoted table name here.
    $this->dbalQuery = $this->connection->getDbalConnection()
      ->createQueryBuilder()
      ->delete($this->connection->getPrefixedTableName($this->table, TRUE));

    // Adds a WHERE clause if necessary.
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $this->dbalQuery->where((string) $this->condition);
      foreach ($this->condition->arguments() as $placeholder => $value) {
        $this->dbalQuery->setParameter($placeholder, $value);
      }
    }

    return $this;
  }

}
