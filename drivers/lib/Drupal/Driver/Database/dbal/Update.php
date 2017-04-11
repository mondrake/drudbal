<?php

namespace Drupal\Driver\Database\dbal;

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
   * Executes the UPDATE query.
   *
   * @return
   *   The number of rows matched by the update query. This includes rows that
   *   actually didn't have to be updated because the values didn't change.
   */
  public function execute() {
//    return parent::execute();





    // Create a sanitized comment string to prepend to the query.
    $comments = $this->connection->makeComment($this->comments);
// @todo comments? any test?
// @todo what to do with $this->queryOptions??
// @todo what shall we return here??

//kint($this);

    $prefixed_table = $this->connection->getDbalExtension()->pfxTable($this->table);
    $dbal_connection = $this->connection->getDbalConnection();
    $dbal_query_builder = $dbal_connection->createQueryBuilder();
    $dbal_query = $dbal_query_builder->update($prefixed_table);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    foreach ($this->expressionFields as $field => $data) {
      if ($data['expression'] instanceof SelectInterface) {
        // Compile and cast expression subquery to a string.
        $data['expression']->compile($this->connection, $this);
        $dbal_query->set($field, '(' . $data['expression'] . ')');
      }
      else {
        $dbal_query->set($field, $data['expression']);
      }
      unset($fields[$field]);
    }

    // Add fields to update to a given value.
    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $dbal_query
        ->set($field, ':db_update_placeholder_' . ($max_placeholder))
        ->setParameter('db_update_placeholder_' . ($max_placeholder), $value);
      $max_placeholder++;
    }

    // Adds a WHERE clause if necessary.
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $dbal_query->where((string) $this->condition);
      foreach ($this->condition->arguments() as $placeholder => $value) {
        $dbal_query->setParameter($placeholder, $value);
      }
    }

/*if (in_array($this->table, ['test', 'test_null', 'test_task', 'mondrake_test', 'test_special_columns'])) {
  debug('***DBAL: ' . var_export($dbal_query->getParameters(), TRUE));
  debug('***DBAL: ' . $dbal_query->getSQL());
}*/
//    return $this->connection->query($dbal_query->getSQL(), $dbal_query->getParameters(), $this->queryOptions);
    return parent::execute();
  }

}
