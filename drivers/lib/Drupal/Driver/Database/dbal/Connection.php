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

use Drupal\Driver\Database\dbal\DbalExtension\PDOMySql;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager as DbalDriverManager;
use Doctrine\DBAL\Statement as DbalStatement;
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
   * List of supported drivers and their mappings to the DBAL extension
   * classes.
   *
   * @var string[]
   */
  protected static $dbalExtensionClassMap = array(
    'pdo_mysql' => PDOMySql::class,
//    'pdo_sqlite'         => 'PDOSqlite',
//    'pdo_pgsql'          => 'PDOPgSql',
//    'pdo_oci'            => 'PDOOracle',
//    'oci8'               => 'OCI8',
//    'ibm_db2'            => 'IBMDB2\DB2Driver',
//    'pdo_sqlsrv'         => 'PDOSqlsrv',
//    'mysqli'             => 'Mysqli',
//    'drizzle_pdo_mysql'  => 'DrizzlePDOMySql',
//    'sqlanywhere'        => 'SQLAnywhere',
//    'sqlsrv'             => 'SQLSrv',
  );

  /**
   * List of URL schemes from a database URL and their mappings to driver.
   *
   * @var string[]
   */
  protected static $driverSchemeAliases = array(
//    'db2'        => 'ibm_db2',
//    'mssql'      => 'pdo_sqlsrv',
    'mysql'      => 'pdo_mysql',
    'mysql2'     => 'pdo_mysql', // Amazon RDS, for some weird reason
//    'postgres'   => 'pdo_pgsql',
//    'postgresql' => 'pdo_pgsql',
//    'pgsql'      => 'pdo_pgsql',
//    'sqlite'     => 'pdo_sqlite',
//    'sqlite3'    => 'pdo_sqlite',
  );

  /**
   * The DruDbal extension for the DBAL driver.
   *
   * @var \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   */
  protected $dbalExt;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DbalConnection $dbal_connection, array $connection_options = []) {
    $dbal_extension_class = static::getDbalExtensionClass($dbal_connection->getDriver()->getName());
    $this->dbalExt = new $dbal_extension_class($this, $dbal_connection);
    $this->transactionSupport = $this->dbalExt->transactionSupport($connection_options);
    $this->transactionalDDLSupport = $this->dbalExt->transactionalDDLSupport($connection_options);
    $this->setPrefix(isset($connection_options['prefix']) ? $connection_options['prefix'] : '');
    $this->connectionOptions = $connection_options;
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
    $this->dbalExt->destroy();
    $this->schema = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalExt->clientVersion();
  }

  /**
   * {@inheritdoc}
   *
   * @todo clean this up further.
   */
  public function query($query, array $args = [], $options = []) {
    // Use default values if not already set.
    $options += $this->defaultOptions();

    try {
      // We allow either a pre-bound statement object or a literal string.
      // In either case, we want to end up with an executed statement object,
      // which we pass to PDOStatement::execute.
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

        // Resolve tables' names with prefix.
        $query = $this->prefixTables($query);

        // Prepare a statement.
        $stmt = $this->connection->getWrappedConnection()->prepare($query, $options);

        // Execute statement.
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
          return $this->connection->lastInsertId($sequence_name);
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
      return $this->dbalExt->handleQueryException($e, $query, $args, $options);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    if (!empty($connection_options['dbal_driver'])) {
      $dbal_driver = $connection_options['dbal_driver'];
    }
    else {
      // If 'dbal_driver' is missing from the connection options, then we are
      // likely in an installation scenario where the database URL is invalid.
      // Try establishing a DBAL connection to clarify details.
      if (empty($connection_options['dbal_url'])) {
        // If 'dbal_url' is also missing, then we are in a very very wrong
        // situation, as DBAL would not be able to determine the driver it
        // needs to use.
        throw new ConnectionNotDefinedException(t('Database connection is not defined properly for the \'dbal\' driver. The \'dbal_url\' key is missing. Check the database connection definition in settings.php.'));
      }
      $options = [];
      $options['url'] = $connection_options['dbal_url'];
      $dbal_connection = DbalDriverManager::getConnection($options);
      // Below shouldn't happen, but if it does, then use the driver name
      // from the just established DBAL connection.
      $dbal_driver = $dbal_connection->getDriver()->getName();
    }
    $dbal_extension_class = static::getDbalExtensionClass($dbal_driver);
    return $dbal_extension_class::open($connection_options);
  }

  /**
   * {@inheritdoc}
   */
  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    try {
      return $this->dbalExt->queryRange($query, $from, $count, $args, $options);
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function queryTemporary($query, array $args = [], array $options = []) {
    try {
      $tablename = $this->generateTemporaryTableName();
      $this->dbalExt->queryTemporary($tablename, $query, $args, $options);
      return $tablename;
    }
    catch (DBALException $e) {
      throw new \Exception($e->getMessage());
    }
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
      $this->dbalExt->preCreateDatabase($database);
      $this->getDbalConnection()->getSchemaManager()->createDatabase($database);
      $this->dbalExt->postCreateDatabase($database);
    }
    catch (DBALException $e) {
      throw new DatabaseNotFoundException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mapConditionOperator($operator) {
    // We don't want to override any of the defaults.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function nextId($existing_id = 0) {
    return $this->dbalExt->nextId($existing_id);
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
        if ($this->dbalExt->releaseSavepoint($name) === 'all') {
          $this->transactionLayers = [];
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
    return $this->dbalExt->getDbalConnection();
  }

  /**
   * Gets the DBAL extension.
   *
   * @return \Drupal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   *   The DBAL extension for this connection.
   */
  public function getDbalExtension() {
    return $this->dbalExt;
  }

  /**
   * Gets the DBAL extension class.
   *
   * @return string
   *   The DBAL extension class.
   */
  public static function getDbalExtensionClass($driver_name) {
    if (isset(static::$driverSchemeAliases[$driver_name])) {
      $driver_name = static::$driverSchemeAliases[$driver_name];
    }
    return static::$dbalExtensionClassMap[$driver_name];
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

}
