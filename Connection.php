<?php

namespace Drupal\Driver\Database\drubal;

use Drupal\Core\Database\DatabaseExceptionWrapper;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Component\Utility\Unicode;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\DriverManager as DBALDriverManager;
use Doctrine\DBAL\Version as DBALVersion;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\ConnectionException as DBALConnectionException;

/**
 * DRUBAL implementation of \Drupal\Core\Database\Connection.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver specific code
 * in Drupal\Driver\Database\drubal\DBALDriver\[driver_name] classes and
 * execution handed over to there.
 */
class Connection extends DatabaseConnection {

  /**
   * List of supported drivers and their mappings to the DBAL driver extension
   * classes.
   *
   * @var string[]
   */
  protected static $driverMap = array(
    'pdo_mysql'          => 'Drupal\Driver\Database\drubal\DBALDriver\PDOMySql',
    'pdo_sqlite'         => 'Drupal\Driver\Database\drubal\DBALDriver\PDOSqlite',
    'pdo_pgsql'          => 'Drupal\Driver\Database\drubal\DBALDriver\PDOPgSql',
    'pdo_oci'            => 'Drupal\Driver\Database\drubal\DBALDriver\PDOOracle',
    'oci8'               => 'Drupal\Driver\Database\drubal\DBALDriver\OCI8',
    'ibm_db2'            => 'Drupal\Driver\Database\drubal\DBALDriver\IBMDB2\DB2Driver',
    'pdo_sqlsrv'         => 'Drupal\Driver\Database\drubal\DBALDriver\PDOSqlsrv',
    'mysqli'             => 'Drupal\Driver\Database\drubal\DBALDriver\Mysqli',
    'drizzle_pdo_mysql'  => 'Drupal\Driver\Database\drubal\DBALDriver\DrizzlePDOMySql',
    'sqlanywhere'        => 'Drupal\Driver\Database\drubal\DBALDriver\SQLAnywhere',
    'sqlsrv'             => 'Drupal\Driver\Database\drubal\DBALDriver\SQLSrv',
  );

  /**
   * List of URL schemes from a database URL and their mappings to driver.
   *
   * @var string[]
   */
  protected static $driverSchemeAliases = array(
    'db2'        => 'ibm_db2',
    'mssql'      => 'pdo_sqlsrv',
    'mysql'      => 'pdo_mysql',
    'mysql2'     => 'pdo_mysql', // Amazon RDS, for some weird reason
    'postgres'   => 'pdo_pgsql',
    'postgresql' => 'pdo_pgsql',
    'pgsql'      => 'pdo_pgsql',
    'sqlite'     => 'pdo_sqlite',
    'sqlite3'    => 'pdo_sqlite',
  );

  /**
   * The actual DBAL connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $DBALConnection;

  /**
   * The current DBAL driver class.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $DBALDriverExtensionClass;

  /**
   * The DBAL driver extension.
   *
   * @var @todo
   */
  protected $DBALDriverExtension;

  /**
   * The minimal possible value for the max_allowed_packet setting of MySQL.
   *
   * @link https://mariadb.com/kb/en/mariadb/server-system-variables/#max_allowed_packet
   * @link https://dev.mysql.com/doc/refman/5.7/en/server-system-variables.html#sysvar_max_allowed_packet
   *
   * @var int
   */
  const MIN_MAX_ALLOWED_PACKET = 1024;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DBALConnection $dbal_connection, array $connection_options = []) {
    // Set the DBAL connection and the driver extension.
    $this->DBALConnection = $dbal_connection;
    if (isset($connection_options['dbal_driver'])) {
      $this->DBALDriverExtensionClass = $this->getDBALDriverExtensionClass($connection_options['dbal_driver']);
    }
    else {
      $this->DBALDriverExtensionClass = $this->getDBALDriverExtensionClass($this->getDBALDriver());
    }
    $this->DBALDriverExtension = new $this->DBALDriverExtensionClass($this);

    $this->setPrefix(isset($connection_options['prefix']) ? $connection_options['prefix'] : '');

    $this->connection = $dbal_connection->getWrappedConnection();
    $this->connection->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [$this->statementClass, [$this]]);

    $this->transactionSupport = $this->DBALDriverExtension->transactionSupport($connection_options);
    $this->transactionalDDLSupport = $this->DBALDriverExtension->transactionalDDLSupport($connection_options);
    $this->connectionOptions = $connection_options;
  }

  /**
   * {@inheritdoc}
   *
   * @todo clean this up.
   */
  public function query($query, array $args = [], $options = []) {
    // Use default values if not already set.
    $options += $this->defaultOptions();

    try {
      // We allow either a pre-bound statement object or a literal string.
      // In either case, we want to end up with an executed statement object,
      // which we pass to PDOStatement::execute.
      if ($query instanceof StatementInterface) {   // @todo
        $stmt = $query;
        $stmt->execute(NULL, $options);
      }
      else {
        $this->expandArguments($query, $args);
        // To protect against SQL injection, Drupal only supports executing one
        // statement at a time.  Thus, the presence of a SQL delimiter (the
        // semicolon) is not allowed unless the option is set.  Allowing
        // semicolons should only be needed for special cases like defining a
        // function or stored procedure in SQL. Trim any trailing delimiter to
        // minimize false positives.
        $query = rtrim($query, ";  \t\n\r\0\x0B");
        if (strpos($query, ';') !== FALSE && empty($options['allow_delimiter_in_query'])) {
          throw new \InvalidArgumentException('; is not supported in SQL strings. Use only one statement at a time.');
        }

        // Resolve tables' names with prefix.
        $query = $this->prefixTables($query);

        // Prepare a DBAL statement.
        $DBAL_stmt = $this->DBALConnection->prepare($query);

        // Set the fetch mode for the statement. @todo if not PDO?
        if (isset($options['fetch'])) {
          if (is_string($options['fetch'])) {
            // \PDO::FETCH_PROPS_LATE tells __construct() to run before properties
            // are added to the object.
            $DBAL_stmt->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $options['fetch']);
          }
          else {
            $DBAL_stmt->setFetchMode($options['fetch']);
          }
        }

        // Bind parameters.
        foreach ($args as $arg => $value) {
          $DBAL_stmt->bindValue($arg, $value);
        }

        // Executes statement via DBAL.
        $DBAL_stmt->execute();

        // This is the PDO statement. @todo if not using PDO?
        $stmt = $DBAL_stmt->getWrappedStatement();
      }

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return']) {
        case Database::RETURN_STATEMENT:
          return $stmt;
        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();
        case Database::RETURN_INSERT_ID:
          $sequence_name = isset($options['sequence_name']) ? $options['sequence_name'] : NULL;
          return $this->connection->lastInsertId($sequence_name);
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\PDOException $e) {
      // Most database drivers will return NULL here, but some of them
      // (e.g. the SQLite driver) may need to re-run the query, so the return
      // value will be the same as for static::query().
      return $this->handleQueryException($e, $query, $args, $options);
    }
    catch (DBALException $e) {
      return $this->handleQueryDBALException($e, $query, $args, $options);
    }
    catch (DatabaseException $e) {  // @todo MySql specific.
      if ($e->getPrevious()->errorInfo[1] == 1153) {
        // If a max_allowed_packet error occurs the message length is truncated.
        // This should prevent the error from recurring if the exception is
        // logged to the database using dblog or the like.
        $message = Unicode::truncateBytes($e->getMessage(), self::MIN_MAX_ALLOWED_PACKET);
        $e = new DatabaseExceptionWrapper($message, $e->getCode(), $e->getPrevious());
      }
      throw $e;
    }
  }

  /**
   * Wraps and re-throws any DBALException thrown by static::query().
   *
   * @param \Doctrine\DBAL\DBALException $e
   *   The exception thrown by static::query().
   * @param $query
   *   The query executed by static::query().
   * @param array $args
   *   An array of arguments for the prepared statement.
   * @param array $options
   *   An associative array of options to control how the query is run.
   *
   * @return @todo
   *
   * @throws \Drupal\Core\Database\DatabaseExceptionWrapper
   */
  protected function handleQueryDBALException(DBALException $e, $query, array $args = [], $options = []) {
    if ($options['throw_exception']) {
      // Wrap the exception in another exception, because PHP does not allow
      // overriding Exception::getMessage(). Its message is the extra database
      // debug information.
      $query_string = ($query instanceof StatementInterface) ? $query->getQueryString() : $query;
      $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
      throw new DatabaseExceptionWrapper($message, 0, $e);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    try {
      $dbal_driver_class = static::getDBALDriverExtensionClass($connection_options['dbal_driver']);
      $dbal_driver_class::preConnectionOpen($connection_options);
      $options = array_diff_key($connection_options, [
        'namespace' => NULL,
        'prefix' => NULL,
      ]);
      $dbal_connection = DBALDriverManager::getConnection($options);
      $dbal_connection->setFetchMode(\PDO::FETCH_OBJ); // @todo check why not by default
      $dbal_driver_class::postConnectionOpen($dbal_connection, $connection_options);
    }
    catch (DBALConnectionException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
    return $dbal_connection;
  }

  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    try {
      return $this->DBALDriverExtension->queryRange($query, $from, $count, $args, $options);
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function queryTemporary($query, array $args = [], array $options = []) {
    try {
      $tablename = $this->generateTemporaryTableName();
      $this->DBALDriverExtension->queryTemporary($tablename, $query, $args, $options);
      return $tablename;
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function driver() {
    return 'drubal';
  }

  public function databaseType() {
    return $this->DBALConnection->getDriver()->getDatabasePlatform()->getName();
  }

  /**
   * Returns the DBAL version.
   */
  public function version() {
    return DBALVersion::VERSION;
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    try {
      $this->DBALDriverExtension->preCreateDatabase($database);
      $this->DBALConnection->getSchemaManager()->createDatabase($database);
      $this->DBALDriverExtension->postCreateDatabase($database);
    }
    catch (DBALException $e) {
      throw new DatabaseNotFoundException($e->getMessage());
    }
  }

  public function mapConditionOperator($operator) {
    // We don't want to override any of the defaults.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function nextId($existing_id = 0) {
    return $this->DBALDriverExtension->nextId($existing_id);
  }

  /**
   * {@inheritdoc}
   */
  protected function popCommittableTransactions() {
    // Commit all the committable layers.
    foreach (array_reverse($this->transactionLayers) as $name => $active) {
      // Stop once we found an active transaction.
      if ($active) {
        break;
      }

      // If there are no more layers left then we should commit.
      unset($this->transactionLayers[$name]);
      if (empty($this->transactionLayers)) {
        if (!$this->connection->commit()) {
          throw new TransactionCommitFailedException();
        }
      }
      else {
        // Attempt to release this savepoint in the standard way.
        if ($this->DBALDriverExtension->releaseSavepoint($name) === 'all') {
          $this->transactionLayers = [];
        }
      }
    }
  }

  /**
   * Gets the DBAL connection.
   *
   * @return string DBAL driver name
   */
  public function getDBALConnection() {
    return $this->DBALConnection;
  }

  /**
   * Gets the DBAL driver name
   *
   * @return string DBAL driver name
   */
  public function getDBALDriver() {
    return $this->DBALConnection->getDriver()->getName();
  }

  /**
   * Gets the DBAL driver class.
   *
   * @return string DBAL driver class.
   */
  public static function getDBALDriverExtensionClass($driver_name) {
    return static::$driverMap[$driver_name];
  }

  /**
   * Gets the DBAL driver extension.
   *
   * @return @todo
   */
  public function getDBALDriverExtension() {
    return $this->DBALDriverExtension;
  }

  /**
   * Gets the database server version
   *
   * @return string database server version string
   *
   * @todo there should be a DBAL native method for this...
   */
  public function getDbServerVersion() {
    return $this->DBALConnection->getWrappedConnection()->getAttribute(\PDO::ATTR_SERVER_VERSION); // @todo if not PDO??
  }

}

