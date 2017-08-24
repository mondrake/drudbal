<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Timer;
use Drupal\Component\Uuid\Php as Uuid;
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
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;

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
    'check',
    'cluster',
    'comment',
    'compress',
    'current',
    'date',
    'file',
    'increment',
    'initial',
    'level',
    'lock',
    'mode',
    'offset',
    'option',
    'pctfree',
    'public',
    'range',
    'raw',
    'resource',
    'row',
    'rowid',
    'rownum',
    'rows',
    'session',
    'size',
    'start',
    'successful',
    'table',
    'uid',
    'user',
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

  /**
   * Database asset name resolution methods.
   */

  protected function getLimitedIdentifier(string $identifier, int $max_length = 30): string {
    if (strlen($identifier) > $max_length) {
      $identifier_crc = hash('crc32b', $identifier);
      return substr($identifier, 0, $max_length - 8) . $identifier_crc;
    }
    return $identifier;
  }

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
  public function getDbFullQualifiedTableName($drupal_table_name) {
    $options = $this->connection->getConnectionOptions();
    $prefix = $this->connection->tablePrefix($drupal_table_name);
    return $options['username'] . '.' . $this->getDbTableName($prefix . $drupal_table_name);
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
  public function resolveAliases(?string $unaliased): string {
    return $unaliased ? strtr($unaliased, array_flip($this->dbIdentifiersMap)) : '';
  }

  /**
   * {@inheritdoc}
   */
  public function getDbIndexName($context, DbalSchema $dbal_schema, $drupal_table_name, $index_name, array $table_prefix_info) {
    // If checking for index existence or dropping, see if an index exists
    // with the Drupal name, regardless of prefix. It may be a table was
    // renamed so the prefix is no longer relevant.
    if (in_array($context, ['indexExists', 'dropIndex'])) {
      $dbal_table = $dbal_schema->getTable($this->tableName($drupal_table_name));
      foreach ($dbal_table->getIndexes() as $index) {
        $index_full_name = $index->getName();
        $matches = [];
        if (preg_match('/.*____(.+)/', $index_full_name, $matches)) {
          if ($matches[1] === hash('crc32b', $index_name)) {
            return strtolower($index_full_name);
          }
        }
      }
      return FALSE;
    }
    else {
      // To keep things... short, use a CRC32 hash of a UUID and one of the
      // Drupal index name as the db name of the index.
      $uuid = new Uuid();
      return 'IDX_' . hash('crc32b', $uuid->generate()) . '____' . hash('crc32b', $index_name);
    }
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    // @todo check if not passed yet
    $dbal_connection_options['charset'] = 'AL32UTF8';
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
    $dbal_connection->exec('ALTER SESSION SET NLS_LENGTH_SEMANTICS=CHAR');
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
$exc_class = get_class($e);
if ($exc_class !== 'Doctrine\\DBAL\\Exception\\TableNotFoundException' && $this->getDebugging()) {
  $backtrace = debug_backtrace();
  error_log("\n***** Exception    : " . $exc_class);
  error_log('***** Message      : ' . $message);
  error_log('***** getCode      : ' . $e->getCode());
  error_log('***** getErrorCode : ' . $e->getErrorCode());
  error_log('***** getSQLState  : ' . $e->getSQLState());
  error_log('***** Query        : ' . $query);
  error_log('***** Query args   : ' . var_export($args, TRUE));
  error_log("***** Backtrace    : \n" . $this->formatBacktrace($backtrace));
}
    if ($e instanceof UniqueConstraintViolationException) {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e instanceof NotNullConstraintViolationException) {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e instanceof DbalDriverException) {
      switch ($e->getErrorCode()) {
        // ORA-01407 cannot update (string) to NULL.
        case 1407:
          throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);

        // ORA-00900: invalid SQL statement.
        case 900:
          throw new DatabaseExceptionWrapper($message, 0, $e);

        default:
          throw new DatabaseExceptionWrapper($message, 0, $e);

      }
    }
    else {
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
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function alterStatement(&$query, array &$args) {
if ($this->getDebugging()) error_log('pre-alter: ' . $query . ' : ' . var_export($args, TRUE));

    // Modify placeholders and statement in case of placeholders with
    // reserved keywords or exceeding Oracle limits, and for empty strings.
    if (count($args)) {
      $temp_args = [];
      foreach ($args as $placeholder => $value) {
if ($this->getDebugging()) error_log('temp_pl: ' . $temp_pl);
if ($this->getDebugging()) error_log('temp_pl_short: ' . $temp_pl_short);
        $temp_pl = ltrim($placeholder, ':');
        $temp_pl_short = $this->getLimitedIdentifier($temp_pl);
        $key = $placeholder;
        if (in_array($temp_pl, static::$oracleKeywords, TRUE)) {
          $key = $placeholder . '____oracle';
          $query = str_replace($placeholder, $key, $query);
        }
        elseif ($temp_pl !== $temp_pl_short) {
          $key = ':' . $temp_pl_short;
          $query = str_replace($placeholder, $key, $query);
        }
        $temp_args[$key] = $value === '' ? self::ORACLE_EMPTY_STRING_REPLACEMENT : $value;  // @todo here check
      }
      $args = $temp_args;
    }

    // Enclose any identifier that is a reserved keyword for Oracle in double
    // quotes.
    $query = preg_replace('/([\s\.(])(' . $this->oracleKeywordTokens . ')([\s,)])/', '$1"$2"$3', $query);

    // RAND() is not available in Oracle; convert to using
    // DBMS_RANDOM.VALUE function.
    $query = str_replace('RAND()', 'DBMS_RANDOM.VALUE', $query);

    // REGEXP is not available in Oracle; convert to using REGEXP_LIKE
    // function.
    $query = preg_replace('/([^\s]+)\s+REGEXP\s+([^\s]+)/', 'REGEXP_LIKE($1, $2)', $query);

    // CONCAT_WS is not available in Oracle; convert to using || operator.
    $matches = [];
    if (preg_match_all('/(?:[\s\(])(CONCAT_WS\(([^\)]*)\))/', $query, $matches, PREG_OFFSET_CAPTURE)) {
      $concat_ws_replacements = [];
      foreach ($matches[2] as $match) {
        $concat_ws_parms_matches = [];
        preg_match_all('/(\'(?:\\\\\\\\)+\'|\'(?:[^\'\\\\]|\\\\\'?|\'\')*\')|([^\'"\s,]+)/', $match[0], $concat_ws_parms_matches);
        $parms = $concat_ws_parms_matches[0];
        $sep = $parms[0];
        $repl = '';
        for ($i = 1, $first = FALSE; $i < count($parms); $i++) {
          if ($parms[$i] === 'NULL') { // @todo check case insensitive
            continue;
          }
          if (array_key_exists($parms[$i], $args) && $args[$parms[$i]] === NULL) {
            unset($args[$parms[$i]]);
            continue;
          }
          if (array_key_exists($parms[$i], $args) && $args[$parms[$i]] === self::ORACLE_EMPTY_STRING_REPLACEMENT) {
            $args[$parms[$i]] = '';
          }
          if ($first) {
            $repl .= ' || ' . $sep . ' || ';
          }
          $repl .= $parms[$i];
          $first = TRUE;
        }
        $concat_ws_replacements[] = "($repl)";
      }
      for ($i = count($concat_ws_replacements) - 1; $i >= 0; $i--) {
        $query = substr_replace($query, $concat_ws_replacements[$i], $matches[1][$i][1], strlen($matches[1][$i][0]));
      }
    };

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

/*    $sql = 'create or replace type vargs as table of varchar2(32767);';
    $this->getDbalConnection()->exec($sql);

    $sql = <<<SQL
create or replace function CONCAT_WS(separator varchar2,args vargs) return varchar2

is
    str         varchar2(32767);            -- output string
    arg         pls_integer := 1;           -- argument counter

begin
    while arg <= args.count loop
        str := str || args(arg) || separator;
        arg := arg + 1;
    end loop;
    return str;

end CONCAT_WS;
SQL;
    $this->getDbalConnection()->exec($sql);
*/
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
    return $string;
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    // Explicitly escape single quotes in default value.
    $matches = [];
    preg_match_all('/(.+ DEFAULT \')(.+)(\'.*)/', $dbal_column_definition, $matches, PREG_SET_ORDER, 0);
    if (!empty($matches)) {
      $parts = $matches[0];
      $dbal_column_definition = $parts[1] . str_replace("'", "''", $parts[2]) . $parts[3];
    }

    // @todo just setting 'unsigned' to true does not enforce values >=0 in the
    // field in Oracle, so add a CHECK >= 0 constraint.
    // @todo open a DBAL issue, this is also in SQLite
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], [
      'float', 'numeric', 'serial', 'int',
    ]) && !empty($drupal_field_specs['unsigned']) && (bool) $drupal_field_specs['unsigned'] === TRUE) {
      $dbal_column_definition .= ' CHECK (' . $this->getDbFieldName($field_name) . '>= 0)';
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    $current_schema = $dbal_schema;
    $to_schema = clone $current_schema;
    $dbal_table = $to_schema->getTable($this->tableName($drupal_table_name));
    $dbal_column = $dbal_table->getColumn($field_name); // @todo getdbfieldname

    $change_nullability = TRUE;
    if (array_key_exists('not null', $drupal_field_new_specs) && $drupal_field_new_specs['not null'] == $dbal_column->getNotnull()) {
      $change_nullability = FALSE;
    }

    $sql = "ALTER TABLE " . $this->tableName($drupal_table_name) . " MODIFY ($field_name NUMBER(10) ";
    if ($change_nullability) {
      $sql .= $drupal_field_new_specs['not null'] ? 'NOT NULL' : 'NULL';
    }
    $sql .= ")";
    $this->connection->query($sql);


//    $info = $this->getTableSerialInfo($table);

//    if (!empty($info->sequence_name) && $this->oid($field, FALSE, FALSE) == $info->field_name) {
//      $this->failsafeDdl('DROP TRIGGER {' . $info->trigger_name . '}');
//      $this->failsafeDdl('DROP SEQUENCE {' . $info->sequence_name . '}');
//    }

/*    $this->connection->query("ALTER TABLE " . $this->oid($table, TRUE) . " RENAME COLUMN ". $this->oid($field) . " TO " . $this->oid($field . '_old'));
    $not_null = isset($spec['not null']) ? $spec['not null'] : FALSE;
    unset($spec['not null']);

    if (!array_key_exists('size', $spec)) {
      $spec['size'] = 'normal';
    }

    $this->addField($table, (string) $field_new, $spec);

    $map = $this->getFieldTypeMap();
    $this->connection->query("UPDATE " . $this->oid($table, TRUE) . " SET ". $this->oid($field_new) . " = " . $this->oid($field . '_old'));

    if ($not_null) {
      $this->connection->query("ALTER TABLE " . $this->oid($table, TRUE) . " MODIFY (". $this->oid($field_new) . " NOT NULL)");
    }

    $this->dropField($table, $field . '_old');

    if (isset($new_keys)) {
      $this->createKeys($table, $new_keys);
    }

    if (!empty($info->sequence_name) && $this->oid($field, FALSE, FALSE) == $info->field_name) {
      $statements = $this->createSerialSql($table, $this->oid($field_new, FALSE, FALSE), $info->sequence_restart);
      foreach ($statements as $statement) {
        $this->connection->query($statement);
      }
    }

    $this->cleanUpSchema($table);*/
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name, $default) {
    if (is_null($default)) {
      $default = 'NULL';
    }
    else {
      $default = is_string($default) ? "'$default'" : $default;   // @todo proper quoting
    }
    $this->connection->query('ALTER TABLE {' . $drupal_table_name . '} MODIFY (' . $this->getDbFieldName($field_name) . ' DEFAULT ' . $default . ')');
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetNoDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name) {
    $this->connection->query('ALTER TABLE {' . $drupal_table_name . '} MODIFY (' . $this->getDbFieldName($field_name) . ' DEFAULT NULL)');
    return TRUE;
  }

}
