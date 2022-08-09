<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Types\Type as DbalType;
use Drupal\Component\Uuid\Php as Uuid;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;
use Drupal\drudbal\Driver\Database\dbal\Statement\PrefetchingStatementWrapper;
use Drupal\sqlite\Driver\Database\sqlite\Connection as SqliteConnectionBase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Driver specific methods for pdo_sqlite.
 */
class PDOSqliteExtension extends AbstractExtension {

  /**
   * Minimum required Sqlite version.
   */
  const SQLITE_MINIMUM_VERSION = '3.26';

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
   * {@inheritdoc}
   */
  protected $statementClass = PrefetchingStatementWrapper::class;

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
  public function __construct(DruDbalConnection $drudbal_connection) {
    parent::__construct($drudbal_connection);

    // If a memory database, then do not try to attach databases per prefix.
    if ($this->connection->getConnectionOptions()['database'] === ':memory:') {
      return;
    }

    $connection_options = $this->connection->getConnectionOptions();

    // Prefix.
    $prefix = $connection_options['prefix'];
    $this->attachedDatabases['main'] = $connection_options['database'] . (empty($prefix) ? '' : ('-' . $prefix));
    $this->connection->setPrefixPublic($prefix);
  }

  /**
   * Destructor for the SQLite connection.
   *
   * We prune empty databases on destruct, but only if tables have been
   * dropped. This is especially needed when running the test suite, which
   * creates and destroy databases several times in a row.
   */
  public function __destruct() {
    if ($this->tableDropped && !empty($this->attachedDatabases)) {
      foreach ($this->attachedDatabases as $prefix => $db_file) {
        // Check if the database is now empty, ignore the internal SQLite tables.
        try {
          $count = $this->connection->query('SELECT COUNT(*) FROM ' . $prefix . '.sqlite_master WHERE type = :type AND name NOT LIKE :pattern', [':type' => 'table', ':pattern' => 'sqlite_%'])->fetchField();

          // We can prune the database file if it doesn't have any tables.
          if ($count == 0) {
            // Detach the database.
            if ($prefix !== 'main') {
              $this->connection->query('DETACH DATABASE :schema', [':schema' => $prefix]);
            }
            // Destroy the database files.
            unlink($db_file);
            @unlink($db_file . '-wal');
            @unlink($db_file . '-shm');
            // @todo The '0' suffix file is due to migrate tests. To be removed.
            @unlink($db_file . '0');
          }
        }
        catch (\Exception $e) {
          // Ignore the exception and continue. There is nothing we can do here
          // to report the error or fail safe.
        }
      }
    }
    parent::__destruct();
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAttachDatabase(string $database): void {
    // Only attach the database once.
    if (!isset($this->attachedDatabases[$database])) {
      $this->getDbalConnection()->executeQuery('ATTACH DATABASE ? AS ?', ["{$this->connection->getConnectionOptions()['database']}-{$database}", $database]);
      $this->attachedDatabases[$database] = "{$this->connection->getConnectionOptions()['database']}-{$database}";
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->getDbalConnection()->getNativeConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
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
    if ($e->getCode() === 17) {
      return $this->connection->query($query, $args, $options);
    }

    // Match all SQLSTATE 23xxx errors.
    if (method_exists($e, 'getSqlState') && substr($e->getSqlState(), -6, -3) == '23') {
      throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
    }
    else {
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDbServerPlatform(bool $strict = FALSE): string {
    return "sqlite";
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
  public function getDrupalTableName(string $prefix, string $db_table_name): ?string {
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
  public function getDbIndexName(string $context, DbalSchema $dbal_schema, string $drupal_table_name, string $drupal_index_name): string {
    // If checking for index existence or dropping, see if an index exists
    // with the Drupal name, regardless of prefix. A table can be renamed so
    // that the prefix is no longer relevant.
    if (in_array($context, ['indexExists', 'dropIndex'])) {
      $dbal_table = $dbal_schema->getTable($this->connection->getPrefixedTableName($drupal_table_name));
      foreach ($dbal_table->getIndexes() as $index) {
        $index_full_name = $index->getName();
        $matches = [];
        if (preg_match('/.*____(.+)/', $index_full_name, $matches)) {
          if ($matches[1] === $drupal_index_name) {
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
      return 'idx_' . str_replace('-', '', $uuid->generate()) . '____' . $drupal_index_name;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalIndexName(string $drupal_table_name, string $db_index_name): string {
    $matches = [];
    preg_match('/.*____(.+)/', $db_index_name, $matches);
    return $matches[1] ?: null;
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    if ($connection_options['database'] === ':memory:') {
      $dbal_connection_options['path'] = NULL;
      $dbal_connection_options['url'] = 'sqlite:///:memory:';
      $dbal_connection_options['memory'] = TRUE;
    }
    else {
      $dbal_connection_options['path'] = $connection_options['database'];
      if ($connection_options['prefix'] !== '') {
        $dbal_connection_options['path'] .= '-' . $connection_options['prefix'];
        if (isset($dbal_connection_options['url'])) {
          $dbal_connection_options['url'] .= '-' . $connection_options['prefix'];
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
    $pdo = $dbal_connection->getNativeConnection()->getNativeConnection();

    // Create functions needed by SQLite.
    $pdo->sqliteCreateFunction('if', [SqliteConnectionBase::class, 'sqlFunctionIf']);
    $pdo->sqliteCreateFunction('greatest', [SqliteConnectionBase::class, 'sqlFunctionGreatest']);
    $pdo->sqliteCreateFunction('least', [SqliteConnectionBase::class, 'sqlFunctionLeast']);
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
      $dbal_connection->executeStatement(implode('; ', $connection_options['init_commands']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionalDdlSupport(array &$connection_options = []): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function preCreateDatabase($database_name) {
    // Verify the database is writable.
    $db_directory = new \SplFileInfo(dirname($database_name));
    if (!$db_directory->isDir() && !(new Filesystem())->mkdir($db_directory->getPathName(), 0755)) {
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
  public function delegateNextId(int $existing_id = 0): int {

    // @codingStandardsIgnoreLine
    $trn = $this->connection->startTransaction();
    // We can safely use literal queries here instead of the slower query
    // builder because if a given database breaks here then it can simply
    // override nextId. However, this is unlikely as we deal with short strings
    // and integers and no known databases require special handling for those
    // simple cases. If another transaction wants to write the same row, it will
    // wait until this transaction commits.
    $stmt = $this->connection->prepareStatement('UPDATE {sequences} SET [value] = GREATEST([value], :existing_id) + 1', [], TRUE);
    $args = [':existing_id' => $existing_id];
    try {
      $stmt->execute($args);
    }
    catch (\Exception $e) {
      $this->connection->exceptionHandler()->handleExecutionException($e, $stmt, $args, []);
    }
    if ($stmt->rowCount() === 0) {
      $this->connection->query('INSERT INTO {sequences} ([value]) VALUES (:existing_id + 1)', $args);
    }
    // The transaction gets committed when the transaction object gets destroyed
    // because it gets out of scope.
    return $this->connection->query('SELECT [value] FROM {sequences}')->fetchField();
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
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e) {
    throw $e;
  }

  /**
   * DrudbalDateSql delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date): string {
    if ($string_date) {
      $field = "strftime('%s', $field)";
    }
    return $field;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFormatSql(string $field, string $format): string {
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
  public function delegateSetTimezoneOffset(string $offset): void {
    // Nothing to do here.
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetFieldTimezoneOffsetSql(string &$field, int $offset): void {
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
    return $this;
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
    if ($connection_options['prefix'] === '' || $schema === $connection_options['prefix']) {
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
    $sql = 'INSERT INTO ' . $this->connection->getPrefixedTableName($drupal_table_name) . ' DEFAULT VALUES';
    return TRUE;
  }

  /**
   * Upsert delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function hasNativeUpsert(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateUpsertSql(string $drupal_table_name, string $key, array $insert_fields, array $insert_values, string $comments = ''): string {

    $query = $comments . 'INSERT INTO {' . $drupal_table_name . '} ';
    $query .= '([' . implode('], [', $insert_fields) . ']) ';
    $query .= 'VALUES ' . implode(', ', $insert_values);

    // Updating the unique / primary key is not necessary.
    unset($insert_fields[$key]);

    $update = [];
    foreach ($insert_fields as $field) {
      // The "excluded." prefix causes the field to refer to the value for field
      // that would have been inserted had there been no conflict.
      $update[] = "[$field] = EXCLUDED.[$field]";
    }

    $query .= ' ON CONFLICT (' . $this->connection->escapeField($key) . ') DO UPDATE SET ' . implode(', ', $update);

    return $query;
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
    if ($e->getCode() === self::DATABASE_NOT_FOUND) {
      $results['fail'][] = t('There is a problem with the database URL. Likely, the database file specified is not writable, or the file path is wrong. Doctrine DBAL reports the following message: %message', ['%message' => $e->getMessage()]);
    }

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

    // Ensure that Sqlite has the right minimum version.
    $db_server_version = $this->getDbalConnection()->getNativeConnection()->getServerVersion();
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
      return $this->getDbalConnection()->createSchemaManager()->listTableNames();
    }
    catch (DbalDriverException $e) {
      if ($e->getCode() === 17) {
        return $this->getDbalConnection()->createSchemaManager()->listTableNames();
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
      $result = $this->getDbalConnection()->createSchemaManager()->tablesExist([$this->connection->getPrefixedTableName($drupal_table_name)]);
    }
    catch (DbalDriverException $e) {
      if ($e->getCodeCode() === 17) {
        $result = $this->getDbalConnection()->createSchemaManager()->tablesExist([$this->connection->getPrefixedTableName($drupal_table_name)]);
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
    $matches = NULL;
    if (preg_match('/^(.+)(\s\-\-.*)$/', $dbal_column_definition, $matches) === 1) {
      $definition = $matches[1];
      $comment = $matches[2];
    }
    else {
      $definition = $dbal_column_definition;
      $comment = '';
    }

    // DBAL does not support BINARY option for char/varchar columns.
    if (isset($drupal_field_specs['binary']) && $drupal_field_specs['binary'] === FALSE) {
      $definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 COLLATE NOCASE_UTF8', $definition);
      $definition = preg_replace('/TEXT\(([0-9]+)\)/', '$0 COLLATE NOCASE_UTF8', $definition);
    }

    // @todo just setting 'unsigned' to true does not enforce values >=0 in the
    // field in Sqlite, so add a CHECK >= 0 constraint.
    if (isset($drupal_field_specs['type']) && in_array($drupal_field_specs['type'], [
      'float', 'numeric', 'serial', 'int',
    ]) && !empty($drupal_field_specs['unsigned']) && (bool) $drupal_field_specs['unsigned'] === TRUE) {
      $definition .= ' CHECK (' . $field_name . '>= 0)';
    }

    // @todo added to avoid edge cases; maybe this can be overridden in
    // alterDbalColumnOptions.
    // @todo there is a duplication of single quotes when table is
    // introspected and re-created.
    if (array_key_exists('default', $drupal_field_specs) && $drupal_field_specs['default'] === '') {
      $definition = preg_replace('/DEFAULT (?!:\'\')/', "$0 ''", $definition);
    }
    $definition = preg_replace('/DEFAULT\s+\'\'\'\'/', "DEFAULT ''", $definition);

    // Decode single quotes.
    if (array_key_exists('default', $dbal_column_options)) {
      $dbal_column_options['default'] = str_replace(self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, '\'\'', $dbal_column_options['default']);
    }
    $definition = str_replace(self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, '\'\'', $definition);

    $dbal_column_definition = $definition . $comment . "\n";

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postDropTable(DbalSchema $dbal_schema, string $drupal_table_name): void  {
    // Signal that at least a table has been deleted so that housekeeping
    // can happen when destructing the extension.
    $this->tableDropped = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options) {
    // SQLite doesn't have a full-featured ALTER TABLE statement. It only
    // supports adding new fields to a table, in some simple cases. In most
    // cases, we have to create a new table and copy the data over.
    if (empty($keys_new_specs) && (empty($drupal_field_specs['not null']) || isset($drupal_field_specs['default'])) && empty($drupal_field_specs['description'])) {
      // When we don't have to create new keys and we are not creating a
      // NOT NULL column without a default value, we can use the quicker
      // version.
      $dbal_type = $this->connection->schema()->getDbalColumnType($drupal_field_specs);
      $dbal_column_options = $this->connection->schema()->getDbalColumnOptions('addField', $field_name, $dbal_type, $drupal_field_specs);
      $query = 'ALTER TABLE {' . $drupal_table_name . '} ADD ' . $field_name . ' ' . $dbal_column_options['columnDefinition'];
      $this->connection->query($query);
    }
    else {
      // We cannot add the field directly. Use the slower table alteration
      // method, starting from the old schema.
      $old_schema = $this->buildTableSpecFromDbalSchema($dbal_schema, $drupal_table_name);
      $new_schema = $old_schema;

      // Add the new field.
      $new_schema['fields'][$field_name] = $drupal_field_specs;

      // Use the default value of the field.
      $mapping[$field_name] = NULL;

      // Add the new indexes.
      $new_schema = array_merge($new_schema, $keys_new_specs);

      // Avoid serial fields in composite primary key or fields not in the
      // primary key.
      if (isset($keys_new_specs['primary key'])) {
        if (count($keys_new_specs['primary key']) === 1) {
          foreach ($new_schema['fields'] as $field_name => &$field_value) {
            if ($field_value['type'] === 'serial' && !in_array($field_name, $keys_new_specs['primary key'])) {
              $field_value['type'] = 'int';
            }
          }
        }
        elseif (count($keys_new_specs['primary key']) > 1) {
          foreach ($keys_new_specs['primary key'] as $key) {
            if ($new_schema['fields'][$key]['type'] === 'serial') {
              $new_schema['fields'][$key]['type'] = 'int';
            }
          }
        }
      }
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
    $primary_key_processed_by_extension = TRUE;
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
  public function delegateDropPrimaryKey(bool &$primary_key_dropped_by_extension, string &$primary_key_asset_name, DbalSchema $dbal_schema, string $drupal_table_name): bool {
    $old_schema = $this->buildTableSpecFromDbalSchema($dbal_schema, $drupal_table_name);
    $new_schema = $old_schema;
    $mapping = [];
    if (!isset($new_schema['primary key'])) {
      $primary_key_dropped_by_extension = FALSE;
    }
    else {
      unset($new_schema['primary key']);
      // Change any serial field to int otherwise DBAL will interpret it as a
      // primary key.
      foreach ($new_schema['fields'] as &$field) {
        if ($field['type'] === 'serial') {
          $field['type'] = 'int';
        }
      }
      $this->alterTable($drupal_table_name, $old_schema, $new_schema, $mapping);
      $primary_key_dropped_by_extension = TRUE;
    }
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
    $typeRegistry = DbalType::getTypeRegistry();
    $mapped_fields = array_flip($this->connection->schema()->getFieldTypeMap());
    $schema = [
      'fields' => [],
      'primary key' => [],
      'unique keys' => [],
      'indexes' => [],
      'full_index_names' => [],
    ];

    // Table.
    $dbal_table = $dbal_schema->getTable($this->connection->getPrefixedTableName($table));

    // Description.
    if ($dbal_table->getComment() !== NULL) {
      $schema['description'] = $dbal_table->getComment();
    }

    // Primary key.
    try {
      $primary_key = $dbal_table->getPrimaryKey();
      $primary_key_columns = $primary_key ? $primary_key->getColumns() : [];
    }
    catch (DbalException $e) {
      $primary_key_columns = [];
    }

    // Columns.
    $columns = $dbal_table->getColumns();
    foreach ($columns as $column) {
      $dbal_type = $typeRegistry->lookupName($column->getType());
      if (!isset($mapped_fields[$dbal_type])) {
        throw new \RuntimeException('Invalid DBAL type ' . $dbal_type);
      }
      [$type, $size] = explode(':', $mapped_fields[$dbal_type]);
      $schema['fields'][$column->getName()] = [
        'size' => $size,
        'not null' => $column->getNotNull() || in_array($column->getName(), $primary_key_columns),
      ];
      if (($column->getDefault() === NULL || strpos($column->getDefault(), 'NULL --') === 0) && $column->getNotNull() === FALSE) {
        $schema['fields'][$column->getName()]['default'] = NULL;
      }
      else {
        $default = $column->getDefault() === NULL ? NULL : str_replace("''", "'", $column->getDefault());
        $schema['fields'][$column->getName()]['default'] = $default;
      }
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
        $schema['fields'][$column->getName()]['length'] = $column->getLength();
      }
      if ($column->getComment() !== NULL) {
        $schema['fields'][$column->getName()]['description'] = $column->getComment();
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
      // The key definition can be an array [$field, $length].
      if (is_array($field)) {
        $field = &$field[0];
      }
      $mapped_field = array_search($field, $mapping, TRUE);
      if ($mapped_field !== FALSE) {
        $field = $mapped_field;
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

    if ($this->copyTableData($table, $new_table, $new_schema, $mapping)) {
      $this->connection->schema()->dropTable($table);
      $this->connection->schema()->createTable($table, $new_schema);
      $this->copyTableData($new_table, $table, $new_schema);
      $this->connection->schema()->dropTable($new_table);
    }
  }

  /**
   * Copy data between two tables.
   *
   * @param string $from_table
   *   Name of the table to be copied from.
   * @param string $to_table
   *   Name of the table to be copied to.
   * @param array $schema
   *   The schema array for the table.
   * @param array $mapping
   *   An optional mapping between the fields of the from-table and the
   *   fields of to-table. An associative array, whose keys are the fields of
   *   the to-table, and values can take two possible forms:
   *     - a simple string, which is interpreted as the name of a field of the
   *       from-table,
   *     - an associative array with two keys 'expression' and 'arguments',
   *       that will be used as an expression field.
   */
  protected function copyTableData(string $from_table, string $to_table, array $schema, array $mapping = []): bool {
    // Build a SQL query to select data from the from-table.
    $select = $this->connection->select($from_table);

    // Complete the mapping.
    $possible_keys = array_keys($schema['fields']);
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
        $select->addField($from_table, $field_source, $field_alias);
      }
    }

    // Execute the data migration query.
    $this->connection->insert($to_table)
      ->from($select)
      ->execute();

    $old_count = $this->connection->query('SELECT COUNT(*) FROM {' . $from_table . '}')->fetchField();
    $new_count = $this->connection->query('SELECT COUNT(*) FROM {' . $to_table . '}')->fetchField();
    return $old_count == $new_count;
  }

}
