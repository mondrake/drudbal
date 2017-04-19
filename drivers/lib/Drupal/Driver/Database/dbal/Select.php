<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Select as QuerySelect;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Select.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Select extends QuerySelect {

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
    // If validation fails, simply return NULL.
    // Note that validation routines in preExecute() may throw exceptions instead.
    if (!$this->preExecute()) {
      return NULL;
    }

    $args = $this->getArguments();
    return $this->connection->query((string) $this, $args, $this->queryOptions);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    $query = parent::__toString();

foreach($this->tables as $table) {
  if ($this->startsWith($table['table'], 'test')) {
//debug($query);
    // For convenience, we compile the query ourselves if the caller forgot
    // to do it. This allows constructs like "(string) $query" to work. When
    // the query will be executed, it will be recompiled using the proper
    // placeholder generator anyway.
    if (!$this->compiled()) {
      $this->compile($this->connection, $this);
    }

    // Create a sanitized comment string to prepend to the query.
    $comments = $this->connection->makeComment($this->comments);

    // Use DBAL query builder to prepare the SELECT query.
    $dbal_connection = $this->connection->getDbalConnection();
    $this->dbalQuery = $dbal_connection->createQueryBuilder();

    // FIELDS and EXPRESSIONS
    $fields = [];
    foreach ($this->tables as $alias => $table) {
      if (!empty($table['all_fields'])) {
        $this->dbalQuery->addSelect($this->connection->escapeTable($alias) . '.*');
      }
    }
    foreach ($this->fields as $field) {
      // Always use the AS keyword for field aliases, as some
      // databases require it (e.g., PostgreSQL).
      $this->dbalQuery->addSelect((isset($field['table']) ? $this->connection->escapeTable($field['table']) . '.' : '') . $this->connection->escapeField($field['field']) . ' AS ' . $this->connection->escapeAlias($field['alias']));
    }
    foreach ($this->expressions as $expression) {
      $this->dbalQuery->addSelect($expression['expression'] . ' AS ' . $this->connection->escapeAlias($expression['alias']));
    }

    // FROM - We presume all queries have a FROM, as any query that doesn't won't need the query builder anyway.
//    $query .= "\nFROM ";
    foreach ($this->tables as $table) {
//      $query .= "\n";
      if (isset($table['join type'])) {
//        $query .= $table['join type'] . ' JOIN ';
      }

      // If the table is a subquery, compile it and integrate it into this query.
      if ($table['table'] instanceof SelectInterface) {
        // Run preparation steps on this sub-query before converting to string.
        $subquery = $table['table'];
        $subquery->preExecute();
        $table_string = '(' . (string) $subquery . ')';
        if (!isset($table['join type'])) {
          $this->dbalQuery->from($table_string, $this->connection->escapeTable($table['alias']));
        }
        else {
          // @todo
        }
      }
      else {
//debug($table['table']);
        $table_string = $this->connection->escapeTable($table['table']);
        // Do not attempt prefixing cross database / schema queries.
        if (strpos($table_string, '.') === FALSE) {
          if (!isset($table['join type'])) {
            $this->dbalQuery->from($this->connection->getPrefixedTableName($table_string), $this->connection->escapeTable($table['alias']));
          }
          else {
            // @todo
          }
//          $table_string = '{' . $table_string . '}';
        }
      }

      // Don't use the AS keyword for table aliases, as some
      // databases don't support it (e.g., Oracle).
//      $query .= $table_string . ' ' . $this->connection->escapeTable($table['alias']);

      if (!empty($table['condition'])) {
//        $query .= ' ON ' . (string) $table['condition'];
      }
    }

    // WHERE
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->condition)) {
      $this->dbalQuery->where((string) $this->condition);
//      foreach ($this->condition->arguments() as $placeholder => $value) {
//        $this->dbalQuery->setParameter($placeholder, $value);
//      }
    }

    $sql = $this->dbalQuery->getSQL();

    // DISTINCT @todo move to extension
    if ($this->distinct) {
      $sql = preg_replace('/SELECT /', '$0 DISTINCT ', $sql);  // @todo enforce only at the beginning of the string
    }

//debug($comments . $sql);
    if (!$this->group && !count($this->having) && !$this->union && !$this->order && empty($this->range) && !$this->forUpdate) {
      return $comments . $this->dbalQuery->getSQL();
    }
  }
}

    return $query;
  }


  protected function startsWith($haystack, $needle)
{
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}

}
