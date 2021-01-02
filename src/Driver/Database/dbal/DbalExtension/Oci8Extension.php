<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Utility\Timer;
use Drupal\Component\Uuid\Php as Uuid;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;

use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;

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

//  protected static $isDebugging = TRUE;

  const ORACLE_EMPTY_STRING_REPLACEMENT = "\010"; // it's the Backspace, dec=8, hex=8, oct=10.

  /**
   * A map of condition operators to SQLite operators.
   *
   * @var array
   */
  protected static $oracleConditionOperatorMap = [
    'LIKE' => ['postfix' => " ESCAPE '\\'"],
    'NOT LIKE' => ['postfix' => " ESCAPE '\\'"],
  ];

  private $tempTables = [];

  /**
   * Map of database identifiers.
   *
   * This array maps actual database identifiers to identifiers longer than 30
   * characters, to allow dealing with Oracle constraint.
   *
   * @var string[]
   */
  private $dbIdentifiersMap = [];

  /**
   * Destructs an Oci8 extension object.
   */
  public function __destruct() {
    foreach ($this->tempTables as $db_table) {
      try {
        $this->dbalConnection->exec("TRUNCATE TABLE $db_table");
        $this->dbalConnection->exec("DROP TABLE $db_table");
      }
      catch (\Exception $e) {
        throw new \RuntimeException("Missing temp table $db_table", $e->getCode(), $e);
      }
    }
    parent::__destruct();
  }

  /**
   * Database asset name resolution methods.
   */

  private function getLimitedIdentifier(string $identifier, int $max_length = 30): string {
    if (strlen($identifier) > $max_length) {
      $identifier_crc = hash('crc32b', $identifier);
      $limited_identifier = substr($identifier, 0, $max_length - 8) . $identifier_crc;
      $this->dbIdentifiersMap[$limited_identifier] = $identifier;
      return $limited_identifier;
    }
    return $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbTableName(string $drupal_prefix, string $drupal_table_name): string {
    $prefixed_table_name = $drupal_prefix . $drupal_table_name;
    // Max length for Oracle is 30 chars, but should be even lower to allow
    // DBAL creating triggers/sequences with table name + suffix.
    if (strlen($prefixed_table_name) > 24) {
      $identifier_crc = hash('crc32b', $prefixed_table_name);
      $prefixed_table_name = substr($prefixed_table_name, 0, 16) . $identifier_crc;
    }
    return $prefixed_table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFullQualifiedTableName($drupal_table_name) {
    $options = $this->connection->getConnectionOptions();
    $prefix = $this->connection->tablePrefix($drupal_table_name);
    return $options['username'] . '."' . $this->getDbTableName($prefix, $drupal_table_name) . '"';
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFieldName($field_name, bool $quoted = TRUE) {
    if ($field_name === NULL || $field_name === '') {
      return '';
    }

    if (strpos($field_name, '.') !== FALSE) {
      [$table_tmp, $field_tmp] = explode('.', $field_name);
      $table = $this->getLimitedIdentifier($table_tmp);
      $field = $this->getLimitedIdentifier($field_tmp);
    }
    else {
      $field = $this->getLimitedIdentifier($field_name);
    }

    $identifier = '';
    if (isset($table)) {
      $identifier .= $quoted ? '"' . $table . '".' : $table . '.';
    }

    $identifier .= $quoted ? '"' . $field . '"' : $field;

    return $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbAlias($alias, bool $quoted = TRUE) {
    if ($alias === NULL || $alias === '') {
      return '';
    }

    if (substr($alias, 0, 1) === '"') {
      return $alias;
    }

    if (strpos($alias, '.') !== FALSE) {
      [$table_tmp, $alias_tmp] = explode('.', $alias);
      $table = $this->getLimitedIdentifier($table_tmp);
      $alias = $this->getLimitedIdentifier($alias_tmp);
    }
    else {
      $alias = $this->getLimitedIdentifier($alias);
    }

    $identifier = '';
    if (isset($table)) {
      $identifier .= $quoted ? '"' . $table . '".' : $table . '.';
    }

    $identifier .= $quoted ? '"' . $alias . '"' : $alias;

    return $identifier;
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
      $dbal_table = $dbal_schema->getTable($this->connection->getPrefixedTableName($drupal_table_name, TRUE));
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
  public function delegateTransactionalDdlSupport(array &$connection_options = []): bool {
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
    $affected = $this->connection->query('UPDATE {sequences} SET [value] = GREATEST([value], :existing_id) + 1', [
      ':existing_id' => $existing_id,
    ], ['return' => Database::RETURN_AFFECTED]);
    if (!$affected) {
      $this->connection->query('INSERT INTO {sequences} ([value]) VALUES (:existing_id + 1)', [
        ':existing_id' => $existing_id,
      ]);
    }
    // The transaction gets committed when the transaction object gets destroyed
    // because it gets out of scope.
    return $this->connection->query('SELECT [value] FROM {sequences}')->fetchField();
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
  public function delegateQueryTemporary($drupal_table_name, $query, array $args = [], array $options = []) {
    // @todo Oracle 18 allows session scoped temporary tables, but until then
    //   we need to store away the table being created and drop it during
    //   destruction.
    $prefixes = $this->connection->getPrefixes();
    $prefixes[$drupal_table_name] = '';
    $this->connection->setPrefixPublic($prefixes);
//    $this->tempTables[$drupal_table_name] = $this->connection->getPrefixedTableName($drupal_table_name, TRUE);
    $this->tempTables[$drupal_table_name] = $this->getLimitedIdentifier($drupal_table_name, 24);
    return $this->connection->query('CREATE GLOBAL TEMPORARY TABLE "' . $this->tempTables[$drupal_table_name] . '" ON COMMIT PRESERVE ROWS AS ' . $query, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    if ($e instanceof DatabaseExceptionWrapper) {
      $e = $e->getPrevious();
    }
    if ($e instanceof UniqueConstraintViolationException) {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e instanceof NotNullConstraintViolationException) {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    elseif ($e instanceof DbalDriverException) {
      switch ($e->getCode()) {
        // ORA-01407 cannot update (string) to NULL.
        case 1407:
          throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);

        // ORA-00900: invalid SQL statement.
        case 900:
          throw new DatabaseExceptionWrapper($message, 0, $e);

        default:
          /*if ($this->getDebugging()) {
            $backtrace = debug_backtrace();
            error_log("\n***** Exception    : " . get_class($e));
            error_log('***** Message      : ' . $message);
            error_log('***** getCode      : ' . $e->getCode());
            error_log('***** getSQLState  : ' . $e->getSQLState());
            error_log('***** Query        : ' . $query);
            error_log('***** Query args   : ' . var_export($args, TRUE));
            error_log("***** Backtrace    : \n" . $this->formatBacktrace($backtrace));
          }*/
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
   * PlatformSql delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date) : string {
    if ($string_date) {
      return $field;
    }

    return "TO_DATE('19700101', 'YYYYMMDD') + (1 / 24 / 60 / 60) * $field";
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFormatSql(string $field, string $format) : string {
    // An array of PHP-to-Oracle date replacement patterns.
    static $replace = [
      'Y' => 'YYYY',
      'y' => 'YY',
      'M' => 'MON',
      'm' => 'MM',
      // No format for Numeric representation of a month, without leading
      // zeros.
      'n' => 'MM',
      'F' => 'MONTH',
      'D' => 'DY',
      'd' => 'DD',
      'l' => 'DAY',
      // No format for Day of the month without leading zeros.
      'j' => 'DD',
      'W' => 'IW',
      'H' => 'HH24',
      'h' => 'HH12',
      'i' => 'MI',
      's' => 'SS',
      'A' => 'AM',
    ];

    $format = strtr($format, $replace);
    return "TO_CHAR($field, '$format')";
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetTimezoneOffset(string $offset) : void {
    // Nothing to do here.
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetFieldTimezoneOffsetSql(string &$field, int $offset) : void {
    // Nothing to do here.
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function alterStatement(&$query, array &$args) {
    if ($this->getDebugging()) dump(['pre-alter', $query, $args]);

    // Modify arguments for empty strings.
    foreach ($args as $placeholder => &$value) {
      $value = $value === '' ? self::ORACLE_EMPTY_STRING_REPLACEMENT : $value;  // @todo here check
    }

    // Replace empty strings.
    $query = str_replace("''", "'" . self::ORACLE_EMPTY_STRING_REPLACEMENT . "'", $query);

    // REGEXP is not available in Oracle; convert to using REGEXP_LIKE
    // function.
    $query = preg_replace('/([^\s]+)\s+NOT REGEXP\s+([^\s]+)/', 'NOT REGEXP_LIKE($1, $2)', $query);
    $query = preg_replace('/([^\s]+)\s+REGEXP\s+([^\s]+)/', 'REGEXP_LIKE($1, $2)', $query);

    // In case of missing from, Oracle requires FROM DUAL.
    if (strpos($query, 'SELECT ') === 0 && strpos($query, ' FROM ') === FALSE) {
      $query .= ' FROM DUAL';
    }

    if ($this->getDebugging()) dump(['post-alter', $query, $args]);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function processFetchedRecord(array $record) : array {
    // Enforce all values are of type 'string'.
    $result = [];
    foreach ($record as $column => $value) {
      $column = strtolower($column);
      if ($column === 'doctrine_rownum') {
        continue;
      }
      if (isset($this->dbIdentifiersMap[$column])) {
        $column = $this->dbIdentifiersMap[$column];
      }
      $result[$column] = $value === self::ORACLE_EMPTY_STRING_REPLACEMENT ? '' : (string) $value;
    }
    return $result;
  }

  /**
   * Insert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getSequenceNameForInsert($drupal_table_name) {
    $table_name = $this->connection->getPrefixedTableName($drupal_table_name);
    return "\"{$table_name}_SEQ\"";
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
  public function runInstallTasks(): array {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    // Install a CONCAT_WS function.
    try {
      $this->dbalConnection->exec(<<<PLSQL
CREATE OR REPLACE FUNCTION CONCAT_WS(p_delim IN VARCHAR2
                                    , p_str1 IN VARCHAR2 DEFAULT NULL
                                    , p_str2 IN VARCHAR2 DEFAULT NULL
                                    , p_str3 IN VARCHAR2 DEFAULT NULL
                                    , p_str4 IN VARCHAR2 DEFAULT NULL
                                    , p_str5 IN VARCHAR2 DEFAULT NULL
                                    , p_str6 IN VARCHAR2 DEFAULT NULL
                                    , p_str7 IN VARCHAR2 DEFAULT NULL
                                    , p_str8 IN VARCHAR2 DEFAULT NULL
                                    , p_str9 IN VARCHAR2 DEFAULT NULL
                                    , p_str10 IN VARCHAR2 DEFAULT NULL
                                    , p_str11 IN VARCHAR2 DEFAULT NULL
                                    , p_str12 IN VARCHAR2 DEFAULT NULL
                                    , p_str13 IN VARCHAR2 DEFAULT NULL
                                    , p_str14 IN VARCHAR2 DEFAULT NULL
                                    , p_str15 IN VARCHAR2 DEFAULT NULL
                                    , p_str16 IN VARCHAR2 DEFAULT NULL
                                    , p_str17 IN VARCHAR2 DEFAULT NULL
                                    , p_str18 IN VARCHAR2 DEFAULT NULL
                                    , p_str19 IN VARCHAR2 DEFAULT NULL
                                    , p_str20 IN VARCHAR2 DEFAULT NULL) RETURN VARCHAR2 IS
    TYPE t_str IS VARRAY (20) OF VARCHAR2(4000);
    l_str_list t_str := t_str(p_str1
        , p_str2
        , p_str3
        , p_str4
        , p_str5
        , p_str6
        , p_str7
        , p_str8
        , p_str9
        , p_str10
        , p_str11
        , p_str12
        , p_str13
        , p_str14
        , p_str15
        , p_str16
        , p_str17
        , p_str18
        , p_str19
        , p_str20);
    i          INTEGER;
    l_result   VARCHAR2(4000);
BEGIN
    FOR i IN l_str_list.FIRST .. l_str_list.LAST
        LOOP
            l_result := l_result
                || CASE
                       WHEN l_str_list(i) IS NOT NULL
                           THEN p_delim
                            END
                || CASE
                       WHEN l_str_list(i) = CHR(8)
                           THEN NULL
                       ELSE l_str_list(i)
                            END;
        END LOOP;
    RETURN LTRIM(l_result, p_delim);
END;
PLSQL);
    }
    catch (\Exception $e) {
      $results['fail'][] = t("Failed installation of the CONCAT_WS function: " . $e->getMessage());
    }

    // Install a GREATEST function.
    try {
      $this->dbalConnection->exec(<<<PLSQL
create or replace function greatest(p1 number, p2 number, p3 number default null)
return number
as
begin
  if p3 is null then
    if p1 > p2 or p2 is null then
     return p1;
    else
     return p2;
    end if;
  else
   return greatest(p1,greatest(p2,p3));
  end if;
end;
PLSQL);
    }
    catch (\Exception $e) {
      $results['fail'][] = t("Failed installation of the GREATEST function: " . $e->getMessage());
    }

    // Install a RAND function.
    try {
      $this->dbalConnection->exec(<<<PLSQL
create or replace function rand
return number
as
begin
  return dbms_random.random;
end;
PLSQL);
    }
    catch (\Exception $e) {
      $results['fail'][] = t("Failed installation of the RAND function: " . $e->getMessage());
    }

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
      $this->getDbalConnection()->query("SELECT 1 FROM " . $this->connection->getPrefixedTableName($drupal_table_name, TRUE) . " WHERE ROWNUM <= 1");
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
    $db_field = $this->getDbFieldName($field_name, TRUE);
    try {
      $this->getDbalConnection()->query("SELECT $db_field FROM " . $this->connection->getPrefixedTableName($drupal_table_name, TRUE) . " WHERE ROWNUM <= 1");
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
  public function delegateColumnNameList(array $columns) {
    $column_names = [];
    foreach ($columns as $dbal_column_name) {
      $column_names[] = trim($dbal_column_name, '"');
    }
    return $column_names;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
    if (isset($drupal_field_specs['type']) && $drupal_field_specs['type'] === 'blob') {
      $dbal_type = 'text';
      return TRUE;
    }
    // Special case when text field need to be indexed, BLOB field will not
    // be indexeable.
    if ($drupal_field_specs['type'] === 'text' && isset($drupal_field_specs['mysql_type']) && $drupal_field_specs['mysql_type'] === 'blob') {
      $dbal_type = 'string';
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
    // Special case when text field need to be indexed, BLOB field will not
    // be indexeable.
    if ($drupal_field_specs['type'] === 'text' && isset($drupal_field_specs['mysql_type']) && $drupal_field_specs['mysql_type'] === 'blob') {
      $dbal_column_options['length'] = 4000;
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
    $dbal_table = $to_schema->getTable($this->connection->getPrefixedTableName($drupal_table_name));
    $dbal_column = $dbal_table->getColumn($field_name); // @todo getdbfieldname

    $change_nullability = TRUE;
    if (array_key_exists('not null', $drupal_field_new_specs) && $drupal_field_new_specs['not null'] == $dbal_column->getNotnull()) {
      $change_nullability = FALSE;
    }

    $sql = "ALTER TABLE " . $this->connection->getPrefixedTableName($drupal_table_name, TRUE) . " MODIFY (\"$field_name\" NUMBER(10) ";
    $sql .= "NOT NULL";
//    if ($change_nullability) {
//      $sql .= array_key_exists('not null', $drupal_field_new_specs) && $drupal_field_new_specs['not null'] ? 'NOT NULL' : 'NULL';
//    }
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

}
