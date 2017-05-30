<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Core\Database\TransactionNameNonUniqueException;
use Drupal\Core\Database\TransactionNoActiveException;
use Drupal\Core\Database\TransactionOutOfOrderException;

use Drupal\Driver\Database\dbal\DbalExtension\MysqliExtension;
use Drupal\Driver\Database\dbal\DbalExtension\PDOMySqlExtension;
use Drupal\Driver\Database\dbal\DbalExtension\PDOSqliteExtension;
use Drupal\Driver\Database\dbal\Statement\PDODbalStatement;
use Drupal\Driver\Database\dbal\Statement\PDOSqliteDbalStatement;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager as DbalDriverManager;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\Version as DbalVersion;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * DruDbal implementation of \Drupal\Core\Database\Connection.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Connection extends DatabaseConnection {

  /**
   * List of supported drivers and their mapping to the DBAL extension
   * and the statement classes to use.
   *
   * @var array[]
   */
  protected static $dbalClassMap = array(
    'mysqli' => [MysqliExtension::class, Statement::class],
    'pdo_mysql' => [PDOMySqlExtension::class, PDODbalStatement::class],
    'pdo_sqlite' => [PDOSqliteExtension::class, PDOSqliteDbalStatement::class],
  );

  /**
   * List of URL schemes from a database URL and their mappings to driver.
   *
   * @var string[]
   */
  protected static $driverSchemeAliases = array(
    'mysql' => 'pdo_mysql',
    'mysql2' => 'pdo_mysql',
    'sqlite' => 'pdo_sqlite',
    'sqlite3' => 'pdo_sqlite',
   );

  /**
   * The DruDbal extension for the DBAL driver.
   *
   * @var \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   */
  protected $dbalExtension;

  /**
   * Current connection DBAL platform.
   *
   * @var \Doctrine\DBAL\Platforms\AbstractPlatform
   */
  protected $dbalPlatform;

  /**
   * An array of options to be passed to the Statement object.
   *
   * DBAL is quite strict in the sense that it does not pass options to the
   * prepare/execute methods. Overcome that by storing here options required,
   * so that the custom Statement classes defined by the driver can manage that
   * on construction.
   *
   * @todo remove
   *
   * @var array[]
   */
  protected $statementOptions;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DbalConnection $dbal_connection, array $connection_options = []) {
    $this->connectionOptions = $connection_options;
    $this->setPrefix(isset($connection_options['prefix']) ? $connection_options['prefix'] : '');
    $dbal_extension_class = static::getDbalExtensionClass($connection_options);
    $this->statementClass = static::getStatementClass($connection_options);
    $this->dbalExtension = new $dbal_extension_class($this, $dbal_connection, $this->statementClass);
    $this->dbalPlatform = $dbal_connection->getDatabasePlatform();
    $this->transactionSupport = $this->dbalExtension->delegateTransactionSupport($connection_options);
    $this->transactionalDDLSupport = $this->dbalExtension->delegateTransactionalDdlSupport($connection_options);
    // Unset $this->connection so that __get() can return the wrapped
    // DbalConnection on the extension instead.
    unset($this->connection);
  }

  /**
   * Implements the magic __get() method.
   */
  public function __get($name) {
    // Calls to $this->connection return the wrapped DbalConnection on the
    // extension instead.
    if ($name === 'connection') {
      return $this->getDbalConnection();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
    $this->dbalExtension->destroy();
    $this->schema = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalExtension->delegateClientVersion();
  }

  /**
   * Returns a prefixed table name.
   *
   * @param string $table_name
   *   A Drupal table name
   *
   * @return string
   *   A fully prefixed table name, suitable for direct usage in db queries.
   */
  public function getPrefixedTableName($table_name) {
    return $this->prefixTables('{' . $table_name . '}');
  }

  /**
   * {@inheritdoc}
   */
  public function query($query, array $args = [], $options = []) {
    // Use default values if not already set.
    $options += $this->defaultOptions();

    try {
      // We allow either a pre-bound statement object or a literal string.
      // In either case, we want to end up with an executed statement object,
      // which we pass to Statement::execute.
      if ($query instanceof StatementInterface) {
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
        $stmt = $this->prepareQueryWithParams($query, $args);
        $stmt->execute($args, $options);
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
          return (string) $this->connection->lastInsertId($sequence_name);
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new DBALException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\InvalidArgumentException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      return $this->handleDbalQueryException($e, $query, $args, $options);
    }
  }

  /**
   * Wraps and re-throws any DBALException thrown by ::query().
   *
   * @param \Exception $e
   *   The exception thrown by query().
   * @param $query
   *   The query executed by query().
   * @param array $args
   *   An array of arguments for the prepared statement.
   * @param array $options
   *   An associative array of options to control how the query is run.
   *
   * @return mixed
   *   NULL when the option to re-throw is FALSE, the result of
   *   DbalExtensionInterface::delegateQueryExceptionProcess() otherwise.
   *
   * @throws \Drupal\Core\Database\DatabaseExceptionWrapper
   */
  protected function handleDbalQueryException(\Exception $e, $query, array $args = [], $options = []) {
    if ($options['throw_exception']) {
      // Wrap the exception in another exception, because PHP does not allow
      // overriding Exception::getMessage(). Its message is the extra database
      // debug information.
      if ($query instanceof StatementInterface) {
        $query_string = $query->getQueryString();
      }
      elseif (is_string($query)) {
        $query_string = $query;
      }
      else {
        $query_string = NULL;
      }
      $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
      return $this->dbalExtension->delegateQueryExceptionProcess($query, $args, $options, $message, $e);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    if (empty($connection_options['dbal_driver'])) {
      // If 'dbal_driver' is missing from the connection options, then we are
      // likely in an installation scenario where the database URL is invalid.
      // Try establishing a DBAL connection to clarify details.
      if (empty($connection_options['dbal_url'])) {
        // If 'dbal_url' is also missing, then we are in a very very wrong
        // situation, as DBAL would not be able to determine the driver it
        // needs to use.
        throw new ConnectionNotDefinedException(t('Database connection is not defined properly for the \'dbal\' driver. The \'dbal_url\' key is missing. Check the database connection definition in settings.php.'));
      }
      $dbal_connection = DbalDriverManager::getConnection([
        'url' => $connection_options['dbal_url'],
      ]);
      // Below shouldn't happen, but if it does, then use the driver name
      // from the just established DBAL connection.
      $connection_options['dbal_driver'] = $dbal_connection->getDriver()->getName();
    }

    $dbal_extension_class = static::getDbalExtensionClass($connection_options);
    try {
      $dbal_connection_options = static::mapConnectionOptionsToDbal($connection_options);
      $dbal_extension_class::preConnectionOpen($connection_options, $dbal_connection_options);
      $dbal_connection = DBALDriverManager::getConnection($dbal_connection_options);
      $dbal_extension_class::postConnectionOpen($dbal_connection, $connection_options, $dbal_connection_options);
    }
    catch (DbalConnectionException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
    return $dbal_connection;
  }

  /**
   * Create an array of DBAL connection options from the Drupal options.
   *
   * @param array $connection_options
   *   An array of DRUPAL options for the connection. May include the
   *   following:
   *   - prefix
   *   - namespace
   *   - Other driver-specific options.
   *
   * @return array
   *   An array of options suitable to establish a DBAL connection.
   */
  public static function mapConnectionOptionsToDbal(array $connection_options) {
    // Take away from the Drupal connection array the keys that will be
    // managed separately.
    $options = array_diff_key($connection_options, [
      'namespace' => NULL,
      'driver' => NULL,
      'prefix' => NULL,

      'database' => NULL,
      'username' => NULL,
      'password' => NULL,
      'host' => NULL,
      'port' => NULL,

      'pdo' => NULL,

      'dbal_url' => NULL,
      'dbal_driver' => NULL,
      'dbal_options' => NULL,
      'dbal_extension_class' => NULL,
      'dbal_statement_class' => NULL,
    ]);
    // Map to DBAL connection array the main keys from the Drupal connection.
    if (isset($connection_options['database'])) {
      $options['dbname'] = $connection_options['database'];
    }
    if (isset($connection_options['username'])) {
      $options['user'] = $connection_options['username'];
    }
    if (isset($connection_options['password'])) {
      $options['password'] = $connection_options['password'];
    }
    if (isset($connection_options['host'])) {
      $options['host'] = $connection_options['host'];
    }
    if (isset($connection_options['port'])) {
      $options['port'] =  $connection_options['port'];
    }
    if (isset($connection_options['dbal_url'])) {
      $options['url'] =  $connection_options['dbal_url'];
    }
    if (isset($connection_options['dbal_driver'])) {
      $options['driver'] = $connection_options['dbal_driver'];
    }
    // If there is a 'pdo' key in Drupal, that needs to be mapped to the
    // 'driverOptions' key in DBAL.
    $options['driverOptions'] = isset($connection_options['pdo']) ? $connection_options['pdo'] : [];
    // If there is a 'dbal_options' key in Drupal, merge it with the array
    // built so far. The content of the 'dbal_options' key will override
    // overlapping keys built so far.
    if (isset($connection_options['dbal_options'])) {
      $options = array_merge($options, $connection_options['dbal_options']);
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->dbalExtension->delegateQueryRange($query, $from, $count, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function queryTemporary($query, array $args = [], array $options = []) {
    $tablename = $this->generateTemporaryTableName();
    $this->dbalExtension->delegateQueryTemporary($tablename, $query, $args, $options);
    return $tablename;
  }

  /**
   * {@inheritdoc}
   */
  public function driver() {
    return 'dbal';
  }

  /**
   * {@inheritdoc}
   */
  public function databaseType() {
    return $this->getDbalConnection()->getDriver()->getDatabasePlatform()->getName();
  }

  /**
   * {@inheritdoc}
   */
  public function version() {
    // Return the DBAL version.
    return DbalVersion::VERSION;
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    try {
      $this->dbalExtension->preCreateDatabase($database);
      $this->getDbalConnection()->getSchemaManager()->createDatabase($database);
      $this->dbalExtension->postCreateDatabase($database);
    }
    catch (DBALException $e) {
      throw new DatabaseNotFoundException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mapConditionOperator($operator) {
    return $this->dbalExtension->delegateMapConditionOperator($operator);
  }

  /**
   * {@inheritdoc}
   */
  public function nextId($existing_id = 0) {
    return $this->dbalExtension->delegateNextId($existing_id);
  }

  /**
   * Prepares a query string and returns the prepared statement.
   *
   * This method caches prepared statements, reusing them when possible. It also
   * prefixes tables names enclosed in curly-braces.
   * Emulated prepared statements does not communicate with the database server
   * so this method does not check the statement.
   *
   * @param string $query
   *   The query string as SQL, with curly-braces surrounding the
   *   table names.
   * @param array $args
   *   An array of arguments for the prepared statement. If the prepared
   *   statement uses ? placeholders, this array must be an indexed array.
   *   If it contains named placeholders, it must be an associative array.
   * @param array $driver_options
   *   (optional) This array holds one or more key=>value pairs to set
   *   attribute values for the Statement object that this method returns.
   *
   * @return \Drupal\Core\Database\StatementInterface|false
   *   If the database server successfully prepares the statement, returns a
   *   StatementInterface object.
   *   If the database server cannot successfully prepare the statement  returns
   *   FALSE or emits an Exception (depending on error handling).
   */
  public function prepareQueryWithParams($query, array $args = [], array $driver_options = []) {
    $query = $this->prefixTables($query);
    return new $this->statementClass($this, $query, $args, $driver_options);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareQuery($query) {
    // Should not be used, because it fails to execute properly in case the
    // driver is not able to process named placeholders. Use
    // ::prepareQueryWithParams instead.
    // @todo raise an exception and fail hard??
    return $this->prepareQueryWithParams($query);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare($statement, array $driver_options = []) {
    // Should not be used, because it fails to execute properly in case the
    // driver is not able to process named placeholders. Use
    // ::prepareQueryWithParams instead.
    // @todo raise an exception and fail hard??
    return new $this->statementClass($this, $statement, [], $driver_options);
  }

  /**
   * {@inheritdoc}
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
        $this->getDbalConnection()->exec($this->dbalPlatform->rollbackSavePoint($savepoint));
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
    $this->connection->rollBack();
    if ($rolled_back_other_active_savepoints) {
      throw new TransactionOutOfOrderException();
    }
  }

  /**
   * {@inheritdoc}
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
      $this->getDbalConnection()->exec($this->dbalPlatform->createSavePoint($name));
    }
    else {
      $this->connection->beginTransaction();
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
        try {
          $this->getDbalConnection()->commit();
        }
        catch (DbalConnectionException $e) {
          throw new TransactionCommitFailedException();
        }
      }
      else {
        // Attempt to release this savepoint in the standard way.
        try {
          $this->getDbalConnection()->exec($this->dbalPlatform->releaseSavePoint($name));
        }
        catch (DbalDriverException $e) {
          // If all SAVEPOINTs were released automatically, clean the
          // transaction stack.
          if ($this->dbalExtension->delegateReleaseSavepointExceptionProcess($e) === 'all') {
            $this->transactionLayers = [];
          };
        }
      }
    }
  }

  /**
   * Gets the wrapped DBAL connection.
   *
   * @return string
   *   The DBAL connection wrapped by the extension object.
   */
  public function getDbalConnection() {
    return $this->dbalExtension->getDbalConnection();
  }

  /**
   * Gets the DBAL extension.
   *
   * @return \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   *   The DBAL extension for this connection.
   */
  public function getDbalExtension() {
    return $this->dbalExtension;
  }

  /**
   * Gets the DBAL extension class to use for the DBAL driver.
   *
   * @param array $connection_options
   *   An array of options for the connection.
   *
   * @return string
   *   The DBAL extension class.
   */
  public static function getDbalExtensionClass(array $connection_options) {
    if (isset($connection_options['dbal_extension_class'])) {
      return $connection_options['dbal_extension_class'];
    }
    $driver_name = $connection_options['dbal_driver'];
    if (isset(static::$driverSchemeAliases[$driver_name])) {
      $driver_name = static::$driverSchemeAliases[$driver_name];
    }
    return static::$dbalClassMap[$driver_name][0];
  }

  /**
   * Gets the Statement class to use for this connection.
   *
   * @param array $connection_options
   *   An array of options for the connection.
   *
   * @return string
   *   The Statement class.
   */
  public static function getStatementClass(array $connection_options) {
    if (isset($connection_options['dbal_statement_class'])) {
      return $connection_options['dbal_statement_class'];
    }
    $driver_name = $connection_options['dbal_driver'];
    if (isset(static::$driverSchemeAliases[$driver_name])) {
      $driver_name = static::$driverSchemeAliases[$driver_name];
    }
    return static::$dbalClassMap[$driver_name][1];
  }

  /**
   * Gets the database server version.
   *
   * @return string
   *   The database server version string.
   */
  public function getDbServerVersion() {
    return $this->getDbalConnection()->getWrappedConnection()->getServerVersion();
  }

  /**
   * {@inheritdoc}
   */
  public static function getConnectionInfoAsUrlHelper(array $connection_options, UriInterface $uri) {
    $uri = parent::getConnectionInfoAsUrlHelper($connection_options, $uri);
    // Add the 'dbal_driver' key to the URI.
    if (!empty($connection_options['dbal_driver'])) {
      $uri = Uri::withQueryValue($uri, 'dbal_driver', $connection_options['dbal_driver']);
    }
    return $uri;
  }

  /**
   * {@inheritdoc}
   */
  public static function convertDbUrlToConnectionInfoHelper(UriInterface $uri, $root, array $connection_options) {
    $connection_options = parent::convertDbUrlToConnectionInfoHelper($uri, $root, $connection_options);
    // Add the 'dbal_driver' key to the connection options.
    $parts = [];
    parse_str($uri->getQuery(), $parts);
    $dbal_driver = isset($parts['dbal_driver']) ? $parts['dbal_driver'] : '';
    $connection_options['dbal_driver'] = $dbal_driver;
    return $connection_options;
  }

  /**
   * Pushes an option to be retrieved by the Statement object.
   *
   * @todo try to remove
   *
   * @param string $option
   *   The option identifier.
   * @param string $value
   *   The option value.
   *
   * @return $this
   */
  public function pushStatementOption($option, $value) {
    if (!isset($this->statementOptions[$option])) {
      $this->statementOptions[$option] = [];
    }
    $this->statementOptions[$option][] = $value;
    return $this;
  }

  /**
   * Pops an option retrieved by the Statement object.
   *
   * @todo try to remove
   *
   * @param string $option
   *   The option identifier.
   *
   * @return mixed|null
   *   The option value, or NULL if missing.
   */
  public function popStatementOption($option) {
    if (!isset($this->statementOptions[$option]) || empty($this->statementOptions[$option])) {
      return NULL;
    }
    return array_pop($this->statementOptions[$option]);
  }

  /**
   * {@inheritdoc}
   */
  public function getFullQualifiedTableName($table) {
    return $this->getDbalExtension()->delegateFullQualifiedTableName($table);
  }

  /**
   * Returns the table prefixes array.
   *
   * @return array
   *   The connection options array.
   */
  public function getPrefixes() {
    return $this->prefixes;
  }

  /**
   * Set the list of prefixes used by this database connection.
   *
   * @param array|string $prefix
   *   Either a single prefix, or an array of prefixes, in any of the multiple
   *   forms documented in default.settings.php.
   */
  public function setPrefixPublic($prefix) {
    return $this->setPrefix($prefix);
  }
}
