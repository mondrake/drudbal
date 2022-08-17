<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update as QueryUpdate;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Update.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
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
  public function __construct(Connection $connection, string $table, array $options = []) {
    // @todo Remove the __construct in Drupal 11.
    // @see https://www.drupal.org/project/drupal/issues/3256524
    parent::__construct($connection, $table, $options);
    unset($this->queryOptions['return']);
  }

  /**
   * Returns the DruDbal connection.
   */
  private function connection(): Connection {
    $connection = $this->connection;
    assert($connection instanceof Connection);
    return $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $stmt = $this->connection()->prepareStatement((string) $this, $this->queryOptions, TRUE);
    try {
      $stmt->execute($this->dbalQuery->getParameters(), $this->queryOptions);
      return $stmt->rowCount();
    }
    catch (\Exception $e) {
      $this->connection()->exceptionHandler()->handleExecutionException($e, $stmt, $this->dbalQuery->getParameters(), $this->queryOptions);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection()->makeComment($this->comments);
    $this->compileDbalQuery();
    return $comments . $this->dbalQuery->getSQL();
  }

  /**
   * Builds the query via DBAL Query Builder.
   */
  protected function compileDbalQuery() {
    $dbal_extension = $this->connection()->getDbalExtension();

    // Need to pass the quoted table name here.
    $this->dbalQuery = $this->connection()->getDbalConnection()
      ->createQueryBuilder()
      ->update($this->connection()->getPrefixedTableName($this->table, TRUE));

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
        $this->dbalQuery->set($dbal_extension->getDbFieldName($field), '(' . $data['expression'] . ')');
        foreach ($data['expression']->arguments() as $placeholder => $value) {
          $this->dbalQuery->setParameter($placeholder, $value);
        }
      }
      else {
        $this->dbalQuery->set($dbal_extension->getDbFieldName($field), $data['expression']);
      }
      unset($fields[$field]);
    }

    // Add fields that have to be updated to a given value.
    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $this->dbalQuery
        ->set($dbal_extension->getDbFieldName($field), ':db_update_placeholder_' . ($max_placeholder))
        ->setParameter(':db_update_placeholder_' . ($max_placeholder), $value);
      $max_placeholder++;
    }

    // Adds the WHERE clause.
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
