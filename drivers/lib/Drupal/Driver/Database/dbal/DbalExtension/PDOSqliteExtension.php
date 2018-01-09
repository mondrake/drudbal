<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Drupal\Component\Uuid\Php as Uuid;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Driver\sqlite\Connection as SqliteConnectionBase;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Statement as DbalStatement;

/**
 * Driver specific methods for pdo_sqlite.
 */
class PDOSqliteExtension extends AbstractExtension {

  /**
   * Minimum required Sqlite version.
   */
  const SQLITE_MINIMUM_VERSION = '3.7.11';

  /**
   * Error code for "Unable to open database file" error.
   */
  const DATABASE_NOT_FOUND = 14;

  /**
   * Replacement for single quote identifiers.
   *
   * @todo DBAL uses single quotes instead of backticks to produce DDL
   * statements. This causes problems if fields defaults or comments have
   * single quotes inside.
   */
  const SINGLE_QUOTE_IDENTIFIER_REPLACEMENT = ']]]]SINGLEQUOTEIDENTIFIERDRUDBAL[[[[';

  /**
   * A map of condition operators to SQLite operators.
   *
   * @var array
   */
  protected static $sqliteConditionOperatorMap = [
    'LIKE' => ['postfix' => " ESCAPE '\\'"],
    'NOT LIKE' => ['postfix' => " ESCAPE '\\'"],
    'LIKE BINARY' => ['postfix' => " ESCAPE '\\'", 'operator' => 'GLOB'],
    'NOT LIKE BINARY' => ['postfix' => " ESCAPE '\\'", 'operator' => 'NOT GLOB'],
  ];

  /**
   * Databases attached to the current database.
   *
   * This is used to allow prefixes to be safely handled without locking the
   * table.
   *
   * @var array
   */
  protected $attachedDatabases = [];

  /**
   * Indicates that at least one table has been dropped during this request.
   *
   * The destructor will only try to get rid of unnecessary databases if there
   * is potential of them being empty.
   *
   * @var bool
   */
  protected $tableDropped = FALSE;

  /**
   * {@inheritdoc}
   */
  public function __construct(DruDbalConnection $drudbal_connection, DbalConnection $dbal_connection, $statement_class) {
    parent::__construct($drudbal_connection, $dbal_connection, $statement_class);

    // Attach additional databases per prefix.
    $connection_options = $drudbal_connection->getConnectionOptions();
    $prefixes = [];
    foreach ($connection_options['prefix'] as $key => $prefix) {
      // Default prefix means query the main database -- no need to attach anything.
      if ($key !== 'default' && !isset($this->attachedDatabases[$prefix])) {
        $this->attachedDatabases[$prefix] = $prefix;
        $dbal_connection->executeQuery('ATTACH DATABASE ? AS ?', [$connection_options['database'] . '-' . $prefix, $prefix]);
      }
      $prefixes[$key] = $prefix;
    }
    $this->connection->setPrefixPublic($prefixes);
//error_log(var_export(['instance ' . $this->debugId, $connection_options, $prefixes], true));
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->getDbalConnection()->getWrappedConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    if ($e instanceof DatabaseExceptionWrapper) {
      $e = $e->getPrevious();
    }
    // The database schema might be changed by another process in between the
    // time that the statement was prepared and the time the statement was run
    // (e.g. usually happens when running tests). In this case, we need to
    // re-run the query.
    // @see http://www.sqlite.org/faq.html#q15
    // @see http://www.sqlite.org/rescode.html#schema
    if ($e->getErrorCode() === 17) {
      return $this->connection->query($query, $args, $options);
    }

    // Match all SQLSTATE 23xxx errors.
    if (substr($e->getSqlState(), -6, -3) == '23') {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    else {
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

  /**
   * Database asset name resolution methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getDbTableName(string $drupal_prefix, string $drupal_table_name): string {
    // In SQLite, the prefix is the database.
    return $drupal_table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalTableName(string $drupal_default_prefix, string $db_table_name): string {
    // In SQLite, the prefix is the database.
    return $db_table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbFullQualifiedTableName($drupal_table_name) {
    $prefix = $this->connection->tablePrefix($drupal_table_name);
    return empty($prefix) ? 'main.' . $drupal_table_name : $prefix . '.' . $drupal_table_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDbIndexName($context, DbalSchema $dbal_schema, $drupal_table_name, $index_name, array $table_prefix_info) {
    // If checking for index existence or dropping, see if an index exists
    // with the Drupal name, regardless of prefix. A table can be renamed so
    // that the prefix is no longer relevant.
    if (in_array($context, ['indexExists', 'dropIndex'])) {
      $dbal_table = $dbal_schema->getTable($this->tableName($drupal_table_name));
if ($drupal_table_name === 'node_field_data') throw new \Exception(var_export([$drupal_table_name, $index_name, array_keys($dbal_table->getIndexes())], TRUE));
      foreach ($dbal_table->getIndexes() as $index) {
        $index_full_name = $index->getName();
        $matches = [];
        if (preg_match('/.*____(.+)/', $index_full_name, $matches)) {
          if ($matches[1] === $index_name) {
            return $index_full_name;
          }
        }
      }
      return FALSE;
    }
    else {
      // Use an UUID to make the index identifier unique and not table
      // dependent (otherwise indexes need to be recreated if the table gets
      // renamed).
      $uuid = new Uuid();
      return 'idx_' . str_replace('-', '', $uuid->generate()) . '____' . $index_name;
    }
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    if ($connection_options['database'] === ':memory:') {
      $dbal_connection_options['path'] = 'file::memory:?cache=shared';
    }
    else {
      $dbal_connection_options['path'] = $connection_options['database'];
      if (isset($connection_options['prefix']['default']) && $connection_options['prefix']['default'] !== '') {
        $dbal_connection_options['path'] .= '-' . $connection_options['prefix']['default'];
        if (isset($dbal_connection_options['url'])) {
          $dbal_connection_options['url'] .= '-' . $connection_options['prefix']['default'];
        }
      }
    }
    unset($dbal_connection_options['dbname']);
    $dbal_connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // Convert numeric values to strings when fetching.
      \PDO::ATTR_STRINGIFY_FETCHES => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
    $pdo = $dbal_connection->getWrappedConnection();

    // Create functions needed by SQLite.
    $pdo->sqliteCreateFunction('if', [SqliteConnectionBase::class, 'sqlFunctionIf']);
    $pdo->sqliteCreateFunction('greatest', [SqliteConnectionBase::class, 'sqlFunctionGreatest']);
    $pdo->sqliteCreateFunction('pow', 'pow', 2);
    $pdo->sqliteCreateFunction('exp', 'exp', 1);
    $pdo->sqliteCreateFunction('length', 'strlen', 1);
    $pdo->sqliteCreateFunction('md5', 'md5', 1);
    $pdo->sqliteCreateFunction('concat', [SqliteConnectionBase::class, 'sqlFunctionConcat']);
    $pdo->sqliteCreateFunction('concat_ws', [SqliteConnectionBase::class, 'sqlFunctionConcatWs']);
    $pdo->sqliteCreateFunction('substring', [SqliteConnectionBase::class, 'sqlFunctionSubstring'], 3);
    $pdo->sqliteCreateFunction('substring_index', [SqliteConnectionBase::class, 'sqlFunctionSubstringIndex'], 3);
    $pdo->sqliteCreateFunction('rand', [SqliteConnectionBase::class, 'sqlFunctionRand']);
    $pdo->sqliteCreateFunction('regexp', [SqliteConnectionBase::class, 'sqlFunctionRegexp']);

    // SQLite does not support the LIKE BINARY operator, so we overload the
    // non-standard GLOB operator for case-sensitive matching. Another option
    // would have been to override another non-standard operator, MATCH, but
    // that does not support the NOT keyword prefix.
    $pdo->sqliteCreateFunction('glob', [SqliteConnectionBase::class, 'sqlFunctionLikeBinary']);

    // Create a user-space case-insensitive collation with UTF-8 support.
    $pdo->sqliteCreateCollation('NOCASE_UTF8', ['Drupal\Component\Utility\Unicode', 'strcasecmp']);

    // Set SQLite init_commands if not already defined. Enable the Write-Ahead
    // Logging (WAL) for SQLite. See https://www.drupal.org/node/2348137 and
    // https://www.sqlite.org/wal.html.
    $connection_options += [
      'init_commands' => [],
    ];
    $connection_options['init_commands'] += [
      'wal' => "PRAGMA journal_mode=WAL",
    ];

    // Execute sqlite init_commands.
    if (isset($connection_options['init_commands'])) {
      $dbal_connection->exec(implode('; ', $connection_options['init_commands']));
    }
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
  public function preCreateDatabase($database_name) {
    // Verify the database is writable.
    $db_directory = new \SplFileInfo(dirname($database_name));
    if (!$db_directory->isDir() && !drupal_mkdir($db_directory->getPathName(), 0755, TRUE)) {
      throw new DatabaseNotFoundException('Unable to create database directory ' . $db_directory->getPathName());
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateMapConditionOperator($operator) {
    return isset(static::$sqliteConditionOperatorMap[$operator]) ? static::$sqliteConditionOperatorMap[$operator] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNextId($existing_id = 0) {
    // @codingStandardsIgnoreLine
    $trn = $this->connection->startTransaction();
    // We can safely use literal queries here instead of the slower query
    // builder because if a given database breaks here then it can simply
    // override nextId. However, this is unlikely as we deal with short strings
    // and integers and no known databases require special handling for those
    // simple cases. If another transaction wants to write the same row, it will
    // wait until this transaction commits. Also, the return value needs to be
    // set to RETURN_AFFECTED as if it were a real update() query otherwise it
    // is not possible to get the row count properly.
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
    return $this->connection->query($query . ' LIMIT ' . (int) $from . ', ' . (int) $count, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryTemporary($drupal_table_name, $query, array $args = [], array $options = []) {
    $prefixes = $this->connection->getPrefixes();
    $prefixes[$drupal_table_name] = '';
    $this->connection->setPrefixPublic($prefixes);
    return $this->connection->query('CREATE TEMPORARY TABLE ' . $drupal_table_name . ' AS ' . $query, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e) {
    throw $e;
  }

  /**
   * PlatformSql delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date) : string {
    if ($string_date) {
      $field = "strftime('%s', $field)";
    }
    return $field;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFormatSql(string $field, string $format) : string {
    // An array of PHP-to-SQLite date replacement patterns.
    static $replace = [
      'Y' => '%Y',
      // No format for 2 digit year number.
      'y' => '%Y',
      // No format for 3 letter month name.
      'M' => '%m',
      'm' => '%m',
      // No format for month number without leading zeros.
      'n' => '%m',
      // No format for full month name.
      'F' => '%m',
      // No format for 3 letter day name.
      'D' => '%d',
      'd' => '%d',
      // No format for full day name.
      'l' => '%d',
      // no format for day of month number without leading zeros.
      'j' => '%d',
      'W' => '%W',
      'H' => '%H',
      // No format for 12 hour hour with leading zeros.
      'h' => '%H',
      'i' => '%M',
      's' => '%S',
      // No format for AM/PM.
      'A' => '',
    ];

    $format = strtr($format, $replace);

    // SQLite does not have a ISO week substitution string, so it needs special
    // handling.
    // @see http://wikipedia.org/wiki/ISO_week_date#Calculation
    // @see http://stackoverflow.com/a/15511864/1499564
    if ($format === '%W') {
      $expression = "((strftime('%j', date(strftime('%Y-%m-%d', $field, 'unixepoch'), '-3 days', 'weekday 4')) - 1) / 7 + 1)";
    }
    else {
      $expression = "strftime('$format', $field, 'unixepoch')";
    }
    // The expression yields a string, but the comparison value is an integer in
    // case the comparison value is a float, integer, or numeric. All of the
    // above SQLite format tokens only produce integers. However, the given
    // $format may contain 'Y-m-d', which results in a string.
    // @see \Drupal\Core\Database\Driver\sqlite\Connection::expandArguments()
    // @see http://www.sqlite.org/lang_datefunc.html
    // @see http://www.sqlite.org/lang_expr.html#castexpr
    if (preg_match('/^(?:%\w)+$/', $format)) {
      $expression = "CAST($expression AS NUMERIC)";
    }
    return $expression;
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
    if (!empty($offset)) {
      $field = "($field + $offset)";
    }
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function onSelectPrefetchAllData() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterStatement(&$query, array &$args) {
    // The PDO SQLite layer doesn't replace numeric placeholders in queries
    // correctly, and this makes numeric expressions (such as
    // COUNT(*) >= :count) fail.
    // We replace numeric placeholders in the query ourselves to work around
    // this bug.
    //
    // See http://bugs.php.net/bug.php?id=45259 for more details.
    if (count($args)) {
      // Check if $args is a simple numeric array.
      if (range(0, count($args) - 1) === array_keys($args)) {
        // In that case, we have unnamed placeholders.
        $count = 0;
        $new_args = [];
        foreach ($args as $value) {
          if (is_float($value) || is_int($value)) {
            if (is_float($value)) {
              // Force the conversion to float so as not to loose precision
              // in the automatic cast.
              $value = sprintf('%F', $value);
            }
            $query = substr_replace($query, $value, strpos($query, '?'), 1);
          }
          else {
            $placeholder = ':db_statement_placeholder_' . $count++;
            $query = substr_replace($query, $placeholder, strpos($query, '?'), 1);
            $new_args[$placeholder] = $value;
          }
        }
        $args = $new_args;
      }
      else {
        // Else, this is using named placeholders.
        foreach ($args as $placeholder => $value) {
          if (is_float($value) || is_int($value)) {
            if (is_float($value)) {
              // Force the conversion to float so as not to loose precision
              // in the automatic cast.
              $value = sprintf('%F', $value);
            }

            // We will remove this placeholder from the query as PDO throws an
            // exception if the number of placeholders in the query and the
            // arguments does not match.
            unset($args[$placeholder]);
            // PDO allows placeholders to not be prefixed by a colon. See
            // http://marc.info/?l=php-internals&m=111234321827149&w=2 for
            // more.
            if ($placeholder[0] != ':') {
              $placeholder = ":$placeholder";
            }
            // When replacing the placeholders, make sure we search for the
            // exact placeholder. For example, if searching for
            // ':db_placeholder_1', do not replace ':db_placeholder_11'.
            $query = preg_replace('/' . preg_quote($placeholder) . '\b/', $value, $query);
          }
        }
      }
    }
    if ($this->getDebugging()) {
      error_log($query . ' : ' . var_export($args, TRUE));
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFetch(DbalStatement $dbal_statement, $mode, $fetch_class) {
    if ($mode === \PDO::FETCH_CLASS) {
      $dbal_statement->setFetchMode($mode, $fetch_class);
    }
    return $dbal_statement->fetch($mode);
  }

  /**
   * Select delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getForUpdateSQL() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function alterFullQualifiedTableName(string $full_db_table_name): string {
    if (strpos($full_db_table_name, '.') === FALSE) {
      return $full_db_table_name;
    }

    list($schema, $table_name) = explode('.', $full_db_table_name);
    $connection_options = $this->connection->getConnectionOptions();
    if (isset($connection_options['prefix']['default']) && $schema === $connection_options['prefix']['default']) {
      return 'main.' . $table_name;
    }
    return $full_db_table_name;
  }

  /**
   * Insert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function getAddDefaultsExplicitlyOnInsert() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDefaultsOnlyInsertSql(&$sql, $drupal_table_name) {
    $sql = 'INSERT INTO ' . $this->tableName($drupal_table_name) . ' DEFAULT VALUES';
    return TRUE;
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

    // DBAL creates the database based on the specified file on connection
    // opening. If the file is not writable, or the file path is wrong, we
    // get a DATABASE_NOT_FOUND error. In such case we need the user to
    // correct the URL.
    if ($e->getErrorCode() === self::DATABASE_NOT_FOUND) {
      $results['fail'][] = t('There is a problem with the database URL. Likely, the database file specified is not writable, or the file path is wrong. Doctrine DBAL reports the following message: %message', ['%message' => $e->getMessage()]);
    }

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

    // Ensure that Sqlite has the right minimum version.
    $db_server_version = $this->getDbalConnection()->getWrappedConnection()->getServerVersion();
    if (version_compare($db_server_version, self::SQLITE_MINIMUM_VERSION, '<')) {
      $results['fail'][] = t("The Sqlite version %version is less than the minimum required version %minimum_version.", [
        '%version' => $db_server_version,
        '%minimum_version' => self::SQLITE_MINIMUM_VERSION,
      ]);
    }

    return $results;
  }

  /**
   * Schema delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function alterDefaultSchema(&$default_schema) {
    $default_schema = 'main';
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateListTableNames() {
    try {
      return $this->getDbalConnection()->getSchemaManager()->listTableNames();
    }
    catch (DbalDriverException $e) {
      if ($e->getErrorCode() === 17) {
        return $this->getDbalConnection()->getSchemaManager()->listTableNames();
      }
      else {
        throw $e;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTableExists(&$result, $drupal_table_name) {
    try {
      $result = $this->getDbalConnection()->getSchemaManager()->tablesExist([$this->tableName($drupal_table_name)]);
    }
    catch (DbalDriverException $e) {
      if ($e->getErrorCode() === 17) {
        $result = $this->getDbalConnection()->getSchemaManager()->tablesExist([$this->tableName($drupal_table_name)]);
      }
      else {
        throw $e;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
    if (isset($drupal_field_specs['sqlite_type'])) {
      $dbal_type = $this->getDbalConnection()->getDatabasePlatform()->getDoctrineTypeMapping($drupal_field_specs['sqlite_type']);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getStringForDefault($string) {
    // Encode single quotes.
    return str_replace('\'', self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, $string);
  }

  /**
   * {@inheritdoc}
   */
  public function alterDbalColumnDefinition($context, &$dbal_column_definition, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    // DBAL does not support BINARY option for char/varchar columns.
    if (isset($drupal_field_specs['binary']) && $drupal_field_specs['binary'] === FALSE) {
      $dbal_column_definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 COLLATE NOCASE_UTF8', $dbal_column_definition);
      $dbal_column_definition = preg_replace('/TEXT\(([0-9]+)\)/', '$0 COLLATE NOCASE_UTF8', $dbal_column_definition);
    }

    // @todo just setting 'unsigned' to true does not enforce values >=0 in the
    // field in Sqlite, so add a CHECK >= 0 constraint.
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], [
      'float', 'numeric', 'serial', 'int',
    ]) && !empty($drupal_field_specs['unsigned']) && (bool) $drupal_field_specs['unsigned'] === TRUE) {
      $dbal_column_definition .= ' CHECK (' . $field_name . '>= 0)';
    }

    // @todo added to avoid edge cases; maybe this can be overridden in
    // alterDbalColumnOptions.
    // @todo there is a duplication of single quotes when table is
    // introspected and re-created.
    if (array_key_exists('default', $drupal_field_specs) && $drupal_field_specs['default'] === '') {
      $dbal_column_definition = preg_replace('/DEFAULT (?!:\'\')/', "$0 ''", $dbal_column_definition);
    }
    $dbal_column_definition = preg_replace('/DEFAULT\s+\'\'\'\'/', "DEFAULT ''", $dbal_column_definition);

    // Decode single quotes.
    if (array_key_exists('default', $dbal_column_options)) {
      $dbal_column_options['default'] = str_replace(self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, '\'\'', $dbal_column_options['default']);
    }
    $dbal_column_definition = str_replace(self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, '\'\'', $dbal_column_definition);

    // Column comments do not work when adding/changing a field in SQLite.
    // @todo check if it can be moved as unset of option in alterDbalColumnOptions
    if (in_array($context, ['addField', 'changeField'])) {
      $dbal_column_definition = preg_replace('/(.+)(( --)(.+))/', "$1", $dbal_column_definition);
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options) {
    // SQLite doesn't have a full-featured ALTER TABLE statement. It only
    // supports adding new fields to a table, in some simple cases. In most
    // cases, we have to create a new table and copy the data over.
    if (empty($keys_new_specs) && (empty($drupal_field_specs['not null']) || isset($drupal_field_specs['default']))) {
      // When we don't have to create new keys and we are not creating a
      // NOT NULL column without a default value, we can use the quicker
      // version.
      $dbal_type = $this->connection->schema()->getDbalColumnType($drupal_field_specs);
      $dbal_column_options = $this->connection->schema()->getDbalColumnOptions('addField', $field_name, $dbal_type, $drupal_field_specs);
      $query = 'ALTER TABLE {' . $drupal_table_name . '} ADD ' . $field_name . ' ' . $dbal_column_options['columnDefinition'];
      $this->connection->query($query);

      // Apply the initial value if set.
      if (isset($drupal_field_specs['initial'])) {
        $this->connection->update($drupal_table_name)
          ->fields([$field_name => $drupal_field_specs['initial']])
          ->execute();
      }
      if (isset($drupal_field_specs['initial_from_field'])) {
        $this->connection->update($drupal_table_name)
          ->expression($field_name, $drupal_field_specs['initial_from_field'])
          ->execute();
      }
    }
    else {
      // We cannot add the field directly. Use the slower table alteration
      // method, starting from the old schema.
      $old_schema = $this->buildTableSpecFromDbalSchema($dbal_schema, $drupal_table_name);
      $new_schema = $old_schema;

      // Add the new field.
      $new_schema['fields'][$field_name] = $drupal_field_specs;

      // Build the mapping between the old fields and the new fields.
      $mapping = [];
      if (isset($drupal_field_specs['initial'])) {
        // If we have a initial value, copy it over.
        $mapping[$field_name] = [
          'expression' => ':newfieldinitial',
          'arguments' => [':newfieldinitial' => $drupal_field_specs['initial']],
        ];
      }
      elseif (isset($drupal_field_specs['initial_from_field'])) {
        // If we have a initial value, copy it over.
        $mapping[$field_name] = [
          'expression' => $drupal_field_specs['initial_from_field'],
          'arguments' => [],
        ];
      }
      else {
        // Else use the default of the field.
        $mapping[$field_name] = NULL;
      }

      // Add the new indexes.
      $new_schema = array_merge($new_schema, $keys_new_specs);

      $this->alterTable($drupal_table_name, $old_schema, $new_schema, $mapping);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropField(DbalSchema $dbal_schema, $drupal_table_name, $field_name) {
    $old_schema = $this->buildTableSpecFromDbalSchema($dbal_schema, $drupal_table_name);
    $new_schema = $old_schema;

    unset($new_schema['fields'][$field_name]);

    // Handle possible primary key changes.
    if (isset($new_schema['primary key']) && ($key = array_search($field_name, $new_schema['primary key'])) !== FALSE) {
      unset($new_schema['primary key'][$key]);
    }

    // Handle possible index changes.
    foreach ($new_schema['indexes'] as $index => $fields) {
      foreach ($fields as $key => $field) {
        if ($field == $field_name) {
          unset($new_schema['indexes'][$index][$key]);
        }
      }
      // If this index has no more fields then remove it.
      if (empty($new_schema['indexes'][$index])) {
        unset($new_schema['indexes'][$index]);
      }
    }
    $this->alterTable($drupal_table_name, $old_schema, $new_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    $old_schema = $this->buildTableSpecFromDbalSchema($dbal_schema, $drupal_table_name);
    $new_schema = $old_schema;

    // Map the old field to the new field.
    if ($field_name != $field_new_name) {
      $mapping[$field_new_name] = $field_name;
    }
    else {
      $mapping = [];
    }

    // Remove the previous definition and swap in the new one.
    unset($new_schema['fields'][$field_name]);
    $new_schema['fields'][$field_new_name] = $drupal_field_new_specs;

    // Map the former indexes to the new column name.
    $new_schema['primary key'] = $this->mapKeyDefinition($new_schema['primary key'], $mapping);
    foreach (['unique keys', 'indexes'] as $k) {
      foreach ($new_schema[$k] as &$key_definition) {
        $key_definition = $this->mapKeyDefinition($key_definition, $mapping);
      }
    }

    // Add in the keys from $keys_new_specs.
    if (isset($keys_new_specs['primary key'])) {
      $new_schema['primary key'] = $keys_new_specs['primary key'];
    }
    foreach (['unique keys', 'indexes'] as $k) {
      if (!empty($keys_new_specs[$k])) {
        $new_schema[$k] = $keys_new_specs[$k] + $new_schema[$k];
      }
    }

    $this->alterTable($drupal_table_name, $old_schema, $new_schema, $mapping);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name, $default) {
    $old_schema = $this->buildTableSpecFromDbalSchema($dbal_schema, $drupal_table_name);
    $new_schema = $old_schema;

    $new_schema['fields'][$field_name]['default'] = $default;
    $this->alterTable($drupal_table_name, $old_schema, $new_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateFieldSetNoDefault(DbalSchema $dbal_schema, $drupal_table_name, $field_name) {
    $old_schema = $this->buildTableSpecFromDbalSchema($dbal_schema, $drupal_table_name);
    $new_schema = $old_schema;

    unset($new_schema['fields'][$field_name]['default']);
    $this->alterTable($drupal_table_name, $old_schema, $new_schema);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddUniqueKey(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name, array $drupal_field_specs) {
    // Avoid DBAL managing of this that would go through table re-creation.
    $index_columns = $this->connection->schema()->dbalGetFieldList($drupal_field_specs);
    $this->connection->query('CREATE UNIQUE INDEX ' . $index_full_name . ' ON ' . $table_full_name . ' (' . implode(', ', $index_columns) . ")");

    // Update DBAL Schema.
    $dbal_schema->getTable($table_full_name)->addUniqueIndex($index_columns, $index_full_name);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddIndex(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name, array $drupal_field_specs, array $indexes_spec) {
    // Avoid DBAL managing of this that would go through table re-creation.
    $index_columns = $this->connection->schema()->dbalGetFieldList($drupal_field_specs);
    $this->connection->query('CREATE INDEX ' . $index_full_name . ' ON ' . $table_full_name . ' (' . implode(', ', $index_columns) . ")");

    // Update DBAL Schema.
    $dbal_schema->getTable($table_full_name)->addIndex($index_columns, $index_full_name);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateDropIndex(DbalSchema $dbal_schema, $table_full_name, $index_full_name, $drupal_table_name, $drupal_index_name) {
    // Avoid DBAL managing of this that would go through table re-creation.
    $this->connection->query('DROP INDEX ' . $index_full_name);

    // Update DBAL Schema.
    $dbal_schema->getTable($table_full_name)->dropIndex($index_full_name);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetTableComment(DbalSchema $dbal_schema, $drupal_table_name) {
    throw new \RuntimeException('Table comments are not supported in SQlite.');
  }

  /**
   * Find out the schema of a table.
   *
   * This function uses introspection methods provided by the database to
   * create a schema array. This is useful, for example, during update when
   * the old schema is not available.
   *
   * @param \Doctrine\DBAL\Schema\Schema $dbal_schema
   *   The DBAL schema object.
   * @param string $table
   *   Name of the table.
   *
   * @return array
   *   An array representing the schema, from drupal_get_schema().
   *
   * @throws \Exception
   *   If a column of the table could not be parsed.
   */
  protected function buildTableSpecFromDbalSchema(DbalSchema $dbal_schema, $table) {
    $mapped_fields = array_flip($this->connection->schema()->getFieldTypeMap());
    $schema = [
      'fields' => [],
      'primary key' => [],
      'unique keys' => [],
      'indexes' => [],
      'full_index_names' => [],
    ];

    // Table.
    $dbal_table = $dbal_schema->getTable($this->tableName($table));

    // Columns.
    $columns = $dbal_table->getColumns();
    foreach ($columns as $column) {
      $dbal_type = $column->getType()->getName();
      if (isset($mapped_fields[$dbal_type])) {
        list($type, $size) = explode(':', $mapped_fields[$dbal_type]);
      }
      $schema['fields'][$column->getName()] = [
        'size' => $size,
        'not null' => $column->getNotNull(),
        'default' => ($column->getDefault() === NULL && $column->getNotNull() === FALSE) ? 'NULL' : $column->getDefault(),
      ];
      if ($column->getAutoincrement() === TRUE && in_array($dbal_type, [
        'smallint', 'integer', 'bigint',
      ])) {
        $schema['fields'][$column->getName()]['type'] = 'serial';
      }
      else {
        $schema['fields'][$column->getName()]['type'] = $type;
      }
      if ($column->getUnsigned() !== NULL) {
        $schema['fields'][$column->getName()]['unsigned'] = $column->getUnsigned();
      }
      if ($column->getLength() !== NULL) {
        $schema['fields'][$column->getName()]['lenght'] = $column->getLength();
      }
      if ($column->getComment() !== NULL) {
        $schema['fields'][$column->getName()]['comment'] = $column->getComment();
      }
    }

    // Primary key.
    if ($dbal_table->hasPrimaryKey()) {
      $schema['primary key'] = $dbal_table->getPrimaryKey()->getColumns();
    }

    // Indexes.
    $indexes = $dbal_table->getIndexes();
    foreach ($indexes as $index) {
      if ($index->isPrimary()) {
        continue;
      }
      $schema_key = $index->isUnique() ? 'unique keys' : 'indexes';
      // Get index name without prefix.
      $matches = NULL;
      preg_match('/.*____(.+)/', $index->getName(), $matches);
      $index_name = $matches[1];
      $schema[$schema_key][$index_name] = $index->getColumns();
      $schema['full_index_names'][] = $index->getName();
    }

    return $schema;
  }

  /**
   * Rename columns in an index definition according to a new mapping.
   *
   * @param array $key_definition
   *   The key definition.
   * @param array $mapping
   *   The new mapping.
   */
  protected function mapKeyDefinition(array $key_definition, array $mapping) {
    foreach ($key_definition as &$field) {
      // The key definition can be an array($field, $length).
      if (is_array($field)) {
        $field = &$field[0];
      }
      if (isset($mapping[$field])) {
        $field = $mapping[$field];
      }
    }
    return $key_definition;
  }

  /**
   * Create a table with a new schema containing the old content.
   *
   * As SQLite does not support ALTER TABLE (with a few exceptions) it is
   * necessary to create a new table and copy over the old content.
   *
   * @param string $table
   *   Name of the table to be altered.
   * @param array $old_schema
   *   The old schema array for the table.
   * @param array $new_schema
   *   The new schema array for the table.
   * @param array $mapping
   *   An optional mapping between the fields of the old specification and the
   *   fields of the new specification. An associative array, whose keys are
   *   the fields of the new table, and values can take two possible forms:
   *     - a simple string, which is interpreted as the name of a field of the
   *       old table,
   *     - an associative array with two keys 'expression' and 'arguments',
   *       that will be used as an expression field.
   */
  protected function alterTable($table, array $old_schema, array $new_schema, array $mapping = []) {
    $i = 0;
    do {
      $new_table = $table . '_' . $i++;
    } while ($this->connection->schema()->tableExists($new_table));

    // Drop any existing index from the old table.
    foreach ($old_schema['full_index_names'] as $full_index_name) {
      $this->connection->query('DROP INDEX ' . $full_index_name);
    }

    $this->connection->schema()->createTable($new_table, $new_schema);

    // Build a SQL query to migrate the data from the old table to the new.
    $select = $this->connection->select($table);

    // Complete the mapping.
    $possible_keys = array_keys($new_schema['fields']);
    $mapping += array_combine($possible_keys, $possible_keys);

    // Now add the fields.
    foreach ($mapping as $field_alias => $field_source) {
      // Just ignore this field (ie. use it's default value).
      if (!isset($field_source)) {
        continue;
      }

      if (is_array($field_source)) {
        $select->addExpression($field_source['expression'], $field_alias, $field_source['arguments']);
      }
      else {
        $select->addField($table, $field_source, $field_alias);
      }
    }

    // Execute the data migration query.
    $this->connection->insert($new_table)
      ->from($select)
      ->execute();

    $old_count = $this->connection->query('SELECT COUNT(*) FROM {' . $table . '}')->fetchField();
    $new_count = $this->connection->query('SELECT COUNT(*) FROM {' . $new_table . '}')->fetchField();
    if ($old_count == $new_count) {
      $this->connection->schema()->dropTable($table);
      $this->connection->schema()->renameTable($new_table, $table);
    }
  }

}
