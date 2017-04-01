<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\TransactionCommitFailedException;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Version as DbalVersion;

/**
 * DruDbal implementation of \Drupal\Core\Database\Connection.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver specific code
 * in Drupal\Driver\Database\dbal\DBALDriver\[driver_name] classes and
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
    'pdo_mysql'          => 'PDOMySql',
    'pdo_sqlite'         => 'PDOSqlite',
    'pdo_pgsql'          => 'PDOPgSql',
    'pdo_oci'            => 'PDOOracle',
    'oci8'               => 'OCI8',
    'ibm_db2'            => 'IBMDB2\DB2Driver',
    'pdo_sqlsrv'         => 'PDOSqlsrv',
    'mysqli'             => 'Mysqli',
    'drizzle_pdo_mysql'  => 'DrizzlePDOMySql',
    'sqlanywhere'        => 'SQLAnywhere',
    'sqlsrv'             => 'SQLSrv',
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
   * The DBAL driver extension.
   *
   * @var @todo
   */
  protected $druDbalDriver;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DbalConnection $dbal_connection, array $connection_options = []) {
    $drudbal_driver_class = static::getDruDbalDriverClass($dbal_connection->getDriver()->getName());
    $this->druDbalDriver = new $drudbal_driver_class($this, $dbal_connection);
    $this->transactionSupport = $this->druDbalDriver->transactionSupport($connection_options);
    $this->transactionalDDLSupport = $this->druDbalDriver->transactionalDDLSupport($connection_options);
    $this->setPrefix(isset($connection_options['prefix']) ? $connection_options['prefix'] : '');
    $this->connectionOptions = $connection_options;
    // Unset $this->connection so that __get() can return the DbalConnection on
    // the driver instead.
    unset($this->connection);
  }

  /**
   * @todo
   */
  public function __get($name) {
    // Calls to $this->connection return the DbalConnection on the driver
    // instead.
    if ($name === 'connection') {
      return $this->getDbalConnection();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
    $this->druDbalDriver->destroy();
    $this->schema = NULL;
  }

  /**
   * @todo
   */
  public function runInstallTasks() {
    return $this->druDbalDriver->runInstallTasks();
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->druDbalDriver->clientVersion();
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
        $DBAL_stmt = $this->getDbalConnection()->prepare($query);

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
          return $this->getDbalConnection()->lastInsertId($sequence_name);
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (DBALException $e) {
      return $this->druDbalDriver->handleQueryDBALException($e, $query, $args, $options); // @todo csn we change and pass the normal method here??
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    $drudbal_driver_class = static::getDruDbalDriverClass($connection_options['dbal_driver']);
    return $drudbal_driver_class::open($connection_options);
  }

  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    try {
      return $this->druDbalDriver->queryRange($query, $from, $count, $args, $options);
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function queryTemporary($query, array $args = [], array $options = []) {
    try {
      $tablename = $this->generateTemporaryTableName();
      $this->druDbalDriver->queryTemporary($tablename, $query, $args, $options);
      return $tablename;
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function driver() {
    return 'dbal';
  }

  public function databaseType() {
    return $this->getDbalConnection()->getDriver()->getDatabasePlatform()->getName();
  }

  /**
   * Returns the DBAL version.
   */
  public function version() {
    return DbalVersion::VERSION;
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    try {
      $this->druDbalDriver->preCreateDatabase($database);
      $this->getDbalConnection()->getSchemaManager()->createDatabase($database);
      $this->druDbalDriver->postCreateDatabase($database);
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
    return $this->druDbalDriver->nextId($existing_id);
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
        try {
          $this->getDbalConnection()->commit();
        }
        catch (DbalConnectionException $e) {
          throw new TransactionCommitFailedException();
        }
      }
      else {
        // Attempt to release this savepoint in the standard way.
        if ($this->druDbalDriver->releaseSavepoint($name) === 'all') {
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
  public function getDbalConnection() {
    return $this->druDbalDriver->getDbalConnection();
  }

  /**
   * Gets the DruDbal driver.
   *
   * @return @todo
   */
  public function getDruDbalDriver() {
    return $this->druDbalDriver;
  }

  /**
   * Gets the DBAL driver class.
   *
   * @return string DBAL driver class.
   */
  public static function getDruDbalDriverClass($driver_name) {
    return "Drupal\\Driver\\Database\\dbal\\DBALDriver\\" . static::$driverMap[$driver_name];  // @todo manage aliases, class path to const
  }

  /**
   * Gets the database server version
   *
   * @return string database server version string
   */
  public function getDbServerVersion() {
    return $this->getDbalConnection()->getWrappedConnection()->getServerVersion();
  }

}
