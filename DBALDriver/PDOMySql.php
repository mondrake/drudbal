<?php

namespace Drupal\Driver\Database\drubal\DBALDriver;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Driver\Database\drubal\Connection as DrubalConnection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager as DBALDriverManager;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\ConnectionException as DbalConnectionException;

/**
 * Driver specific methods for pdo_mysql.
 */
class PDOMySql {

  /**
   * Minimum required mysql version.
   */
  const MYSQLSERVER_MINIMUM_VERSION = '5.5.3';

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
   * The DRUBAL connection.
   *
   * @todo should not be here, risk of circular reference.
   */
  protected $drubalConnection;

  /**
   * The actual DBAL connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $dbalConnection;

  /**
   * Flag to indicate if the cleanup function in __destruct() should run.
   *
   * @var bool
   */
  protected $needsCleanup = FALSE;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DrubalConnection $drubal_connection, DbalConnection $dbal_connection) {
    $this->drubalConnection = $drubal_connection;
    $this->dbalConnection = $dbal_connection;
  }

  /**
   * Gets the DBAL connection.
   *
   * @return string DBAL driver name
   */
  public function getDbalConnection() {
    return $this->dbalConnection;
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    try {
      static::preConnectionOpen($connection_options);
      $options = array_diff_key($connection_options, [
        'namespace' => NULL,
        'prefix' => NULL,
      ]);
      $dbal_connection = DBALDriverManager::getConnection($options);
      static::postConnectionOpen($dbal_connection, $connection_options);
    }
    catch (DbalConnectionException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
    return $dbal_connection;
  }

  /**
   * @todo shouldn't serialization being avoided?? this is from mysql core
   */
  public function serialize() {
    // Cleanup the connection, much like __destruct() does it as well.
    if ($this->needsCleanup) {
      $this->nextIdDelete();
    }
    $this->needsCleanup = FALSE;

    return parent::serialize();
  }

  /**
   * {@inheritdoc}
   */
  public function __destruct() {
    if ($this->needsCleanup) {
      $this->nextIdDelete();
    }
  }

  /**
   * @todo
   */
  public static function preConnectionOpen(array &$connection_options = []) {
    if (isset($connection_options['_dsn_utf8_fallback']) && $connection_options['_dsn_utf8_fallback'] === TRUE) {
      // Only used during the installer version check, as a fallback from utf8mb4.
      $charset = 'utf8';
    }
    else {
      $charset = 'utf8mb4';
    }
    // Character set is added to dsn to ensure PDO uses the proper character
    // set when escaping. This has security implications. See
    // https://www.drupal.org/node/1201452 for further discussion.
    $connection_options['charset'] = $charset;
    // Allow PDO options to be overridden.
    $connection_options += [
      'driverOptions' => [],
    ];
    $connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by default.
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's dumb.
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ];
    if (defined('\PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      // An added connection option in PHP 5.5.21 to optionally limit SQL to a
      // single statement like mysqli.
      $connection_options['driverOptions'] += [\PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE];
    }
  }

  /**
   * @todo
   */
  public static function postConnectionOpen(DbalConnection $dbal_connection, array &$connection_options = []) {
    // Force MySQL to use the UTF-8 character set. Also set the collation, if a
    // certain one has been set; otherwise, MySQL defaults to
    // 'utf8mb4_general_ci' for utf8mb4.
    if (!empty($connection_options['collation'])) {
      $dbal_connection->exec('SET NAMES ' . $connection_options['charset'] . ' COLLATE ' . $connection_options['collation']);
    }
    else {
      $dbal_connection->exec('SET NAMES ' . $connection_options['charset']);
    }

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
      'sql_mode' => "SET sql_mode = 'ANSI,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,ONLY_FULL_GROUP_BY'",
    ];
    // Execute initial commands.
    foreach ($connection_options['init_commands'] as $sql) {
      $dbal_connection->exec($sql);
    }
  }

  /**
   * @todo
   */
  public static function installConnect() {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    try {
      // Now actually do a check.
      try {
        Database::getConnection();
      }
      catch (\Exception $e) {
        // Detect utf8mb4 incompability.
        if ($e->getCode() == self::UNSUPPORTED_CHARSET || ($e->getCode() == self::SQLSTATE_SYNTAX_ERROR && $e->errorInfo[1] == self::UNKNOWN_CHARSET)) {
          $results['fail'][] = t('Your MySQL server and PHP MySQL driver must support utf8mb4 character encoding. Make sure to use a database system that supports this (such as MySQL/MariaDB/Percona 5.5.3 and up), and that the utf8mb4 character set is compiled in. See the <a href=":documentation" target="_blank">MySQL documentation</a> for more information.', [':documentation' => 'https://dev.mysql.com/doc/refman/5.0/en/cannot-initialize-character-set.html']);
          $info = Database::getConnectionInfo();
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
        }
        else {
          // Rethrow the exception.
          throw $e;
        }
      }
      $results['pass'][] = t('Drupal can CONNECT to the database ok.');
    }
    catch (\Exception $e) {
      // Attempt to create the database if it is not found.
      if ($e->getCode() == self::DATABASE_NOT_FOUND) {
        // Remove the database string from connection info.
        $connection_info = Database::getConnectionInfo();
        $database = $connection_info['default']['database'];
        unset($connection_info['default']['database']);

        // In order to change the Database::$databaseInfo array, need to remove
        // the active connection, then re-add it with the new info.
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $connection_info['default']);

        try {
          // Now, attempt the connection again; if it's successful, attempt to
          // create the database.
          Database::getConnection()->createDatabase($database);
        }
        catch (DatabaseNotFoundException $e) {
          // Still no dice; probably a permission issue. Raise the error to the
          // installer.
          $results['fail'][] = t('Database %database not found. The server reports the following message when attempting to create the database: %error.', ['%database' => $database, '%error' => $e->getMessage()]);
        }
      }
      else {
        // Database connection failed for some other reason than the database
        // not existing.
        $results['fail'][] = t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist or does the database user have sufficient privileges to create the database?</li><li>Have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname?</li></ul>', ['%error' => $e->getMessage()]);
      }
    }
    return $results;
  }

  /**
   * @todo
   */
  public function runInstallTasks() {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    // Ensure that MySql has the right minimum version.
    if (version_compare($this->drubalConnection->getDbServerVersion(), self::MYSQLSERVER_MINIMUM_VERSION, '<')) {
      $results['fail'][] = t("The MySQL server version %version is less than the minimum required version %minimum_version.", [
        '%version' => $this->drubalConnection->getDbServerVersion(),
        '%minimum_version' => self::MYSQLSERVER_MINIMUM_VERSION,
      ]);
    }

    // Ensure that InnoDB is available.
    $engines = $this->drubalConnection->query('SHOW ENGINES')->fetchAllKeyed();
    if (isset($engines['MyISAM']) && $engines['MyISAM'] == 'DEFAULT' && !isset($engines['InnoDB'])) {
      $results['fail'][] = t('The MyISAM storage engine is not supported.');
    }

    // Ensure that the MySQL driver supports utf8mb4 encoding.
    $version = $this->clientVersion();
    if (FALSE !== strpos($version, 'mysqlnd')) {
      // The mysqlnd driver supports utf8mb4 starting at version 5.0.9.
      $version = preg_replace('/^\D+([\d.]+).*/', '$1', $version);
      if (version_compare($version, self::MYSQLND_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The MySQLnd driver version %version is less than the minimum required version. Upgrade to MySQLnd version %mysqlnd_minimum_version or up, or alternatively switch mysql drivers to libmysqlclient version %libmysqlclient_minimum_version or up.", ['%version' => $version, '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION, '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION]);
      }
    }
    else {
      // The libmysqlclient driver supports utf8mb4 starting at version 5.5.3.
      if (version_compare($version, self::LIBMYSQLCLIENT_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The libmysqlclient driver version %version is less than the minimum required version. Upgrade to libmysqlclient version %libmysqlclient_minimum_version or up, or alternatively switch mysql drivers to MySQLnd version %mysqlnd_minimum_version or up.", ['%version' => $version, '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION, '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION]);
      }
    }

    return $results;
  }

  /**
   * @todo
   */
  public function transactionSupport(array &$connection_options = []) {
    // This driver defaults to transaction support, except if explicitly passed FALSE.
    return !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);
  }

  /**
   * @todo
   */
  public function transactionalDDLSupport(array &$connection_options = []) {
    // MySQL never supports transactional DDL.
    return FALSE;
  }

  /**
   * @todo
   */
  public static function preCreateDatabase($database) {
  }

  /**
   * @todo
   */
  public static function postCreateDatabase($database) {
    // Set the database as active.
    $this->drubalConnection->getDbalConnection()->exec("USE $database");
  }

  /**
   * @todo
   */
  public function nextId($existing_id = 0) {
    $new_id = $this->drubalConnection->query('INSERT INTO {sequences} () VALUES ()', [], ['return' => Database::RETURN_INSERT_ID]);
    // This should only happen after an import or similar event.
    if ($existing_id >= $new_id) {
      // If we INSERT a value manually into the sequences table, on the next
      // INSERT, MySQL will generate a larger value. However, there is no way
      // of knowing whether this value already exists in the table. MySQL
      // provides an INSERT IGNORE which would work, but that can mask problems
      // other than duplicate keys. Instead, we use INSERT ... ON DUPLICATE KEY
      // UPDATE in such a way that the UPDATE does not do anything. This way,
      // duplicate keys do not generate errors but everything else does.
      $this->drubalConnection->query('INSERT INTO {sequences} (value) VALUES (:value) ON DUPLICATE KEY UPDATE value = value', [':value' => $existing_id]);
      $new_id = $this->drubalConnection->query('INSERT INTO {sequences} () VALUES ()', [], ['return' => Database::RETURN_INSERT_ID]);
    }
    $this->needsCleanup = TRUE;
    return $new_id;
  }

  /**
   * @todo
   */
  public function nextIdDelete() {
    // While we want to clean up the table to keep it up from occupying too
    // much storage and memory, we must keep the highest value in the table
    // because InnoDB uses an in-memory auto-increment counter as long as the
    // server runs. When the server is stopped and restarted, InnoDB
    // reinitializes the counter for each table for the first INSERT to the
    // table based solely on values from the table so deleting all values would
    // be a problem in this case. Also, TRUNCATE resets the auto increment
    // counter.
    try {
      $max_id = $this->drubalConnection->query('SELECT MAX(value) FROM {sequences}')->fetchField();
      // We know we are using MySQL here, no need for the slower db_delete().
      $this->drubalConnection->query('DELETE FROM {sequences} WHERE value < :value', [':value' => $max_id]);
    }
    // During testing, this function is called from shutdown with the
    // simpletest prefix stored in $this->connection, and those tables are gone
    // by the time shutdown is called so we need to ignore the database
    // errors. There is no problem with completely ignoring errors here: if
    // these queries fail, the sequence will work just fine, just use a bit
    // more database storage and memory.
//    catch (DatabaseException $e) {
    catch (\Exception $e) {
      return;  // @todo
    }
  }

  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->drubalConnection->query($query . ' LIMIT ' . (int) $from . ', ' . (int) $count, $args, $options);
  }

  public function queryTemporary($tablename, $query, array $args = [], array $options = []) {
    return $this->drubalConnection->query('CREATE TEMPORARY TABLE {' . $tablename . '} Engine=MEMORY ' . $query, $args, $options);
  }

  public function releaseSavepoint($name) {
    try {
      $this->drubalConnection->query('RELEASE SAVEPOINT ' . $name);
      return 'ok';
    }
    catch (DatabaseExceptionWrapper $e) {
      // However, in MySQL (InnoDB), savepoints are automatically committed
      // when tables are altered or created (DDL transactions are not
      // supported). This can cause exceptions due to trying to release
      // savepoints which no longer exist.
      //
      // To avoid exceptions when no actual error has occurred, we silently
      // succeed for MySQL error code 1305 ("SAVEPOINT does not exist").
      //
      // With DBAL, the previous exception is DBALException, and the
      // previous again is PDOException where errorInfo is stored.
      if ($e->getPrevious()->getPrevious()->errorInfo[1] == '1305') {
        // We also have to explain to PDO that the transaction stack has
        // been cleaned-up.
        $this->drubalConnection->commit();
        // If one SAVEPOINT was released automatically, then all were.
        // Therefore, clean the transaction stack.
        return 'all';
      }
      else {
        throw $e;
      }
    }
  }


  /**
   * Returns the version of the database client.
   */
  public function clientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
  }

  /**
   * Schema delegated methods.
   */

  public function delegateCreateTableSetOptions($dbal_table, $dbal_schema, &$table, $name) {
    // Provide defaults if needed.
    $table += [
      'mysql_engine' => 'InnoDB',
      'mysql_character_set' => 'utf8mb4',
    ];
    $dbal_table->addOption('charset', $table['mysql_character_set']);
    $dbal_table->addOption('engine', $table['mysql_engine']);
    $info = $this->drubalConnection->getConnectionOptions();
    $dbal_table->addOption('collate', empty($info['collation']) ? 'utf8mb4_general_ci' : $info['collation']);
  }

  public function delegateGetDbalColumnType(&$dbal_type, $field) {
    if (isset($field['mysql_type'])) {
      $dbal_type = $this->dbalConnection->getDatabasePlatform()->getDoctrineTypeMapping($field['mysql_type']);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get information about the table and database name from the prefix.
   *
   * @return
   *   A keyed array with information about the database, table name and prefix.
   */
  public function getPrefixInfo($table = 'default', $add_prefix = TRUE) {
    $info = ['prefix' => $this->drubalConnection->tablePrefix($table)];
    if ($add_prefix) {
      $table = $info['prefix'] . $table;
    }
    if (($pos = strpos($table, '.')) !== FALSE) {
      $info['database'] = substr($table, 0, $pos);
      $info['table'] = substr($table, ++$pos);
    }
    else {
      $info['database'] = $this->dbalConnection->getDatabase();
      $info['table'] = $table;
    }
    return $info;
  }

  /**
   * Retrieve a table or column comment.
   */
  public function delegateGetComment(&$comment, $dbal_schema, $table, $column = NULL) {
    if ($column !== NULL) {
      return FALSE;
    }

    // DBAL cannot retrieve table comments from introspected schema.
    // @see https://github.com/doctrine/dbal/issues/1335
    $table_info = $this->getPrefixInfo($table);
    $dbal_query = $this->dbalConnection->createQueryBuilder();
    $dbal_query
      ->select('table_comment')
      ->from('information_schema.tables')
      ->where(
          $dbal_query->expr()->andX(
            $dbal_query->expr()->eq('table_schema', '?'),
            $dbal_query->expr()->eq('table_name', '?')
          )
        )
      ->setParameter(0, $table_info['database'])
      ->setParameter(1, $table_info['table']);
    $comment = $this->alterGetComment($dbal_query->execute()->fetchField(), $dbal_schema, $table, $column);
    return TRUE;
  }

  /**
   * Alter a table or column comment retrieved from schema.
   */
  public function alterGetComment($comment, $dbal_schema, $table, $column = NULL) {
    return $comment;
  }

  /**
   * Alter a table comment being set.
   */
  public function alterSetTableComment($comment, $name, $dbal_schema, $table) {
    return Unicode::truncate($comment, self::COMMENT_MAX_TABLE, TRUE, TRUE);
  }

  /**
   * Alter a column comment being set.
   */
  public function alterSetColumnComment($comment, $dbal_type, $field, $field_name) {
    return Unicode::truncate($comment, self::COMMENT_MAX_COLUMN, TRUE, TRUE);
  }

  /**
   * @var array
   *   List of MySQL string types.
   */
  protected $mysqlStringTypes = [
    'VARCHAR',
    'CHAR',
    'TINYTEXT',
    'MEDIUMTEXT',
    'LONGTEXT',
    'TEXT',
  ];

  /**
   * Set database-engine specific properties for a field.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function processField($field) {

    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }

    // Set the correct database-engine specific datatype.
    // In case one is already provided, force it to uppercase.
    if (isset($field['mysql_type'])) {
      $field['mysql_type'] = Unicode::strtoupper($field['mysql_type']);
    }
    else {
      $map = $this->getFieldTypeMap();
      $field['mysql_type'] = $map[$field['type'] . ':' . $field['size']];
    }

    if (isset($field['type']) && $field['type'] == 'serial') {
      $field['auto_increment'] = TRUE;
    }

    return $field;
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
  public function getNormalizedIndexes(array $spec) {
    $indexes = isset($spec['indexes']) ? $spec['indexes'] : [];
    foreach ($indexes as $index_name => $index_fields) {
      foreach ($index_fields as $index_key => $index_field) {
        // Get the name of the field from the index specification.
        $field_name = is_array($index_field) ? $index_field[0] : $index_field;
        // Check whether the field is defined in the table specification.
        if (isset($spec['fields'][$field_name])) {
          // Get the MySQL type from the processed field.
          $mysql_field = $this->processField($spec['fields'][$field_name]);
          if (in_array($mysql_field['mysql_type'], $this->mysqlStringTypes)) {
            // Check whether we need to shorten the index.
            if ((!isset($mysql_field['type']) || $mysql_field['type'] != 'varchar_ascii') && (!isset($mysql_field['length']) || $mysql_field['length'] > 191)) {
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

  protected function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip. This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    // $map does not use drupal_static as its value never changes.
    static $map = [
      'varchar_ascii:normal' => 'VARCHAR',

      'varchar:normal'  => 'VARCHAR',
      'char:normal'     => 'CHAR',

      'text:tiny'       => 'TINYTEXT',
      'text:small'      => 'TINYTEXT',
      'text:medium'     => 'MEDIUMTEXT',
      'text:big'        => 'LONGTEXT',
      'text:normal'     => 'TEXT',

      'serial:tiny'     => 'TINYINT',
      'serial:small'    => 'SMALLINT',
      'serial:medium'   => 'MEDIUMINT',
      'serial:big'      => 'BIGINT',
      'serial:normal'   => 'INT',

      'int:tiny'        => 'TINYINT',
      'int:small'       => 'SMALLINT',
      'int:medium'      => 'MEDIUMINT',
      'int:big'         => 'BIGINT',
      'int:normal'      => 'INT',

      'float:tiny'      => 'FLOAT',
      'float:small'     => 'FLOAT',
      'float:medium'    => 'FLOAT',
      'float:big'       => 'DOUBLE',
      'float:normal'    => 'FLOAT',

      'numeric:normal'  => 'DECIMAL',

      'blob:big'        => 'LONGBLOB',
      'blob:normal'     => 'BLOB',
    ];
    return $map;
  }

  /**
   * Helper function for normalizeIndexes().
   *
   * Shortens an index to 191 characters.
   *
   * @param array $index
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



}
