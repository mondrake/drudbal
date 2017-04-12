<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Insert as QueryInsert;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Insert.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Insert extends QueryInsert {

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
    if (!$this->preExecute()) {
      return NULL;
    }

    // If we're selecting from a SelectQuery, finish building the query and
    // pass it back, as any remaining options are irrelevant.
    if (empty($this->fromQuery)) {
      $max_placeholder = 0;
      $values = [];
      foreach ($this->insertValues as $insert_values) {
        foreach ($insert_values as $value) {
          $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
        }
      }
    }
    else {
      $values = $this->fromQuery->getArguments();
    }

if(in_array($this->table, ['test', 'test_people_copy', 'test_special_columns', 'mondrake_test'])) {
    // DBAL does not support multiple insert statements. In such case, open a
    // transaction and process separately each values set.
    $sql = (string) $this;
    if (count($this->insertValues) > 1) {
      $transaction = $this->connection->startTransaction();
    }
    foreach ($this->insertValues as $insert_values) {
      $max_placeholder = 0;
      $values = [];
      foreach ($insert_values as $value) {
        $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
      }
      $last_insert_id = $this->connection->query($sql, $values, $this->queryOptions);
    }
    if (count($this->insertValues) > 1) {
      $transaction = NULL;
    }
}
else {
    $last_insert_id = $this->connection->query((string) $this, $values, $this->queryOptions);
}

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return $last_insert_id;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $comments = $this->connection->makeComment($this->comments);

    // Default fields are always placed first for consistency.
    $insert_fields = array_merge($this->defaultFields, $this->insertFields);

    // If we're selecting from a SelectQuery, finish building the query and
    // pass it back, as any remaining options are irrelevant.
    if (!empty($this->fromQuery)) {
      $insert_fields_string = $insert_fields ? ' (' . implode(', ', $insert_fields) . ') ' : ' ';
      return $comments . 'INSERT INTO {' . $this->table . '}' . $insert_fields_string . $this->fromQuery;
    }

    $query = $comments . 'INSERT INTO {' . $this->table . '} (' . implode(', ', $insert_fields) . ') VALUES ';

    $values = $this->getInsertPlaceholderFragment($this->insertValues, $this->defaultFields);
    $query .= implode(', ', $values);

if(in_array($this->table, ['test', 'test_people_copy', 'test_special_columns', 'mondrake_test'])) {
    $dbal_connection = $this->connection->getDbalConnection();
    $prefixed_table = $this->connection->getPrefixedTableName($this->table);

    // Use DBAL query builder to prepare the INSERT query.
    $this->dbalQuery = $dbal_connection->createQueryBuilder()->insert($prefixed_table);

    $max_placeholder = 0;
    foreach ($insert_fields as $field) {
      $this->dbalQuery->setValue($field, ':db_insert_placeholder_' . $max_placeholder++);
    }
debug($this->dbalQuery->getSQL());
    return $comments . $this->dbalQuery->getSQL();
}
    return $query;
  }

}
