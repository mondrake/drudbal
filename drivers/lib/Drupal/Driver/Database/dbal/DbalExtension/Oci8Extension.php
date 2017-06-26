<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Driver\sqlite\Connection as SqliteConnectionBase;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Statement as DbalStatement;

/**
 * Driver specific methods for oci8 (Oracle).
 */
class Oci8Extension extends AbstractExtension {


  const ORACLE_EMPTY_STRING_REPLACEMENT = "\010";

  /**
   * A map of condition operators to SQLite operators.
   *
   * @var array
   */
  protected static $oracleConditionOperatorMap = [
    'LIKE' => ['postfix' => " ESCAPE '\\'"],
    'NOT LIKE' => ['postfix' => " ESCAPE '\\'"],
  ];

  /**
   * A list of Oracle keywords that collide with Drupal.
   *
   * @var string[]
   */
  protected static $oracleKeywords = [
    'access',
    'start',
    'session',
    'file',
    'size',
    'successful',
    'table',
    'option',
    'check',
    'cluster',
    'initial',
    'pctfree',
    'uid',
    'comment',
    'compress',
    'public',
    'raw',
    'user',
    'current',
    'date',
    'level',
    'resource',
    'lock',
    'row',
    'rowid',
    'rownum',
    'mode',
    'rows',
    'range',
  ];

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionSupport(array &$connection_options = []) {
    return !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionalDdlSupport(array &$connection_options = []) {
    return !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateMapConditionOperator($operator) {
    return isset(static::$oracleConditionOperatorMap[$operator]) ? static::$oracleConditionOperatorMap[$operator] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNextId($existing_id = 0) {
    // @codingStandardsIgnoreLine
    $trn = $this->connection->startTransaction();
    $affected = $this->connection->query('UPDATE {sequences} SET value = GREATEST(value, :existing_id) + 1', [
      ':existing_id' => $existing_id,
    ], ['return' => Database::RETURN_AFFECTED]);
    if (!$affected) {
      $this->connection->query('INSERT INTO {sequences} (value) VALUES (:existing_id + 1)', [
        ':existing_id' => $existing_id,
      ]);
    }
    // The transaction gets committed when the transaction object gets destroyed
    // because it gets out of scope.
    return $this->connection->query('SELECT value FROM {sequences}')->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryRange($query, $from, $count, array $args = [], array $options = []) {
    $limit_query = $this->getDbalConnection()->getDatabasePlatform()->modifyLimitQuery($query, $count, $from);
    return $this->connection->query($limit_query, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    if ($e instanceof DatabaseExceptionWrapper) {
      $e = $e->getPrevious();
    }
    if ($e instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    else {
$exc_class = get_class($e);
if ($exc_class !== 'Doctrine\\DBAL\\Exception\\TableNotFoundException') {
  $backtrace = debug_backtrace();
  error_log('***** Exception : ' . $exc_class);
  error_log('***** Message   : ' . $message);
  error_log('***** Query     : ' . $query);
  error_log('***** Query args: ' . var_export($args, TRUE));
  error_log("***** Backtrace : \n" . $this->formatBacktrace($backtrace));
}
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

  /**
   * Formats a backtrace into a plain-text string.
   *
   * The calls show values for scalar arguments and type names for complex ones.
   *
   * @param array $backtrace
   *   A standard PHP backtrace.
   *
   * @return string
   *   A plain-text line-wrapped string ready to be put inside <pre>.
   */
  public static function formatBacktrace(array $backtrace) {
    $return = '';

    foreach ($backtrace as $trace) {
      $call = ['function' => '', 'args' => []];

      if (isset($trace['class'])) {
        $call['function'] = $trace['class'] . $trace['type'] . $trace['function'];
      }
      elseif (isset($trace['function'])) {
        $call['function'] = $trace['function'];
      }
      else {
        $call['function'] = 'main';
      }

/*      if (isset($trace['args'])) {
        foreach ($trace['args'] as $arg) {
          if (is_scalar($arg)) {
            $call['args'][] = is_string($arg) ? '\'' . $arg . '\'' : $arg;
          }
          else {
            $call['args'][] = ucfirst(gettype($arg));
          }
        }
      }*/

      $line = '';
      if (isset($trace['line'])) {
        $line = " (Line: {$trace['line']})";
      }

      $return .= $call['function'] . '(' . /*implode(', ', $call['args']) .*/ ")$line\n";
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQuoteIdentifier($identifier) {
    $keywords = $this->getDbalConnection()->getDatabasePlatform()->getReservedKeywordsList();
    return $keywords->isKeyword($identifier) ? '"' . $identifier . '"' : $identifier;
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function alterStatement(&$query, array &$args) {
    if (count($args)) {
      $temp_args = [];
      foreach ($args as $placeholder => $value) {
        $temp_pl = ltrim($placeholder, ':');
        if (in_array($temp_pl, static::$oracleKeywords, TRUE)) {
          $key = $placeholder . '____oracle';
          $query = str_replace($placeholder , $placeholder . '____oracle', $query);
        }
        else {
          $key = $placeholder;
        }
        $temp_args[$key] = $value === '' ? self::ORACLE_EMPTY_STRING_REPLACEMENT : $value;  // @todo here check
      }
      $args = $temp_args;
    }


    foreach (static::$oracleKeywords as $keyword) {
      $query = preg_replace('/([\s\.(])(' . $keyword . ')([\s,)])/', '$1"$2"$3', $query);
    }

error_log($query);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFetch(DbalStatement $dbal_statement, $mode, $fetch_class) {
    if ($mode <= \PDO::FETCH_BOTH) {
      $row = $dbal_statement->fetch($mode);
      if (!$row) {
        return FALSE;
      }
      // @todo stringify also FETCH_NUM and FETCH_BOTH
      if ($mode === \PDO::FETCH_ASSOC) {
        $adj_row = [];
        foreach ($row as $column => $value) {
          $column = strtolower($column);
          $adj_row[$column] = $value === self::ORACLE_EMPTY_STRING_REPLACEMENT ? '' : (string) $value;
        }
        $row = $adj_row;
      }
      return $row;
    }
    else {
      $row = $dbal_statement->fetch(\PDO::FETCH_ASSOC);
      if (!$row) {
        return FALSE;
      }
      switch ($mode) {
        case \PDO::FETCH_OBJ:
          $ret = new \stdClass();
          foreach ($row as $column => $value) {
            $column = strtolower($column);
            $ret->$column = $value === self::ORACLE_EMPTY_STRING_REPLACEMENT ? '' : (string) $value;
          }
          return $ret;

        case \PDO::FETCH_CLASS:
          $ret = new $fetch_class();
          foreach ($row as $column => $value) {
            $column = strtolower($column);
            $ret->$column = $value === self::ORACLE_EMPTY_STRING_REPLACEMENT ? '' : (string) $value;
          }
          return $ret;

        default:
          throw new MysqliException("Unknown fetch type '{$mode}'");
      }
    }
  }

  /**
   * Install\Tasks delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function delegateInstallConnectExceptionProcess(\Exception $e) {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function runInstallTasks() {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    return $results;
  }

  /**
   * Schema delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
    if (isset($drupal_field_specs['type']) && $drupal_field_specs['type'] === 'blob') {
      $dbal_type = 'text';
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnOptions($context, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], ['char', 'varchar', 'varchar_ascii', 'text', 'blob'])) {
      if (array_key_exists('default', $drupal_field_specs)) {
        $dbal_column_options['default'] = empty($drupal_field_specs['default']) ? self::ORACLE_EMPTY_STRING_REPLACEMENT : $drupal_field_specs['default'];  // @todo here check
      }
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStringForDefault($string) {
    // Encode single quotes.
    return $string;
  }

  /**
   * {@inheritdoc}
   */
  public function getIndexFullName($context, DbalSchema $dbal_schema, $drupal_table_name, $index_name, array $table_prefix_info) {
    $full_name = $table_prefix_info['table'] . '____' . $index_name;
    if (strlen($full_name) > 30) {
      $identifier_crc = hash('crc32b', $full_name);
      $full_name = substr($full_name, 0, 22) . $identifier_crc;
    }
    return $full_name;
  }

}
