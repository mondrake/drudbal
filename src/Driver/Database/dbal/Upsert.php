<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Upsert as QueryUpsert;
use Doctrine\DBAL\Exception\DeadlockException as DBALDeadlockException;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Upsert.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Upsert extends QueryUpsert {

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
    // DBAL does not support UPSERT. Open a transaction (if supported), and
    // process separate inserts. In case of integrity constraint violation,
    // fall back to an update.
    // @see https://github.com/doctrine/dbal/issues/1320
    // @todo what to do if no transaction support.
    if (!$this->preExecute()) {
      return 0;
    }

    $sql = (string) $this;

    // Loop through the values to be UPSERTed.
    $affected_rows = NULL;
    if ($this->insertValues) {
      if ($this->connection()->getDbalExtension()->hasNativeUpsert()) {
        // Native UPSERT.
        $max_placeholder = 0;
        $values = [];
        foreach ($this->insertValues as $insert_values) {
          foreach ($insert_values as $value) {
            $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
          }
        }
        $stmt = $this->connection()->prepareStatement((string) $this, $this->queryOptions, TRUE);
        try {
          $stmt->execute($values, $this->queryOptions);
          $affected_rows = $stmt->rowCount();
        }
        catch (\Exception $e) {
          $this->connection()->exceptionHandler()->handleExecutionException($e, $stmt, $values, $this->queryOptions);
        }
      }
      else {
        // Emulated UPSERT.
        // @codingStandardsIgnoreLine
//global $xxx; if ($xxx) dump(['upsert:execute']);
        $trn = $this->connection()->startTransaction();

        $affected_rows = 0;
        foreach ($this->insertValues as $insert_values) {
          $max_placeholder = 0;
          $values = [];
          foreach ($insert_values as $value) {
            $values[':db_insert_placeholder_' . $max_placeholder++] = $value;
          }
          try {
            $stmt = $this->connection()->prepareStatement($sql, $this->queryOptions, TRUE);
            try {
//if ($xxx) dump(['upsert:2', $sql]);
              $stmt->execute($values, $this->queryOptions);
              $affected_rows += $stmt->rowCount();
            }
            catch (\Exception $e) {
              $this->connection()->exceptionHandler()->handleExecutionException($e, $stmt, $values, $this->queryOptions);
            }
          }
          catch (IntegrityConstraintViolationException $e) {
//if ($xxx) dump(['upsert:3', $e->getMessage()]);
            // Update the record at key in case of integrity constraint
            // violation.
            $this->fallbackUpdate($insert_values);
            $affected_rows++;
          }
        }
      }
    }
    else {
      // If there are no values, then this is a default-only query. We still
      // need to handle that.
      try {
        $stmt = $this->connection()->prepareStatement($sql, $this->queryOptions, TRUE);
        try {
          $stmt->execute([], $this->queryOptions);
          $affected_rows = $stmt->rowCount();
        }
        catch (\Exception $e) {
          $this->connection()->exceptionHandler()->handleExecutionException($e, $stmt, [], $this->queryOptions);
        }
      }
      catch (IntegrityConstraintViolationException $e) {
        // Update the record at key in case of integrity constraint
        // violation.
        if (!$this->connection()->getDbalExtension()->hasNativeUpsert()) {
          $this->fallbackUpdate([]);
          $affected_rows = 1;
        }
      }
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return $affected_rows;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $comments = $this->connection()->makeComment($this->comments);

    // Delegate to DBAL extension.
    if ($this->connection()->getDbalExtension()->hasNativeUpsert()) {
      $insert_fields = array_merge($this->defaultFields, $this->insertFields);
      $insert_values = $this->getInsertPlaceholderFragment($this->insertValues, $this->defaultFields);
      return $this->connection()->getDbalExtension()->delegateUpsertSql($this->table, $this->key, $insert_fields, $insert_values, $comments);
    }

    // Use DBAL query builder to prepare an INSERT query. Need to pass the
    // quoted table name here.
    $dbal_query = $this->connection()->getDbalConnection()->createQueryBuilder()->insert($this->connection()->getPrefixedTableName($this->table, TRUE));

    foreach ($this->defaultFields as $field) {
      $dbal_query->setValue($this->connection()->getDbalExtension()->getDbFieldName($field, TRUE), 'DEFAULT');
    }
    $max_placeholder = 0;
    foreach ($this->insertFields as $field) {
      $dbal_query->setValue($this->connection()->getDbalExtension()->getDbFieldName($field, TRUE), ':db_insert_placeholder_' . $max_placeholder++);
    }
    return $comments . $dbal_query->getSQL();
  }

  /**
   * Executes an UPDATE when the INSERT fails.
   *
   * @param array $insert_values
   *   The values that failed insert, and that need instead to update the
   *   record identified by the unique key.
   */
  private function fallbackUpdate(array $insert_values): void {
//global $xxx; if ($xxx) dump(['upsert:fallbackUpdate:1']);
    // Use the DBAL query builder for the UPDATE. Need to pass the quoted table
    // name here.
    $dbal_query = $this->connection()->getDbalConnection()->createQueryBuilder()->update($this->connection()->getPrefixedTableName($this->table, TRUE));

    // Set default fields first.
    foreach ($this->defaultFields as $field) {
      $dbal_query->set($this->connection()->getDbalExtension()->getDbFieldName($field), 'DEFAULT');
    }

    // Set values fields.
    for ($i = 0; $i < count($this->insertFields); $i++) {
      if ($this->insertFields[$i] != $this->key) {
        // Updating the unique / primary key is not necessary.
        $dbal_query
          ->set($this->connection()->getDbalExtension()->getDbFieldName($this->insertFields[$i], TRUE), ':db_update_placeholder_' . $i)
          ->setParameter('db_update_placeholder_' . $i, $insert_values[$i]);
      }
      else {
        // The unique / primary key is the WHERE condition for the UPDATE.
        $dbal_query
          ->where($dbal_query->expr()->eq($this->connection()->getDbalExtension()->getDbFieldName($this->insertFields[$i], TRUE), ':db_condition_placeholder_0'))
          ->setParameter('db_condition_placeholder_0', $insert_values[$i]);
      }
    }

    $dbal_query->executeStatement();
  }

}
