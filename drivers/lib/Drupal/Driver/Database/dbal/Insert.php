<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\IntegrityConstraintViolationException;
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
   * {@inheritdoc}
   */
  public function execute() {
    if (!$this->preExecute()) {
      return NULL;
    }

    $sql = (string) $this;

    // DBAL does not support multiple insert statements. In such case, open a
    // transaction (if supported), and process separately each values set.
    if ((count($this->insertValues) > 1 || !empty($this->fromQuery)) && $this->connection->supportsTransactions()) {
      $this->connection->startTransaction();
    }

    $last_insert_id = NULL;
    if (empty($this->fromQuery)) {
      // Deal with a single INSERT or a bulk INSERT.
      if ($this->insertValues) {
        foreach ($this->insertValues as $insert_values) {
          $max_placeholder = 0;
          $values = [];
          foreach ($insert_values as $value) {
            $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
          }
          try {
            $last_insert_id = $this->connection->query($sql, $values, $this->queryOptions);
          }
          catch (IntegrityConstraintViolationException $e) {
            // Abort the entire insert in case of integrity constraint violation
            // and a transaction is open.
            if ($this->connection->inTransaction()) {
              $this->connection->rollBack();
            }
            throw $e;
          }
        }
      }
      else {
        // If there are no values, then this is a default-only query. We still
        // need to handle that.
        $last_insert_id = $this->connection->query($sql, [], $this->queryOptions);
      }
    }
    else {
      // Deal with a INSERT INTO ... SELECT construct, that DBAL does not
      // support natively. Execute the SELECT subquery and INSERT its rows'
      // values to the target table.
      $rows = $this->fromQuery->execute();
      foreach ($rows as $row) {
        $max_placeholder = 0;
        $values = [];
        foreach ($row as $value) {
          $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
        }
        try {
          $last_insert_id = $this->connection->query($sql, $values, $this->queryOptions);
        }
        catch (IntegrityConstraintViolationException $e) {
          // Abort the entire insert in case of integrity constraint violation
          // and a transaction is open.
          if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
          }
          throw $e;
        }
      }
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return $last_insert_id;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection->makeComment($this->comments);
    $dbal_connection = $this->connection->getDbalConnection();

    $sql = '';

    // Use special syntax, if available, for an insert of only default values.
    if (count($this->insertFields) === 0 && empty($this->fromQuery) && $this->connection->getDbalExtension()->delegateDefaultsOnlyInsertSql($sql, $this->table)) {
      return $comments . $sql;
    }

    // Use DBAL query builder to prepare the INSERT query.
    $prefixed_table = $this->connection->getPrefixedTableName($this->table);
    $dbal_query = $dbal_connection->createQueryBuilder()->insert($prefixed_table);

    // If we're selecting from a SelectQuery, and no fields are specified in
    // select (i.e. we have a SELECT * FROM ...), then we have to fetch the
    // target column names from the table to be INSERTed to, since DBAL does
    // not support 'INSERT INTO ... SELECT * FROM' constructs.
    if (!empty($this->fromQuery) && empty($this->fromQuery->getFields())) {
      $insert_fields = array_keys($dbal_connection->getSchemaManager()->listTableColumns($prefixed_table));
    }
    else {
      if ($this->connection->getDbalExtension()->getAddDefaultsExplicitlyOnInsert()) {
        foreach ($this->defaultFields as $field) {
          $dbal_query->setValue($field, 'default');
        }
      }
      $insert_fields = $this->insertFields;
    }
    $max_placeholder = 0;

    foreach ($insert_fields as $field) {
      $dbal_query->setValue($field, ':db_insert_placeholder_' . $max_placeholder++);
    }

    return $comments . $dbal_query->getSQL();
  }

}
