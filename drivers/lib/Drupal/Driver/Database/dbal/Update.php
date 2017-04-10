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
    return parent::execute();

    
    
    
    
if (!in_array($this->table, ['test', 'test_null', 'test_task', 'mondrake_test', 'test_special_columns'])) return parent::execute();
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
    $update_values = [];
    foreach ($this->expressionFields as $field => $data) {
      if (!empty($data['arguments'])) {
        $update_values += $data['arguments'];
      }
      if ($data['expression'] instanceof SelectInterface) {
        $data['expression']->compile($this->connection, $this);
        $update_values += $data['expression']->arguments();
      }
      unset($fields[$field]);
    }

    // Because we filter $fields the same way here and in __toString(), the
    // placeholders will all match up properly.
    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $dbal_query->set($field, $value);
//        ->set($field, ':db_update_placeholder_' . ($max_placeholder))
//        ->setParameter('db_update_placeholder_' . ($max_placeholder), $value);
      $max_placeholder++;
    }

/*    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $update_values = array_merge($update_values, $this->condition->arguments());
    }

kint($fields);
kint($update_values);
kint($this->condition->conditions());*/
    $max_placeholder = 0;
    $conditions = $this->condition->conditions();
    if (count($conditions) === 2 && $conditions['#conjunction'] === 'AND' && $conditions[0]['operator'] === '=' && !is_object($conditions[0]['field'])) {
      $dbal_query->where($conditions[0]['field'] . ' = ' . $dbal_query_builder->createNamedParameter($conditions[0]['value']));
//        ->where($dbal_query_builder->expr()->eq($conditions[0]['field'], ':db_condition_placeholder_' . ($max_placeholder)))
//        ->setParameter('db_condition_placeholder_' . ($max_placeholder), $conditions[0]['value']);
      $max_placeholder++;
if (in_array($this->table, ['test', 'test_null', 'test_task', 'mondrake_test', 'test_special_columns'])) {//
  debug('***DBAL: ' . var_export($dbal_query->getParameters(), TRUE));
  debug('***DBAL: ' . (string) $dbal_query);
/*var_export($dbal_query->getParameters());
var_export((string) $dbal_query);
$stmt = $dbal_connection->prepare((string) $dbal_query);
var_export(get_class($stmt));
$stmt->execute($dbal_query->getParameters());
var_export(get_class($stmt));
//$result = $stmt->rowCount();*/
//die();
}
      return $dbal_query->execute()->fetchField();
    }

    return parent::execute();
  }

}
