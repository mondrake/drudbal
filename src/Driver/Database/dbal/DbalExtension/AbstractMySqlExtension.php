<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Schema\Column as DbalColumn;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\TransactionCommitFailedException;

/**
 * Abstract DBAL Extension for MySql drivers.
 */
abstract class AbstractMySqlExtension extends AbstractExtension {

  /**
   * Minimum required MySQL version.
   *
   * 5.7.8 is the minimum version that supports the JSON datatype.
   * @see https://dev.mysql.com/doc/refman/5.7/en/json.html
   */
  const MYSQL_MINIMUM_VERSION = '5.7.8';

  /**
   * Minimum required MariaDB version.
   *
   * 10.3.7 is the first stable (GA) release in the 10.3 series.
   * @see https://mariadb.com/kb/en/changes-improvements-in-mariadb-103/#list-of-all-mariadb-103-releases
   */
  const MARIADB_MINIMUM_VERSION = '10.3.7';

  /**
   * Minimum required MySQLnd version.
   */
  const MYSQLND_MINIMUM_VERSION = '5.0.9';

  /**
   * Minimum required libmysqlclient version.
   */
  const LIBMYSQLCLIENT_MINIMUM_VERSION = '5.5.3';

  /**
   * Error code for "Unknown database" error.
   */
  const DATABASE_NOT_FOUND = 1049;

  /**
   * Error code for "Access denied" error.
   */
  const ACCESS_DENIED = 1045;

  /**
   * Error code for "Can't initialize character set" error.
   */
  const UNSUPPORTED_CHARSET = 2019;

  /**
   * Driver-specific error code for "Unknown character set" error.
   */
  const UNKNOWN_CHARSET = 1115;

  /**
   * SQLSTATE error code for "Syntax error or access rule violation".
   */
  const SQLSTATE_SYNTAX_ERROR = 42000;

  /**
   * Maximum length of a table comment in MySQL.
   */
  const COMMENT_MAX_TABLE = 60;

  /**
   * Maximum length of a column comment in MySQL.
   */
  const COMMENT_MAX_COLUMN = 255;

  /**
   * The minimal possible value for the max_allowed_packet setting of MySQL.
   *
   * @link https://mariadb.com/kb/en/mariadb/server-system-variables/#max_allowed_packet
   * @link https://dev.mysql.com/doc/refman/5.7/en/server-system-variables.html#sysvar_max_allowed_packet
   */
  const MIN_MAX_ALLOWED_PACKET = 1024;

  /**
   * Replacement for single quote identifiers.
   *
   * @todo DBAL uses single quotes instead of backticks to produce DDL
   * statements. This causes problems if fields defaults or comments have
   * single quotes inside.
   */
  const SINGLE_QUOTE_IDENTIFIER_REPLACEMENT = ']]]]SINGLEQUOTEIDENTIFIERDRUDBAL[[[[';

  /**
   * Default MySql engine.
   */
  const DEFAULT_ENGINE = 'InnoDB';

  /**
   * Default character set.
   */
  const DEFAULT_CHARACTER_SET = 'utf8mb4';

  /**
   * Default collation.
   */
  const DEFAULT_COLLATION = 'utf8mb4_general_ci';

  /**
   * Flag to indicate if the cleanup function in __destruct() should run.
   */
  protected bool $needsCleanup = FALSE;

  public function __destruct() {
    if ($this->needsCleanup) {
      $this->nextIdDelete();
    }
  }

  /**
   * Connection delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    if (isset($connection_options['_dsn_utf8_fallback']) && $connection_options['_dsn_utf8_fallback'] === TRUE) {
      // Only used during the installer version check, as a fallback from
      // utf8mb4.
      $charset = 'utf8';
    }
    else {
      $charset = 'utf8mb4';
    }

    // Character set is added to dsn to ensure PDO uses the proper character
    // set when escaping. This has security implications. See
    // https://www.drupal.org/node/1201452 for further discussion.
    $connection_options['charset'] = $charset;
    $dbal_connection_options['charset'] = $charset;
  }

  /**
   * {@inheritdoc}
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options, array &$dbal_connection_options) {
    // Force MySQL to use the UTF-8 character set. Also set the collation, if a
    // certain one has been set; otherwise, MySQL defaults to
    // 'utf8mb4_general_ci' for utf8mb4.
    $sql = 'SET NAMES ' . $connection_options['charset'];
    if (!empty($connection_options['collation'])) {
      $sql .= ' COLLATE ' . $connection_options['collation'];
    }
    $dbal_connection->executeStatement($sql);

    // Set MySQL init_commands if not already defined.  Default Drupal's MySQL
    // behavior to conform more closely to SQL standards.  This allows Drupal
    // to run almost seamlessly on many different kinds of database systems.
    // These settings force MySQL to behave the same as postgresql, or sqlite
    // in regards to syntax interpretation and invalid data handling.  See
    // https://www.drupal.org/node/344575 for further discussion. Also, as MySQL
    // 5.5 changed the meaning of TRADITIONAL we need to spell out the modes one
    // by one.
    $connection_options += [
      'init_commands' => [],
    ];
    $connection_options['init_commands'] += [
      'sql_mode' => "SET sql_mode = 'ANSI,TRADITIONAL'",
    ];
    // Execute initial commands.
    foreach ($connection_options['init_commands'] as $sql) {
      $dbal_connection->executeStatement($sql);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateTransactionalDdlSupport(array &$connection_options = []): bool {
    // MySQL never supports transactional DDL.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function postCreateDatabase($database_name) {
    // Set the database as active.
    $this->getDbalConnection()->executeStatement("USE $database_name");
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateNextId(int $existing_id = 0): int {
    $this->connection->query('INSERT INTO {sequences} () VALUES ()');
    $new_id = $this->connection->lastInsertId();
    // This should only happen after an import or similar event.
    if ($existing_id >= $new_id) {
      // If we INSERT a value manually into the sequences table, on the next
      // INSERT, MySQL will generate a larger value. However, there is no way
      // of knowing whether this value already exists in the table. MySQL
      // provides an INSERT IGNORE which would work, but that can mask problems
      // other than duplicate keys. Instead, we use INSERT ... ON DUPLICATE KEY
      // UPDATE in such a way that the UPDATE does not do anything. This way,
      // duplicate keys do not generate errors but everything else does.
      $this->connection->query('INSERT INTO {sequences} (value) VALUES (:value) ON DUPLICATE KEY UPDATE value = value', [':value' => $existing_id]);
      $this->connection->query('INSERT INTO {sequences} () VALUES ()');
      $new_id = $this->connection->lastInsertId();
    }
    $this->needsCleanup = TRUE;
    return (int) $new_id;
  }

  /**
   * Cleanup next ID.
   */
  protected function nextIdDelete() {
    // While we want to clean up the table to keep it up from occupying too
    // much storage and memory, we must keep the highest value in the table
    // because InnoDB uses an in-memory auto-increment counter as long as the
    // server runs. When the server is stopped and restarted, InnoDB
    // reinitializes the counter for each table for the first INSERT to the
    // table based solely on values from the table so deleting all values would
    // be a problem in this case. Also, TRUNCATE resets the auto increment
    // counter.
    try {
      $max_id = $this->connection->query('SELECT MAX(value) FROM {sequences}')->fetchField();
      // We know we are using MySQL here, no need for the slower db_delete().
      $this->connection->query('DELETE FROM {sequences} WHERE value < :value', [':value' => $max_id]);
    }
    // During testing, this function is called from shutdown with the
    // simpletest prefix stored in $this->connection, and those tables are gone
    // by the time shutdown is called so we need to ignore the database
    // errors. There is no problem with completely ignoring errors here: if
    // these queries fail, the sequence will work just fine, just use a bit
    // more database storage and memory.
    catch (DatabaseException $e) {
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryExceptionProcess($query, array $args, array $options, $message, \Exception $e) {
    if ($e instanceof DatabaseExceptionWrapper) {
      $e = $e->getPrevious();
    }
    // Match all SQLSTATE 23xxx errors.
dump(get_class($e));
    if ($e instanceof DbalDriverException) {
      if (substr($e->getSqlState(), -6, -3) == '23') {
        throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
      }
      elseif ($e->getCode() == 1153) {
        // If a max_allowed_packet error occurs the message length is truncated.
        // This should prevent the error from recurring if the exception is
        // logged to the database using dblog or the like.
        $message = Unicode::truncateBytes($e->getMessage(), self::MIN_MAX_ALLOWED_PACKET);
        throw new DatabaseExceptionWrapper($message, $e->getSqlState(), $e);
      }
      elseif ($e->getCode() == 1364) {
        // In case of attempted INSERT of a record with an undefined column and
        // no default value indicated in schema, MySql returns a 1364 error code.
        throw new IntegrityConstraintViolationException($message, $e->getCode(), $e);
      }
    }
    else {
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateQueryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->connection->query($query . ' LIMIT ' . (int) $from . ', ' . (int) $count, $args, $options);
  }

  /**
   * Transaction delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateReleaseSavepointExceptionProcess(DbalDriverException $e) {
    // In MySQL (InnoDB), savepoints are automatically committed
    // when tables are altered or created (DDL transactions are not
    // supported). This can cause exceptions due to trying to release
    // savepoints which no longer exist.
    //
    // To avoid exceptions when no actual error has occurred, we silently
    // succeed for MySQL error code 1305 ("SAVEPOINT does not exist").
    if ($e->getCode() == '1305') {
      // We also have to explain to PDO that the transaction stack has
      // been cleaned-up.
      try {
        $this->delegateCommit();
      }
      catch (DbalConnectionException $exc) {
        throw new TransactionCommitFailedException();
      }
      // If one SAVEPOINT was released automatically, then all were.
      // Therefore, clean the transaction stack.
      // @todo use a const
      return 'all';
    }
    else {
      throw $e;
    }
  }

  /**
   * DrudbalDateSql delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFieldSql(string $field, bool $string_date): string {
    if ($string_date) {
      return $field;
    }

    // Base date field storage is timestamp, so the date to be returned here is
    // epoch + stored value (seconds from epoch).
    return "DATE_ADD('19700101', INTERVAL $field SECOND)";
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDateFormatSql(string $field, string $format): string {
    // An array of PHP-to-MySQL replacement patterns.
    static $replace = [
      'Y' => '%Y',
      'y' => '%y',
      'M' => '%b',
      'm' => '%m',
      'n' => '%c',
      'F' => '%M',
      'D' => '%a',
      'd' => '%d',
      'l' => '%W',
      'j' => '%e',
      'W' => '%v',
      'H' => '%H',
      'h' => '%h',
      'i' => '%i',
      's' => '%s',
      'A' => '%p',
    ];

    $format = strtr($format, $replace);
    return "DATE_FORMAT($field, '$format')";
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetTimezoneOffset(string $offset): void {
    $this->getDbalConnection()->executeStatement("SET @@session.time_zone = '$offset'");
  }

  /**
   * {@inheritdoc}
   */
  public function delegateSetFieldTimezoneOffsetSql(string &$field, int $offset): void {
    if (!empty($offset)) {
      $field = "($field + INTERVAL $offset SECOND)";
    }
  }

  /**
   * Truncate delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function preTruncate($drupal_table_name) {
    $this->getDbalConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postTruncate($drupal_table_name) {
    $this->getDbalConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    return $this;
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

    $info = Database::getConnectionInfo();

    // Detect utf8mb4 incompability.
    if ($e->getCode() === self::UNSUPPORTED_CHARSET || $e->getCode() === self::UNKNOWN_CHARSET) {
      $results['fail'][] = t('Your MySQL server and PHP MySQL driver must support utf8mb4 character encoding. Make sure to use a database system that supports this (such as MySQL/MariaDB/Percona 5.5.3 and up), and that the utf8mb4 character set is compiled in. See the <a href=":documentation" target="_blank">MySQL documentation</a> for more information.', [':documentation' => 'https://dev.mysql.com/doc/refman/5.0/en/cannot-initialize-character-set.html']);
      $info_copy = $info;
      // Set a flag to fall back to utf8. Note: this flag should only be
      // used here and is for internal use only.
      $info_copy['default']['_dsn_utf8_fallback'] = TRUE;
      // In order to change the Database::$databaseInfo array, we need to
      // remove the active connection, then re-add it with the new info.
      Database::removeConnection('default');
      Database::addConnectionInfo('default', 'default', $info_copy['default']);
      // Connect with the new database info, using the utf8 character set so
      // that we can run the checkEngineVersion test.
      Database::getConnection();
      // Revert to the old settings.
      Database::removeConnection('default');
      Database::addConnectionInfo('default', 'default', $info['default']);
      return $results;
    }

    // Attempt to create the database if it is not found. Try to establish a
    // connection without database specified, try to create database, and if
    // successful reopen the connection to the new database.
    if ($e->getCode() === self::DATABASE_NOT_FOUND) {
      try {
        // Remove the database string from connection info.
        $database = $info['default']['database'];
        $dbal_url = $info['default']['dbal_url'];
        unset($info['default']['database']);
        if (($pos = strrpos($info['default']['dbal_url'], '/' . $database)) !== FALSE) {
          $info['default']['dbal_url'] = substr_replace($info['default']['dbal_url'], '', $pos, strlen($database) + 1);
        }

        // Change the Database::$databaseInfo array, need to remove the active
        // connection, then re-add it with the new info.
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $info['default']);

        // Now, attempt the connection again; if it's successful, attempt to
        // create the database, then reset the connection info to original.
        Database::getConnection()->createDatabase($database);
        Database::closeConnection();
        $info['default']['database'] = $database;
        $info['default']['dbal_url'] = $dbal_url;
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $info['default']);

        // Re-connect with the new database info.
        Database::getConnection();
        $results['pass'][] = t('Database %database was created successfully.', ['%database' => $database]);
      }
      catch (DatabaseNotFoundException $exc) {
        // Still no dice; probably a permission issue. Raise the error to the
        // installer.
        $results['fail'][] = t('Creation of database %database failed. The server reports the following message: %error.', [
          '%database' => $database,
          '%error' => $exc->getMessage(),
        ]);
      }
      return $results;
    }

    // Database connection failed for some other reasons. Report.
    $results['fail'][] = t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist or does the database user have sufficient privileges to create the database?</li><li>Have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname?</li></ul>', [
      '%error' => $e->getMessage(),
    ]);
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

    // Ensure that the database server has the right minimum version.
    $db_server_platform = $this->getDbServerPlatform(TRUE);
    $db_server_version = $this->getDbServerVersion();
    $db_server_min_version = $db_server_platform === 'mysql' ? self::MYSQL_MINIMUM_VERSION : self::MARIADB_MINIMUM_VERSION;
    if (version_compare($db_server_version, $db_server_min_version, '<')) {
      $results['fail'][] = t("The database server version %version is less than the minimum required version %minimum_version.", [
        '%version' => $db_server_version,
        '%minimum_version' => $db_server_min_version,
      ]);
    }

    // Ensure that InnoDB is available.
    $engines = $this->connection->query('SHOW ENGINES')->fetchAllKeyed();
    if (isset($engines['MyISAM']) && $engines['MyISAM'] == 'DEFAULT' && !isset($engines['InnoDB'])) {
      $results['fail'][] = t('The MyISAM storage engine is not supported.');
    }

    // Ensure that the MySQL driver supports utf8mb4 encoding.
    $version = $this->delegateClientVersion();
    if (FALSE !== strpos($version, 'mysqlnd')) {
      // The mysqlnd driver supports utf8mb4 starting at version 5.0.9.
      $version = preg_replace('/^\D+([\d.]+).*/', '$1', $version);
      if (version_compare($version, self::MYSQLND_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The MySQLnd driver version %version is less than the minimum required version. Upgrade to MySQLnd version %mysqlnd_minimum_version or up, or alternatively switch mysql drivers to libmysqlclient version %libmysqlclient_minimum_version or up.", [
          '%version' => $version,
          '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION,
          '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION,
        ]);
      }
    }
    else {
      // The libmysqlclient driver supports utf8mb4 starting at version 5.5.3.
      if (version_compare($version, self::LIBMYSQLCLIENT_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The libmysqlclient driver version %version is less than the minimum required version. Upgrade to libmysqlclient version %libmysqlclient_minimum_version or up, or alternatively switch mysql drivers to MySQLnd version %mysqlnd_minimum_version or up.", [
          '%version' => $version,
          '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION,
          '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION,
        ]);
      }
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
      $this->getDbalConnection()->executeQuery("SELECT 1 FROM " . $this->connection->getPrefixedTableName($drupal_table_name) . " LIMIT 1 OFFSET 0");
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
      $this->getDbalConnection()->executeQuery("SELECT $field_name FROM " . $this->connection->getPrefixedTableName($drupal_table_name) . " LIMIT 1 OFFSET 0");
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
  public function alterCreateTableOptions(DbalTable $dbal_table, DbalSchema $dbal_schema, array &$drupal_table_specs, $drupal_table_name) {
    // Provide defaults if needed.
    $drupal_table_specs += [
      'mysql_engine' => self::DEFAULT_ENGINE,
      'mysql_character_set' => self::DEFAULT_CHARACTER_SET,
    ];
    $dbal_table->addOption('charset', $drupal_table_specs['mysql_character_set']);
    $dbal_table->addOption('engine', $drupal_table_specs['mysql_engine']);
    $info = $this->connection->getConnectionOptions();
    $collation = empty($info['collation']) ? self::DEFAULT_COLLATION : $info['collation'];
    // If tables are created with UTF8 charset, without specifying the
    // collation, the DEFAULT_COLLATION is not valid. In this case and we
    // need to define one that works.
    if ($drupal_table_specs['mysql_character_set'] == 'utf8' && $collation === self::DEFAULT_COLLATION) {
      $collation = 'utf8_general_ci';
    }
    $dbal_table->addOption('collate', $collation);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateGetDbalColumnType(&$dbal_type, array $drupal_field_specs) {
    if (isset($drupal_field_specs['mysql_type'])) {
      $dbal_type = $this->getDbalConnection()->getDatabasePlatform()->getDoctrineTypeMapping($drupal_field_specs['mysql_type']);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function setDbalPlatformColumnOptions($context, DbalColumn $dbal_column, array &$dbal_column_options, $dbal_type, array $drupal_field_specs, $field_name) {
    if (isset($drupal_field_specs['type']) && $drupal_field_specs['type'] === 'varchar_ascii') {
      $dbal_column->setPlatformOption('charset', 'ascii');
      $dbal_column->setPlatformOption('collation', 'ascii_general_ci');
    }
    return $this;
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
    if (isset($drupal_field_specs['binary']) && $drupal_field_specs['binary']) {
      $dbal_column_definition = preg_replace('/CHAR\(([0-9]+)\)/', '$0 BINARY', $dbal_column_definition);
    }
    // Decode single quotes.
    $dbal_column_definition = str_replace(self::SINGLE_QUOTE_IDENTIFIER_REPLACEMENT, '\\\'', $dbal_column_definition);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateAddField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, array $drupal_field_specs, array $keys_new_specs, array $dbal_column_options) {
    if (!empty($keys_new_specs['primary key']) && isset($drupal_field_specs['type']) && $drupal_field_specs['type'] == 'serial') {
      $sql = 'ALTER TABLE {' . $drupal_table_name . '} ADD `' . $field_name . '` ' . $dbal_column_options['columnDefinition'];
      $keys_sql = $this->createKeysSql(['primary key' => $keys_new_specs['primary key']]);
      $sql .= ', ADD ' . $keys_sql[0];
      $this->connection->query($sql);
      $primary_key_processed_by_extension = TRUE;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateChangeField(&$primary_key_processed_by_extension, DbalSchema $dbal_schema, $drupal_table_name, $field_name, $field_new_name, array $drupal_field_new_specs, array $keys_new_specs, array $dbal_column_options) {
    $sql = 'ALTER TABLE {' . $drupal_table_name . '} CHANGE `' . $field_name . '` `' . $field_new_name . '` ' . $dbal_column_options['columnDefinition'];
    if (!empty($keys_new_specs['primary key'])) {
      $keys_sql = $this->createKeysSql(['primary key' => $keys_new_specs['primary key']]);
      $sql .= ', ADD ' . $keys_sql[0];
      $primary_key_processed_by_extension = TRUE;
    }
    $this->connection->query($sql);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateIndexExists(&$result, DbalSchema $dbal_schema, $table_full_name, $drupal_table_name, $drupal_index_name) {
    if ($drupal_index_name == 'PRIMARY') {
      $result = $dbal_schema->getTable($table_full_name)->hasPrimaryKey();
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIndexFields(DbalSchema $dbal_schema, string $table_full_name, string $index_full_name, string $drupal_table_name, string $drupal_index_name, array $drupal_field_specs, array $indexes_spec): array {
    // We need to normalize the index columns length in MySql.
    $indexes_spec['indexes'][$drupal_index_name] = $drupal_field_specs;
    return $this->getNormalizedIndexes($indexes_spec)[$drupal_index_name];
  }

  /**
   * {@inheritdoc}
   */
  public function alterSetTableComment(&$comment, $drupal_table_name, DbalSchema $dbal_schema, array $drupal_table_spec) {
    $comment = Unicode::truncate($comment, self::COMMENT_MAX_TABLE, TRUE, TRUE);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function alterSetColumnComment(&$comment, $dbal_type, array $drupal_field_specs, $field_name) {
    $comment = Unicode::truncate($comment, self::COMMENT_MAX_COLUMN, TRUE, TRUE);
    return $this;
  }

  /**
   * Gets normalized indexes from a table specification.
   *
   * Shortens indexes to 191 characters if they apply to utf8mb4-encoded
   * fields, in order to comply with the InnoDB index limitation of 756 bytes.
   *
   * @param array $spec
   *   The table specification.
   *
   * @return array
   *   List of shortened indexes.
   *
   * @throws \Drupal\Core\Database\SchemaException
   *   Thrown if field specification is missing.
   */
  protected function getNormalizedIndexes(array $spec) {
    $indexes = isset($spec['indexes']) ? $spec['indexes'] : [];
    foreach ($indexes as $index_name => $index_fields) {
      foreach ($index_fields as $index_key => $index_field) {
        // Get the name of the field from the index specification.
        $field_name = is_array($index_field) ? $index_field[0] : $index_field;
        // Check whether the field is defined in the table specification.
        if (isset($spec['fields'][$field_name])) {
          // Get the DBAL type from the field spec.
          $dbal_type = $this->connection->schema()->getDbalColumnType($spec['fields'][$field_name]);
          if (in_array($dbal_type, ['string', 'text'])) {
            // Check whether we need to shorten the index.
            if ((!isset($spec['fields'][$field_name]['type']) || $spec['fields'][$field_name]['type'] != 'varchar_ascii') && (!isset($spec['fields'][$field_name]['length']) || $spec['fields'][$field_name]['length'] > 191)) {
              // Limit the index length to 191 characters.
              $this->shortenIndex($indexes[$index_name][$index_key]);
            }
          }
        }
        else {
          throw new SchemaException("MySQL needs the '$field_name' field specification in order to normalize the '$index_name' index");
        }
      }
    }
    return $indexes;
  }

  /**
   * Helper function for normalizeIndexes().
   *
   * Shortens an index to 191 characters.
   *
   * @param mixed $index
   *   The index array to be used in createKeySql.
   *
   * @see Drupal\Core\Database\Driver\mysql\Schema::createKeySql()
   * @see Drupal\Core\Database\Driver\mysql\Schema::normalizeIndexes()
   */
  protected function shortenIndex(&$index) {
    if (is_array($index)) {
      if ($index[1] > 191) {
        $index[1] = 191;
      }
    }
    else {
      $index = [$index, 191];
    }
  }

  /**
   * Returns a string specifying a sequence of columns in MySql syntax.
   *
   * @param array $fields
   *   The array of fields in Drupal format.
   *
   * @return string
   *   The string specifying the sequence of columns.
   */
  protected function createKeySql(array $fields) {
    $return = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $return[] = '`' . $field[0] . '`(' . $field[1] . ')';
      }
      else {
        $return[] = '`' . $field . '`';
      }
    }
    return implode(', ', $return);
  }

  /**
   * Returns an array of strings specifying keys/indexes in MySql syntax.
   *
   * @param array $spec
   *   The array of table specifications in Drupal format.
   *
   * @return string[]
   *   The array of strings specifying keys/indexes in MySql syntax.
   */
  protected function createKeysSql(array $spec) {
    $keys = [];

    if (!empty($spec['primary key'])) {
      $keys[] = 'PRIMARY KEY (' . $this->createKeySql($spec['primary key']) . ')';
    }
    if (!empty($spec['unique keys'])) {
      foreach ($spec['unique keys'] as $key => $fields) {
        $keys[] = 'UNIQUE KEY `' . $key . '` (' . $this->createKeySql($fields) . ')';
      }
    }
    if (!empty($spec['indexes'])) {
      $indexes = $this->getNormalizedIndexes($spec);
      foreach ($indexes as $index => $fields) {
        $keys[] = 'INDEX `' . $index . '` (' . $this->createKeySql($fields) . ')';
      }
    }

    return $keys;
  }

}
