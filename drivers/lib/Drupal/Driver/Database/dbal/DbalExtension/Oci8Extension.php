<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Timer;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;

use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Statement as DbalStatement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Driver specific methods for oci8 (Oracle).
 */
class Oci8Extension extends AbstractExtension {

  protected $isDebugging = FALSE;

  const ORACLE_EMPTY_STRING_REPLACEMENT = "\010";

  /**
   * Replacement for single quote identifiers.
   *
   * @todo DBAL uses single quotes instead of backticks to produce DDL
   * statements. This causes problems if fields defaults or comments have
   * single quotes inside.
   */
  const SINGLE_QUOTE_IDENTIFIER_REPLACEMENT = ']]]]SINGLEQUOTEIDENTIFIERDRUDBAL[[[[';
  const DOUBLE_QUOTE_IDENTIFIER_REPLACEMENT = ']]]]DOUBLEQUOTEIDENTIFIERDRUDBAL[[[[';

  const NULL_INSERT_PLACEHOLDER = ']]]]EXPLICIT_NULL_INSERT_DRUDBAL[[[[';
  const EMPTY_STRING_INSERT_PLACEHOLDER = ']]]]EXPLICIT_EMPTY_STRING_INSERT_DRUDBAL[[[[';

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
    'offset',
  ];

  protected $oracleKeywordTokens;

  /**
   * Map of database identifiers.
   *
   * This array maps actual database identifiers to identifiers longer than 30
   * characters, to allow dealing with Oracle constraint.
   *
   * @var string[]
   */
  protected $dbIdentifiersMap = [];

  /**
   * Constructs an Oci8Extension object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $drudbal_connection
   *   The Drupal database connection object for this extension.
   * @param \Doctrine\DBAL\Connection $dbal_connection
   *   The DBAL connection.
   * @param string $statement_class
   *   The StatementInterface class to be used.
   */
  public function __construct(DruDbalConnection $drudbal_connection, DbalConnection $dbal_connection, $statement_class) {
    parent::__construct($drudbal_connection, $dbal_connection, $statement_class);
    $this->oracleKeywordTokens = implode('|', static::$oracleKeywords);
  }

  public function setDebugging($value) {
    $this->isDebugging = $value;
  }
  public function getDebugging() {
    return $this->isDebugging;
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getDbTableName($prefixed_table_name) {
    // Max lenght for Oracle is 30 chars, but should be even lower to allow
    // DBAL creating triggers/sequences with table name + suffix.
    if (strlen($prefixed_table_name) > 24) {
      $identifier_crc = hash('crc32b', $prefixed_table_name);
      $prefixed_table_name = substr($prefixed_table_name, 0, 16) . $identifier_crc;
    }
    $prefixed_table_name = '"' . $prefixed_table_name . '"';
    return $prefixed_table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFieldName($field_name) {
    // Max lenght for Oracle is 30 chars.
    if (strlen($field_name) > 30) {
      $identifier_crc = hash('crc32b', $field_name);
      $db_field_name = substr($field_name, 0, 22) . $identifier_crc;
      $this->dbIdentifiersMap[$db_field_name] = $field_name;
      return $db_field_name;
    }
    elseif (in_array($field_name, static::$oracleKeywords)) {
      return '"' . $field_name . '"';
    }
    else {
      return $field_name;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDbAlias($alias) {
    // Max lenght for Oracle is 30 chars.
    if (strlen($alias) > 30) {
      $identifier_crc = hash('crc32b', $alias);
      $db_alias = substr($alias, 0, 22) . $identifier_crc;
      $this->dbIdentifiersMap[$db_alias] = $alias;
      return $db_alias;
    }
    elseif (in_array($alias, static::$oracleKeywords)) {
      return '"' . $alias . '"';
    }
    else {
      return $alias;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFullQualifiedTableName($drupal_table_name) {
    $options = $this->connection->getConnectionOptions();
    $prefix = $this->connection->tablePrefix($drupal_table_name);
    return $options['username'] . '.' . $this->getDbTableName($prefix . $drupal_table_name);
  }

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
    // Transactional DDL is not available in Oracle.
    return FALSE;
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
    if ($e instanceof UniqueConstraintViolationException) {
if ($this->getDebugging()) {
  $exc_class = get_class($e);
  $backtrace = debug_backtrace();
  error_log('***** Exception : ' . $exc_class);
  error_log('***** Message   : ' . $message);
  error_log('***** Query     : ' . $query);
  error_log('***** Query args: ' . var_export($args, TRUE));
  error_log("***** Backtrace : \n" . $this->formatBacktrace($backtrace));
}
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    else {
$exc_class = get_class($e);
if ($exc_class !== 'Doctrine\\DBAL\\Exception\\TableNotFoundException' || $this->getDebugging()) {
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
        $key = $placeholder;
        if (in_array($temp_pl, static::$oracleKeywords, TRUE)) {
          $key = $placeholder . '____oracle';
          $query = str_replace($placeholder, $placeholder . '____oracle', $query);
        }
        if (strpos($placeholder, ':db_insert_placeholder_') === 0)  {
          switch ($value) {
            case NULL:
              $value = self::NULL_INSERT_PLACEHOLDER;
              break;

            case '':
              $value = self::EMPTY_STRING_INSERT_PLACEHOLDER;
              break;

          }
        }
        $temp_args[$key] = $value === '' ? self::ORACLE_EMPTY_STRING_REPLACEMENT : $value;  // @todo here check
      }
      $args = $temp_args;
    }

    // Enclose any identifier that is a reserved keyword for Oracle in double
    // quotes.
    $query = preg_replace('/([\s\.(])(' . $this->oracleKeywordTokens . ')([\s,)])/', '$1"$2"$3', $query);

    // RAND() is not available in Oracle.
    $query = str_replace('RAND()', 'DBMS_RANDOM.VALUE', $query);

    // REGEXP is not available in Oracle. @todo refine
    $query = preg_replace('/(.*\s+)(.*)(\s+REGEXP\s+)(.*)/', '$1 REGEXP_LIKE($2, $4)', $query);

    // In case of missing from, Oracle requires FROM DUAL.
    if (strpos($query, 'SELECT ') === 0 && strpos($query, ' FROM ') === FALSE) {
      $query .= ' FROM DUAL';
    }

if ($this->getDebugging()) error_log($query . ' : ' . var_export($args, TRUE));
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
          if ($column === 'doctrine_rownum') {
            continue;
          }
          if (isset($this->dbIdentifiersMap[$column])) {
            $column = $this->dbIdentifiersMap[$column];
          }
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
            if ($column === 'doctrine_rownum') {
              continue;
            }
            if (isset($this->dbIdentifiersMap[$column])) {
              $column = $this->dbIdentifiersMap[$column];
            }
            $ret->$column = $value === self::ORACLE_EMPTY_STRING_REPLACEMENT ? '' : (string) $value;
          }
          return $ret;

        case \PDO::FETCH_CLASS:
          $ret = new $fetch_class();
          foreach ($row as $column => $value) {
            $column = strtolower($column);
            if ($column === 'doctrine_rownum') {
              continue;
            }
            if (isset($this->dbIdentifiersMap[$column])) {
              $column = $this->dbIdentifiersMap[$column];
            }
            $ret->$column = $value === self::ORACLE_EMPTY_STRING_REPLACEMENT ? '' : (string) $value;
          }
          return $ret;

        default:
          throw new MysqliException("Unknown fetch type '{$mode}'");
      }
    }
  }

  /**
   * Insert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getSequenceNameForInsert($drupal_table_name) {
    $table_name = $this->tableName($drupal_table_name);
    if (substr($table_name, 0, 1) === '"') {
      return '"' . rtrim(ltrim($table_name, '"'), '"') . '_SEQ"';
    }
    else {
      return $table_name . '_SEQ';
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
  public function delegateTableExists(&$result, $drupal_table_name) {
    // The DBAL Schema manager is quite slow here.
    // Instead, we try to select from the table in question.  If it fails,
    // the most likely reason is that it does not exist.
    try {
      $this->getDbalConnection()->query("SELECT 1 FROM " . $this->tableName($drupal_table_name) . " WHERE ROWNUM <= 1");
      $result = TRUE;
    }
    catch (\Exception $e) {
      $result = FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldExists(&$result, $drupal_table_name, $field_name) {
    // The DBAL Schema manager is quite slow here.
    // Instead, we try to select from the table and field in question. If it
    // fails, the most likely reason is that it does not exist.
    try {
      $this->getDbalConnection()->query("SELECT $field_name FROM " . $this->tableName($drupal_table_name) . " WHERE ROWNUM <= 1");
      $result = TRUE;
    }
    catch (\Exception $e) {
      $result = FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateListTableNames() {
    $db_table_names = $this->getDbalConnection()->getSchemaManager()->listTableNames();
    $table_names = [];
    foreach ($db_table_names as $db_table_name) {
      $table_names[] = rtrim(ltrim($db_table_name, '"'), '"');
    }
    return $table_names;
  }

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
    $replace = str_replace('\'', self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, $string);
    // Encode double quotes.
    $replace = str_replace('"', self::DOUBLE_QUOTE_IDENTIFIER_REPLACEMENT, $replace);
    return $replace;
  }

  /**
   * {@inheritdoc}
   */
  public function postCreateTable($drupal_table_name, array $drupal_table_specs, DbalSchema $dbal_schema) {
    $this->rebuildDefaultsTrigger($drupal_table_name, $dbal_schema);
    return $this;
  }

  /**
   * Emulates mysql default column behaviour (eg.
   * insert into table (col1) values (null)
   * if col1 has default in mysql you have the default insterted instead of null.
   * On oracle you have null inserted.
   * So we need a trigger to intercept this condition and substitute null with default...
   * This condition happens on MySQL only inserting not updating.
   */
  public function rebuildDefaultsTrigger($drupal_table_name, $dbal_schema) {
    $table_name = $this->tableName($drupal_table_name);
    $dbal_table = $dbal_schema->getTable($table_name);
    $trigger_name = rtrim(ltrim($table_name, '"'), '"') . '_TRG_DEFS';
    // Max lenght for Oracle is 30 chars, but should be even lower to allow
    // DBAL creating triggers/sequences with table name + suffix.
    if (strlen($trigger_name) > 30) {
      $identifier_crc = hash('crc32b', $trigger_name);
      $trigger_name = substr($trigger_name, 0, 22) . $identifier_crc;
    }

    $trigger_sql = 'CREATE OR REPLACE TRIGGER ' . $trigger_name .
      ' BEFORE INSERT ON ' . $table_name .
      ' FOR EACH ROW BEGIN /* defs trigger */ IF INSERTING THEN ';

    $def = FALSE;
    foreach ($dbal_table->getColumns() as $column) {
      if ($column->getDefault() !== NULL && $column->getDefault() !== "\010") {
        $def = TRUE;
        $column_name = $column->getName();
        if (in_array($column_name, static::$oracleKeywords)) {
          $column_name = '"' . $column_name . '"';
        }
        $type_name = $column->getType()->getName();
/*        if (in_array($type_name, ['smallint', 'integer', 'bigint', 'decimal', 'float'])) {
          $trigger_sql .=
            'IF :NEW.' . $column_name . ' IS NULL OR TO_CHAR(:NEW.' . $column_name . ') = \'\010\'
              THEN :NEW.' . $column_name . ':= ' . $column->getDefault() . ';
             END IF;
            ';
        }
        else {
          $trigger_sql .=
            'IF :NEW.' . $column_name . ' IS NULL OR TO_CHAR(:NEW.' . $column_name . ') = \'\010\'
              THEN :NEW.' . $column_name . ':= \'' . $column->getDefault() . '\';
             END IF;
            ';
        }
      }*/
      $trigger_sql .=
        "IF :NEW.$column_name .  = '" . self::NULL_INSERT_PLACEHOLDER . "'
          THEN :NEW.$column_name := NULL;
         END IF;
        ";
    }

    if (!$def) {
      $trigger_sql .= ' NULL; ';
    }

    $trigger_sql .= 'END IF; END;';
    $this->getDbalConnection()->exec($trigger_sql);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    $sql = 'ALTER TABLE {' . $drupal_table_name . '} MODIFY (' . $field_name . ' ' . $dbal_column_options['columnDefinition'] . ')';
    $this->connection->query($sql);
    return TRUE;
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
