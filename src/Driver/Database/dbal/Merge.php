<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Merge as QueryMerge;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Merge.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Merge extends QueryMerge {

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $connection, string $table, array $options = []) {
global $xxx; if ($xxx) dump(['merge:__construct', $table, $options]);
    // @todo Remove the __construct in Drupal 11.
    // @see https://www.drupal.org/project/drupal/issues/3256524
    parent::__construct($connection, $table, $options);
    unset($this->queryOptions['return']);
  }

  public function execute() {

    $select = $this->connection->select($this->conditionTable)
      ->condition($this->condition);
    $select->addExpression('1');
global $xxx; if ($xxx) dump(['merge:execute:select', $this->select, (string) $this->select]);

    if (!$select->execute()->fetchField()) {
      try {
        $insert = $this->connection->insert($this->table)->fields($this->insertFields);
        if ($this->defaultFields) {
          $insert->useDefaults($this->defaultFields);
        }
        $insert->execute();
        return self::STATUS_INSERT;
      }
      catch (IntegrityConstraintViolationException $e) {
        // The insert query failed, maybe it's because a racing insert query
        // beat us in inserting the same row. Retry the select query, if it
        // returns a row, ignore the error and continue with the update
        // query below.
        if (!$select->execute()->fetchField()) {
          throw $e;
        }
      }
    }

    if ($this->needsUpdate) {
      $update = $this->connection->update($this->table)
        ->fields($this->updateFields)
        ->condition($this->condition);
      if ($this->expressionFields) {
        foreach ($this->expressionFields as $field => $data) {
          $update->expression($field, $data['expression'], $data['arguments']);
        }
      }
      $update->execute();
      return self::STATUS_UPDATE;
    }
    return NULL;
  }
}
