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
      $field_prefix = isset($field['table']) ? $this->connection->escapeTable($field['table']) . '.' : '';
      $this->dbalQuery->addSelect($field_prefix . $this->connection->escapeField($field['field']) . ' AS ' . $this->connection->escapeAlias($field['alias']));
    }
    foreach ($this->expressions as $expression) {
      $this->dbalQuery->addSelect($expression['expression'] . ' AS ' . $this->connection->escapeAlias($expression['alias']));
    }

    // FROM - We presume all queries have a FROM, as any query that doesn't
    // won't need the query builder anyway.
    $root_alias = NULL;
    foreach ($this->tables as $table) {
      // If the table is a subquery, compile it and integrate it into this
      // query.
      $escaped_alias = $this->connection->escapeTable($table['alias']);
      if ($table['table'] instanceof SelectInterface) {
        // Run preparation steps on this sub-query before converting to string.
        $subquery = $table['table'];
        $subquery->preExecute();
        $escaped_table = '(' . (string) $subquery . ')';
        if (!isset($table['join type'])) {
          $this->dbalQuery->from($escaped_table, $escaped_alias);
          $root_alias = $escaped_alias;
        }
        else {
          switch ($table['join type']) {
            case 'INNER':
              $this->dbalQuery->innerJoin($root_alias, $escaped_table, $escaped_alias, (string) $table['condition']);
              break;

            case 'LEFT OUTER':
              $this->dbalQuery->leftJoin($root_alias, $escaped_table, $escaped_alias, (string) $table['condition']);
              break;

            case 'RIGHT OUTER':
              $this->dbalQuery->rightJoin($root_alias, $escaped_table, $escaped_alias, (string) $table['condition']);
              break;

          }
        }
      }
      else {
        $escaped_table = $this->connection->escapeTable($table['table']);
        // Do not attempt prefixing cross database / schema queries.
        if (strpos($escaped_table, '.') === FALSE) {
          if (!isset($table['join type'])) {
            $this->dbalQuery->from($this->connection->getPrefixedTableName($escaped_table), $escaped_alias);
            $root_alias = $escaped_alias;
          }
          else {
            switch ($table['join type']) {
              case 'INNER':
                $this->dbalQuery->innerJoin($root_alias, $this->connection->getPrefixedTableName($escaped_table), $escaped_alias, (string) $table['condition']);
                break;

              case 'LEFT OUTER':
                $this->dbalQuery->leftJoin($root_alias, $this->connection->getPrefixedTableName($escaped_table), $escaped_alias, (string) $table['condition']);
                break;

              case 'RIGHT OUTER':
                $this->dbalQuery->rightJoin($root_alias, $this->connection->getPrefixedTableName($escaped_table), $escaped_alias, (string) $table['condition']);
                break;

            }
          }
        }
      }
    }

    // WHERE
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->condition)) {
      $this->dbalQuery->where((string) $this->condition);
    }

    // GROUP BY
    if ($this->group) {
      foreach ($this->group as $expression) {
        $this->dbalQuery->addGroupBy($expression);
      }
    }

    // HAVING
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->having)) {
      $this->dbalQuery->having((string) $this->having);
    }

    // UNION @todo

    // ORDER BY
    if ($this->order) {
      foreach ($this->order as $field => $direction) {
        $this->dbalQuery->addOrderBy($this->connection->escapeField($field), $direction);
      }
    }

    // RANGE
    if (!empty($this->range)) {
      $this->dbalQuery
        ->setFirstResult((int) $this->range['start'])
        ->setMaxResults((int) $this->range['length']);
    }

    $sql = $this->dbalQuery->getSQL();

    // DISTINCT @todo move to extension
    if ($this->distinct) {
      $sql = preg_replace('/SELECT /', '$0DISTINCT ', $sql);  // @todo enforce only at the beginning of the string
    }

    // FOR UPDATE @todo move to extension
    if ($this->forUpdate) {
      $sql .= ' FOR UPDATE';
    }

    if (!$this->union) {
$this->xxDebug($comments . $sql);
      return $comments . $sql;
    }

    return $query;
  }


  protected function startsWith($haystack, $needle) {
   $length = strlen($needle);
   return (substr($haystack, 0, $length) === $needle);
  }

  protected function xxDebug($output) {
//   debug($output);
  }

}
