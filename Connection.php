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

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Version as DbalVersion;
use Doctrine\DBAL\DBALException;

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
  protected $drubalDriver;

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
  public function __construct(DbalConnection $dbal_connection, array $connection_options = []) {
    $drubal_dbal_driver_class = static::getDrubalDriverClass($dbal_connection->getDriver()->getName());
    $this->drubalDriver = new $drubal_dbal_driver_class($this, $dbal_connection);
    $dbal_connection->getWrappedConnection()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [$this->statementClass, [$this]]);  // @todo move to driver
    $this->transactionSupport = $this->drubalDriver->transactionSupport($connection_options);
    $this->transactionalDDLSupport = $this->drubalDriver->transactionalDDLSupport($connection_options);
    $this->setPrefix(isset($connection_options['prefix']) ? $connection_options['prefix'] : '');
    $this->connectionOptions = $connection_options;
  }

  /**
   * Destroys this Connection object.
   *
   * PHP does not destruct an object if it is still referenced in other
   * variables. In case of PDO database connection objects, PHP only closes the
   * connection when the PDO object is destructed, so any references to this
   * object may cause the number of maximum allowed connections to be exceeded.
   */
  public function destroy() {
    // Destroy all references to this connection by setting them to NULL.
    // The Statement class attribute only accepts a new value that presents a
    // proper callable, so we reset it to PDOStatement.
    if (!empty($this->statementClass)) {
      $this->getDbalConnection()->getWrappedConnection()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, ['PDOStatement', []]);
    }
    $this->schema = NULL;
  }

  /**
   * @todo
   */
  public function runInstallTasks() {
    return $this->drubalDriver->runInstallTasks();
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->drubalDriver->clientVersion();
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
    $drubal_dbal_driver_class = static::getDrubalDriverClass($connection_options['dbal_driver']);
    return $drubal_dbal_driver_class::open($connection_options);
  }

  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    try {
      return $this->drubalDriver->queryRange($query, $from, $count, $args, $options);
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function queryTemporary($query, array $args = [], array $options = []) {
    try {
      $tablename = $this->generateTemporaryTableName();
      $this->drubalDriver->queryTemporary($tablename, $query, $args, $options);
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
      $this->drubalDriver->preCreateDatabase($database);
      $this->getDbalConnection()->getSchemaManager()->createDatabase($database);
      $this->drubalDriver->postCreateDatabase($database);
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
    return $this->drubalDriver->nextId($existing_id);
  }

  /**
   * Rolls back the transaction entirely or to a named savepoint.
   *
   * This method throws an exception if no transaction is active.
   *
   * @param string $savepoint_name
   *   (optional) The name of the savepoint. The default, 'drupal_transaction',
   *    will roll the entire transaction back.
   *
   * @throws \Drupal\Core\Database\TransactionOutOfOrderException
   * @throws \Drupal\Core\Database\TransactionNoActiveException
   *
   * @see \Drupal\Core\Database\Transaction::rollBack()
   */
  public function rollBack($savepoint_name = 'drupal_transaction') {
    if (!$this->supportsTransactions()) {
      return;
    }
    if (!$this->inTransaction()) {
      throw new TransactionNoActiveException();
    }
    // A previous rollback to an earlier savepoint may mean that the savepoint
    // in question has already been accidentally committed.
    if (!isset($this->transactionLayers[$savepoint_name])) {
      throw new TransactionNoActiveException();
    }

    // We need to find the point we're rolling back to, all other savepoints
    // before are no longer needed. If we rolled back other active savepoints,
    // we need to throw an exception.
    $rolled_back_other_active_savepoints = FALSE;
    while ($savepoint = array_pop($this->transactionLayers)) {
      if ($savepoint == $savepoint_name) {
        // If it is the last the transaction in the stack, then it is not a
        // savepoint, it is the transaction itself so we will need to roll back
        // the transaction rather than a savepoint.
        if (empty($this->transactionLayers)) {
          break;
        }
        $this->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $this->popCommittableTransactions();
        if ($rolled_back_other_active_savepoints) {
          throw new TransactionOutOfOrderException();
        }
        return;
      }
      else {
        $rolled_back_other_active_savepoints = TRUE;
      }
    }
    $this->getDbalConnection()->rollBack();
    if ($rolled_back_other_active_savepoints) {
      throw new TransactionOutOfOrderException();
    }
  }

  /**
   * Increases the depth of transaction nesting.
   *
   * If no transaction is already active, we begin a new transaction.
   *
   * @param string $name
   *   The name of the transaction.
   *
   * @throws \Drupal\Core\Database\TransactionNameNonUniqueException
   *
   * @see \Drupal\Core\Database\Transaction
   */
  public function pushTransaction($name) {
    if (!$this->supportsTransactions()) {
      return;
    }
    if (isset($this->transactionLayers[$name])) {
      throw new TransactionNameNonUniqueException($name . " is already in use.");
    }
    // If we're already in a transaction then we want to create a savepoint
    // rather than try to create another transaction.
    if ($this->inTransaction()) {
      $this->query('SAVEPOINT ' . $name);
    }
    else {
      $this->getDbalConnection()->beginTransaction();
    }
    $this->transactionLayers[$name] = $name;
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
        if (!$this->getDbalConnection()->commit()) {  // @todo DBAL does not return false, raises exception
//          throw new TransactionCommitFailedException();
        }
      }
      else {
        // Attempt to release this savepoint in the standard way.
        if ($this->drubalDriver->releaseSavepoint($name) === 'all') {
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
    return $this->drubalDriver->getDbalConnection();
  }

  /**
   * Gets the DRUBAL driver.
   *
   * @return @todo
   */
  public function getDrubalDriver() {
    return $this->drubalDriver;
  }

  /**
   * Gets the DBAL driver class.
   *
   * @return string DBAL driver class.
   */
  public static function getDrubalDriverClass($driver_name) {
    return "Drupal\\Driver\\Database\\drubal\\DBALDriver\\" . static::$driverMap[$driver_name];  // @todo manage aliases, class path to const
  }

  /**
   * Gets the database server version
   *
   * @return string database server version string
   */
  public function getDbServerVersion() {
    return $this->getDbalConnection()->getWrappedConnection()->getServerVersion();
  }

  /**
   * {@inheritdoc}
   */
  public function quote($string, $parameter_type = \PDO::PARAM_STR) {   // @todo adjust default
    return $this->getDbalConnection()->quote($string, $parameter_type);
  }

}

