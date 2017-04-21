<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update as QueryUpdate;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Update.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Update extends QueryUpdate {

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
// @todo
    $x = (string) $this;
    $this->connection->pushStatementOption('allowRowCount', TRUE);
    return $this->dbalQuery->execute();
//    return $this->connection->query((string) $this, $this->dbalQuery->getParameters(), $this->queryOptions);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection->makeComment($this->comments);
    $dbal_connection = $this->connection->getDbalConnection();
    $prefixed_table = $this->connection->getPrefixedTableName($this->table);

    // Use DBAL query builder to prepare the UPDATE query.
    $this->dbalQuery = $dbal_connection->createQueryBuilder()->update($prefixed_table);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    foreach ($this->expressionFields as $field => $data) {
      // If arguments are set, these are are placeholders and values that need
      // to be passed as parameters to the query.
      if (!empty($data['arguments'])) {
        foreach ($data['arguments'] as $placeholder => $value) {
          $this->dbalQuery->setParameter($placeholder, $value);
        }
      }
      // If the expression is a select subquery, compile it and capture its
      // placeholders and values as parameters for the entire query. Otherwise,
      // just set the field to the expression.
      if ($data['expression'] instanceof SelectInterface) {
        $data['expression']->compile($this->connection, $this);
        $this->dbalQuery->set($field, '(' . $data['expression'] . ')');
        foreach ($data['expression']->arguments() as $placeholder => $value) {
          $this->dbalQuery->setParameter($placeholder, $value);
        }
      }
      else {
        $this->dbalQuery->set($field, $data['expression']);
      }
      unset($fields[$field]);
    }

    // Add fields to have to be updated to a given value.
    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $this->dbalQuery
        ->set($field, ':db_update_placeholder_' . ($max_placeholder))
        ->setParameter(':db_update_placeholder_' . ($max_placeholder), $value);
      $max_placeholder++;
    }

    // Adds a WHERE clause if necessary.
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $this->dbalQuery->where((string) $this->condition);
      foreach ($this->condition->arguments() as $placeholder => $value) {
        $this->dbalQuery->setParameter($placeholder, $value);
      }
    }

    return $comments . $this->dbalQuery->getSQL();
  }

}
