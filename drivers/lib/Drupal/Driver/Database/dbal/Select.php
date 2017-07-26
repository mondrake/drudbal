<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Select as QuerySelect;
use Drupal\Core\Database\Query\SelectInterface;

// @todo DBAL 2.6.0:
// Is there a way to specify SELECT DISTINCT??

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
   * {@inheritdoc}
   */
  public function __toString() {
    $dbal_extension = $this->connection->getDbalExtension();

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
    $dbal_query = $dbal_connection->createQueryBuilder();

    // FIELDS and EXPRESSIONS
    $fields = [];
    foreach ($this->tables as $alias => $table) {
      if (!empty($table['all_fields'])) {
        $dbal_query->addSelect($dbal_extension->getDbAlias($this->connection->escapeTable($alias)) . '.*');
      }
    }
    foreach ($this->fields as $field) {
      $field_prefix = isset($field['table']) ? $dbal_extension->getDbAlias($this->connection->escapeTable($field['table'])) . '.' : '';
      $escaped_field_field = $this->connection->escapeField($dbal_extension->getDbFieldName($field['field']));
      $escaped_field_alias = $this->connection->escapeAlias($dbal_extension->getDbFieldName($field['alias']));
      $dbal_query->addSelect($field_prefix . $escaped_field_field . ' AS ' . $escaped_field_alias);
    }
    foreach ($this->expressions as $expression) {
      $dbal_query->addSelect($expression['expression'] . ' AS ' . $this->connection->escapeAlias($expression['alias']));
    }

    // FROM - We presume all queries have a FROM, as any query that doesn't
    // won't need the query builder anyway.
    $root_alias = NULL;
    foreach ($this->tables as $table) {
      $escaped_alias = $dbal_extension->getDbAlias($this->connection->escapeTable($table['alias']));

      // If the table is a subquery, compile it and integrate it into this
      // query.
      if ($table['table'] instanceof SelectInterface) {
        // Run preparation steps on this sub-query before converting to string.
        $subquery = $table['table'];
        $subquery->preExecute();
        $escaped_table = '(' . (string) $subquery . ')';
      }
      else {
        // Do not attempt prefixing cross database / schema queries.
        if (strpos($table['table'], '.') === FALSE) {
          $escaped_table = $this->connection->getPrefixedTableName($this->connection->escapeTable($table['table']));
        }
        else {
          $escaped_table = $table['table'];
        }
      }

      if (!isset($table['join type'])) {
        $dbal_query->from($escaped_table, $escaped_alias);
        $root_alias = $escaped_alias;
      }
      else {
        switch (trim($table['join type'])) {
          case 'INNER':
            $dbal_query->innerJoin($root_alias, $escaped_table, $escaped_alias, $dbal_extension->resolveAliases((string) $table['condition']));
            break;

          case 'LEFT OUTER':
          case 'LEFT':
            $dbal_query->leftJoin($root_alias, $escaped_table, $escaped_alias, $dbal_extension->resolveAliases((string) $table['condition']));
            break;

          case 'RIGHT OUTER':
          case 'RIGHT':
            $dbal_query->rightJoin($root_alias, $escaped_table, $escaped_alias, $dbal_extension->resolveAliases((string) $table['condition']));
            break;

        }
      }
    }

    // WHERE
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->condition)) {
      $dbal_query->where($dbal_extension->resolveAliases((string) $this->condition));
    }

    // GROUP BY
    if ($this->group) {
      foreach ($this->group as $expression) {
        $dbal_query->addGroupBy($dbal_extension->resolveAliases($expression));
      }
    }

    // HAVING
    // @todo this uses Drupal Condition API. Use DBAL expressions instead?
    if (count($this->having)) {
      $dbal_query->having($dbal_extension->resolveAliases((string) $this->having));
    }

    // UNION is not supported by DBAL. Need to delegate to the DBAL extension.

    // ORDER BY
    if ($this->order) {
      foreach ($this->order as $field => $direction) {
        $dbal_query->addOrderBy($dbal_extension->resolveAliases($this->connection->escapeField($field)), $direction);
      }
    }

    // RANGE
    if (!empty($this->range)) {
      $dbal_query
        ->setFirstResult((int) $this->range['start'])
        ->setMaxResults((int) $this->range['length']);
    }

    $sql = $dbal_query->getSQL();

    // DISTINCT @todo move to extension
    if ($this->distinct) {
      $sql = preg_replace('/SELECT /', '$0DISTINCT ', $sql);  // @todo enforce only at the beginning of the string
    }

    // UNION @todo move to extension
    // There can be an 'ORDER BY' or a 'LIMIT' clause at the end of the SQL
    // string at this point. We need to insert the UNION clauses before
    // that part, or just append at the end of the string.
    if ($this->union) {
      if (($offset = strrpos($sql, ' ORDER BY ')) !== FALSE) {
        $pre = substr($sql, 0, $offset);
        $post = substr($sql, $offset, strlen($sql) - $offset);
      }
      elseif (($offset = strrpos($sql, ' LIMIT ')) !== FALSE) {
        $pre = substr($sql, 0, $offset);
        $post = substr($sql, $offset, strlen($sql) - $offset);
      }
      else {
        $pre = $sql;
        $post = '';
      }
      foreach ($this->union as $union) {
        $pre .= ' ' . $union['type'] . ' ' . (string) $union['query'];
      }
      $sql = $pre . $post;
    }

    // FOR UPDATE @todo move to extension
    if ($this->forUpdate) {
      $sql .= ' FOR UPDATE';
    }

    return $comments . $sql;
  }

}
