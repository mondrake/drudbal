<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Insert as QueryInsert;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Insert.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Insert extends QueryInsert {

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
    if (!$this->preExecute()) {
      return NULL;
    }

    $sql = (string) $this;

    /** @var SelectInterface|null $fromQuery */
    $fromQuery = $this->fromQuery;

    // DBAL does not support multiple insert statements. In such case, open a
    // transaction, and process separately each values set.
    if ((count($this->insertValues) > 1 || !empty($fromQuery))) {
      // @codingStandardsIgnoreLine
      $trn = $this->connection()->startTransaction();
    }

    // Get from extension if a sequence name should be attached to the insert
    // query.
    $sequence_name = $this->connection()->getDbalExtension()->getSequenceNameForInsert($this->table);

    $last_insert_id = NULL;
    if (empty($fromQuery)) {
      // Deal with a single INSERT or a bulk INSERT.
      if ($this->insertValues) {
        foreach ($this->insertValues as $insert_values) {
          $max_placeholder = 0;
          $values = [];
          foreach ($insert_values as $value) {
            $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
          }
          try {
            $stmt = $this->connection()->prepareStatement($sql, $this->queryOptions);
            try {
              $stmt->execute($values, $this->queryOptions);
              try {
                $last_insert_id = $this->connection()->lastInsertId($sequence_name);
              }
              catch (DatabaseObjectNotFoundException|DbalDriverException $e) {
                $last_insert_id = 0;
              }
            }
            catch (\Exception $e) {
              $this->connection()->exceptionHandler()->handleExecutionException($e, $stmt, $values, $this->queryOptions);
            }
          }
          catch (IntegrityConstraintViolationException $e) {
            // Abort the entire insert in case of integrity constraint violation
            // and a transaction is open.
            if (isset($trn) && $this->connection()->inTransaction()) {
              $trn->rollBack();
            }
            throw $e;
          }
        }
      }
      else {
        // If there are no values, then this is a default-only query. We still
        // need to handle that.
        $stmt = $this->connection()->prepareStatement($sql, $this->queryOptions);
        try {
          $stmt->execute([], $this->queryOptions);
          try {
            $last_insert_id = $this->connection()->lastInsertId($sequence_name);
          }
          catch (DatabaseObjectNotFoundException|DbalDriverException $e) {
            $last_insert_id = 0;
          }
        }
        catch (\Exception $e) {
          $this->connection()->exceptionHandler()->handleExecutionException($e, $stmt, [], $this->queryOptions);
        }
      }
    }
    else {
      // Deal with a INSERT INTO ... SELECT construct, that DBAL does not
      // support natively. Execute the SELECT subquery and INSERT its rows'
      // values to the target table.
      $rows = $fromQuery->execute();
      foreach ($rows as $row) {
        $max_placeholder = 0;
        $values = [];
        foreach ($row as $value) {
          $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
        }
        try {
          $stmt = $this->connection()->prepareStatement($sql, $this->queryOptions);
          try {
            $stmt->execute($values, $this->queryOptions);
            try {
              $last_insert_id = $this->connection()->lastInsertId($sequence_name);
            }
            catch (DatabaseObjectNotFoundException|DbalDriverException $e) {
              $last_insert_id = 0;
            }
          }
          catch (\Exception $e) {
            $this->connection()->exceptionHandler()->handleExecutionException($e, $stmt, $values, $this->queryOptions);
          }
        }
        catch (IntegrityConstraintViolationException $e) {
          // Abort the entire insert in case of integrity constraint violation
          // and a transaction is open.
          if (isset($trn) && $this->connection()->inTransaction()) {
            $trn->rollBack();
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
    /** @var SelectInterface|null $fromQuery */
    $fromQuery = $this->fromQuery;

    $comments = $this->connection()->makeComment($this->comments);
    $dbal_connection = $this->connection()->getDbalConnection();
    $dbal_extension = $this->connection()->getDbalExtension();

    $sql = '';

    // Use special syntax, if available, for an insert of only default values.
    if (count($this->insertFields) === 0 && empty($fromQuery) && $dbal_extension->delegateDefaultsOnlyInsertSql($sql, $this->table)) {
      return $comments . $sql;
    }

    // Use DBAL query builder to prepare the INSERT query. The table name has to
    // be quoted in DBAL.
    $dbal_query = $dbal_connection->createQueryBuilder()->insert($this->connection()->getPrefixedTableName($this->table, TRUE));

    // If we're selecting from a SelectQuery, and no fields are specified in
    // select (i.e. we have a SELECT * FROM ...), then we have to fetch the
    // target column names from the table to be INSERTed to, since DBAL does
    // not support 'INSERT INTO ... SELECT * FROM' constructs.
    if (!empty($fromQuery) && empty($fromQuery->getFields())) {
      $insert_fields = array_keys($dbal_connection->createSchemaManager()->listTableColumns($this->connection()->getPrefixedTableName($this->table)));
    }
    else {
      if ($this->connection()->getDbalExtension()->getAddDefaultsExplicitlyOnInsert()) {
        foreach ($this->defaultFields as $field) {
          $dbal_query->setValue($dbal_extension->getDbFieldName($field), 'default');
        }
      }
      $insert_fields = $this->insertFields;
    }
    $max_placeholder = 0;

    foreach ($insert_fields as $field) {
      $dbal_query->setValue($dbal_extension->getDbFieldName($field), ':db_insert_placeholder_' . $max_placeholder++);
    }

    return $comments . $dbal_query->getSQL();
  }

}
