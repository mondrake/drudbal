<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\InvalidQueryException;
use Drupal\Core\Database\Query\Condition as BaseCondition;

/**
 * Generic class for a series of conditions in a query.
 */
class Condition extends BaseCondition {

  /**
   * {@inheritdoc}
   */
  public function condition($field, $value = NULL, $operator = '=') {
    if (empty($operator)) {
      $operator = '=';
    }
    if (empty($value) && is_array($value)) {
      throw new InvalidQueryException(sprintf("Query condition '%s %s ()' cannot be empty.", $field, $operator));
    }

    $this->conditions[] = [
      'field' => $field,
      'value' => $value,
      'operator' => $operator,
    ];

    $this->changed = TRUE;

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function where($snippet, $args = []) {
    $this->conditions[] = [
      'field' => $snippet,
      'value' => $args,
      'operator' => NULL,
    ];
    $this->changed = TRUE;

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isNull($field) {
    return $this->condition($field, NULL, 'IS NULL');
  }

  /**
   * {@inheritdoc}
   */
  public function isNotNull($field) {
    return $this->condition($field, NULL, 'IS NOT NULL');
  }

  /**
   * {@inheritdoc}
   */
  public function exists(SelectInterface $select) {
    return $this->condition('', $select, 'EXISTS');
  }

  /**
   * {@inheritdoc}
   */
  public function notExists(SelectInterface $select) {
    return $this->condition('', $select, 'NOT EXISTS');
  }

  /**
   * {@inheritdoc}
   */
  public function alwaysFalse() {
    return $this->where('1 = 0');
  }

  /**
   * {@inheritdoc}
   */
  public function arguments() {
    // If the caller forgot to call compile() first, refuse to run.
    if ($this->changed) {
      return NULL;
    }
    return $this->arguments;
  }

  /**
   * {@inheritdoc}
   */
  public function compile(Connection $connection, PlaceholderInterface $queryPlaceholder) {
    // Re-compile if this condition changed or if we are compiled against a
    // different query placeholder object.
    if ($this->changed || isset($this->queryPlaceholderIdentifier) && ($this->queryPlaceholderIdentifier != $queryPlaceholder->uniqueIdentifier())) {
      $this->queryPlaceholderIdentifier = $queryPlaceholder->uniqueIdentifier();

      $condition_fragments = [];
      $arguments = [];

      $conditions = $this->conditions;
dump(['compile', $conditions]);

      $conjunction = $conditions['#conjunction'];
      unset($conditions['#conjunction']);
      foreach ($conditions as $condition) {
        // Process field.
        if ($condition['field'] instanceof ConditionInterface) {
          // Left hand part is a structured condition or a subquery. Compile,
          // put brackets around it (if it is a query), and collect any
          // arguments.
          $condition['field']->compile($connection, $queryPlaceholder);
          $field_fragment = (string) $condition['field'];
          if ($condition['field'] instanceof SelectInterface) {
            $field_fragment = '(' . $field_fragment . ')';
          }
          $arguments += $condition['field']->arguments();
          // If the operator and value were not passed in to the
          // @see ConditionInterface::condition() method (and thus have the
          // default value as defined over there) it is assumed to be a valid
          // condition on its own: ignore the operator and value parts.
          $ignore_operator = $condition['operator'] === '=' && $condition['value'] === NULL;
        }
        elseif (!isset($condition['operator'])) {
          // Left hand part is a literal string added with the
          // @see ConditionInterface::where() method. Put brackets around
          // the snippet and collect the arguments from the value part.
          // Also ignore the operator and value parts.
          $field_fragment = '(' . $condition['field'] . ')';
          $arguments += $condition['value'];
          $ignore_operator = TRUE;
        }
        else {
          // Left hand part is a normal field. Add it as is.
          $field_fragment = $connection->escapeField($condition['field']);
          $ignore_operator = FALSE;
        }

        // Process operator.
        if ($ignore_operator) {
          $operator = ['operator' => '', 'use_value' => FALSE];
        }
        else {
          // Remove potentially dangerous characters.
          // If something passed in an invalid character stop early, so we
          // don't rely on a broken SQL statement when we would just replace
          // those characters.
          if (stripos($condition['operator'], 'UNION') !== FALSE || strpbrk($condition['operator'], '[-\'"();') !== FALSE) {
            $this->changed = TRUE;
            $this->arguments = [];
            // Provide a string which will result into an empty query result.
            $this->stringVersion = '( AND 1 = 0 )';

            // Conceptually throwing an exception caused by user input is bad
            // as you result into a WSOD, which depending on your webserver
            // configuration can result into the assumption that your site is
            // broken.
            // On top of that the database API relies on __toString() which
            // does not allow to throw exceptions.
            trigger_error('Invalid characters in query operator: ' . $condition['operator'], E_USER_ERROR);
            return;
          }

          // For simplicity, we convert all operators to a data structure to
          // allow to specify a prefix, a delimiter and such. Find the
          // associated data structure by first doing a database specific
          // lookup, followed by a specification according to the SQL standard.
          $operator = $connection->mapConditionOperator($condition['operator']);
          if (!isset($operator)) {
            $operator = $this->mapConditionOperator($condition['operator']);
          }
          $operator += ['operator' => $condition['operator']];
        }
        // Add defaults.
        $operator += [
          'prefix' => '',
          'postfix' => '',
          'delimiter' => '',
          'use_value' => TRUE,
        ];
        $operator_fragment = $operator['operator'];

        // Process value.
        $value_fragment = '';
        if ($operator['use_value']) {
          // For simplicity, we first convert to an array, so that we can handle
          // the single and multi value cases the same.
          if (!is_array($condition['value'])) {
            if ($condition['value'] instanceof SelectInterface && ($operator['operator'] === 'IN' || $operator['operator'] === 'NOT IN')) {
              // Special case: IN is followed by a single select query instead
              // of a set of values: unset prefix and postfix to prevent double
              // brackets.
              $operator['prefix'] = '';
              $operator['postfix'] = '';
            }
            $condition['value'] = [$condition['value']];
          }
          // Process all individual values.
          $value_fragment = [];
          foreach ($condition['value'] as $value) {
            if ($value instanceof SelectInterface) {
              // Right hand part is a subquery. Compile, put brackets around it
              // and collect any arguments.
              $value->compile($connection, $queryPlaceholder);
              $value_fragment[] = '(' . (string) $value . ')';
              $arguments += $value->arguments();
            }
            else {
              // Right hand part is a normal value. Replace the value with a
              // placeholder and add the value as an argument.
              $placeholder = ':db_condition_placeholder_' . $queryPlaceholder->nextPlaceholder();
              $value_fragment[] = $placeholder;
              $arguments[$placeholder] = $value;
            }
          }
          $value_fragment = $operator['prefix'] . implode($operator['delimiter'], $value_fragment) . $operator['postfix'];
        }

        // Concatenate the left hand part, operator and right hand part.
        $condition_fragments[] = trim(implode(' ', [$field_fragment, $operator_fragment, $value_fragment]));
      }

      // Concatenate all conditions using the conjunction and brackets around
      // the individual conditions to assure the proper evaluation order.
      $this->stringVersion = count($condition_fragments) > 1 ? '(' . implode(") $conjunction (", $condition_fragments) . ')' : implode($condition_fragments);
      $this->arguments = $arguments;
      $this->changed = FALSE;
    }
  }

}
