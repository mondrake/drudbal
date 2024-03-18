<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\DriverManager as DbalDriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\DBAL\ExpandArrayParameters;
use Doctrine\DBAL\Platforms\AbstractPlatform as DbalAbstractPlatform;
use Doctrine\DBAL\SQL\Parser as DbalParser;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type as DbalType;
use Drupal\Component\Uuid\Php as Uuid;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Utility\Error;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\MysqliExtension;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\Oci8Extension;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\PDOMySqlExtension;
use Drupal\drudbal\Driver\Database\dbal\DbalExtension\PDOSqliteExtension;
use GuzzleHttp\Psr7\Uri;

/**
 * DruDbal implementation of \Drupal\Core\Database\Connection.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Connection extends DatabaseConnection {

  /**
   * Supported DBAL drivers and DBAL extension classes to use.
   *
   * @var string[]
   */
  protected static $dbalClassMap = [
    'mysqli' => MysqliExtension::class,
    'oci8' => Oci8Extension::class,
    'pdo_mysql' => PDOMySqlExtension::class,
    'pdo_sqlite' => PDOSqliteExtension::class,
  ];

  /**
   * Map of database tables.
   *
   * Drupal SQL statements wrap table names in curly brackets. This array
   * maps this syntax to actual database tables, adding prefix and/or
   * resolving platform specific constraints.
   *
   * @var array<string, string>
   */
  protected array $dbTables = [];

  /**
   * List of URL schemes from a database URL and their mappings to driver.
   *
   * @var string[]
   */
  protected static $driverSchemeAliases = [
    'mysql' => 'pdo_mysql',
    'mysql2' => 'pdo_mysql',
    'sqlite' => 'pdo_sqlite',
    'sqlite3' => 'pdo_sqlite',
  ];

  /**
   * The DruDbal extension for the DBAL driver.
   */
  protected DbalExtensionInterface $dbalExtension;

  /**
   * The platform SQL parser.
   */
  protected DbalParser $parser;

  /**
   * Constructs a Connection object.
   */
  public function __construct(DbalConnection $dbal_connection, array $connection_options = []) {
    $this->connection = $dbal_connection;
    $this->connectionOptions = $connection_options;

    $quote_identifier = $dbal_connection->getDatabasePlatform()->quoteIdentifier('');
    $this->identifierQuotes = [$quote_identifier[0], $quote_identifier[1]];

    $this->setPrefix($connection_options['prefix'] ?? '');

    $dbal_extension_class = static::getDbalExtensionClass($connection_options);
    $this->dbalExtension = new $dbal_extension_class($this);
    $this->statementWrapperClass = $this->dbalExtension->getStatementClass();
    $this->transactionalDDLSupport = $this->dbalExtension->delegateTransactionalDdlSupport($connection_options);
  }

  /**
   * Destructs a Connection object.
   */
  public function __destruct() {
    $this->schema = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function attachDatabase(string $database): void {
    $this->dbalExtension->delegateAttachDatabase($database);
  }

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalExtension->delegateClientVersion();
  }

  /**
   * {@inheritdoc}
   */
  public function quoteIdentifiers($sql) {
    preg_match_all('/(\[(.+?)\])/', $sql, $matches);
    $ids = [];
    $i = 0;
    foreach($matches[1] as $m) {
      $ids[$m] = $this->getDbalExtension()->getDbFieldName($matches[2][$i], TRUE);
      $i++;
    }
    return strtr($sql, $ids);
  }

  /**
   * {@inheritdoc}
   */
  public function prefixTables($sql) {
    $matches = [];
    preg_match_all('/{(\S*)}/', $sql, $matches, PREG_SET_ORDER, 0);
    foreach ($matches as $match) {
      $table = $match[1];
      if (isset($this->dbTables['{' . $table . '}'])) {
        continue;
      }
      $this->dbTables['{' . $table . '}'] = $this->identifierQuotes[0] . $this->dbalExtension->getDbTableName($this->getPrefix(), $table) . $this->identifierQuotes[1];
    }
    return str_replace(array_keys($this->dbTables), array_values($this->dbTables), $sql);
  }

  /**
   * Returns a prefixed table name.
   *
   * @param string $table_name
   *   A Drupal table name.
   * @param bool $quoted
   *   (Optional) If TRUE, the returned table name is wrapped into identifier
   *   quotes.
   *
   * @return string
   *   A fully prefixed table name, suitable for direct usage in db queries.
   */
  public function getPrefixedTableName(string $table_name, bool $quoted = FALSE): string {
    // If the table name is enclosed in curly braces, remove them first.
    $matches = [];
    if (preg_match('/^{(\S*)}/', $table_name, $matches) === 1) {
      $table_name = $matches[1];
    }

    $prefixed_table_name = $this->prefixTables('{' . $table_name . '}');
    // @todo use substr  instead
    return $quoted ? $prefixed_table_name : str_replace($this->identifierQuotes, ['', ''], $prefixed_table_name);
  }

  /**
   * {@inheritdoc}
   */
  public function lastInsertId(?string $name = NULL): string {
    // @todo DBAL 4 dropped the sequence name, delegate to driver if needed.
    return (string) $this->getDbalConnection()->lastInsertId();
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
        throw new ConnectionNotDefinedException("Database connection is not defined properly for the 'dbal' driver. The 'dbal_url' key is missing. Check the database connection definition in settings.php.");
      }
      $dbal_connection = DbalDriverManager::getConnection((new DsnParser())->parse($connection_options['dbal_url']));
      // Below shouldn't happen, but if it does, then use the driver name
      // from the just established DBAL connection.
      $uri = new Uri($connection_options['dbal_url']);
      $connection_options['dbal_driver'] = $uri->getScheme();
    }

    $connection_options['dbal_driver'] = str_replace('-', '_', $connection_options['dbal_driver']);

    $dbal_extension_class = static::getDbalExtensionClass($connection_options);
    try {
      $dbal_connection_options = static::mapConnectionOptionsToDbal($connection_options);
      $dbal_extension_class::preConnectionOpen($connection_options, $dbal_connection_options);
      $dbal_connection = DbalDriverManager::getConnection($dbal_connection_options);
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
    ]);
    // Map to DBAL connection array the main keys from the Drupal connection.
    if (!empty($connection_options['database'])) {
      $options['dbname'] = $connection_options['database'];
    }
    if (!empty($connection_options['username'])) {
      $options['user'] = $connection_options['username'];
    }
    if (!empty($connection_options['password'])) {
      $options['password'] = $connection_options['password'];
    }
    if (!empty($connection_options['host'])) {
      $options['host'] = $connection_options['host'];
    }
    if (!empty($connection_options['port'])) {
      $options['port'] = $connection_options['port'];
    }
    if (!empty($connection_options['dbal_url'])) {
      $options['url'] = $connection_options['dbal_url'];
    }
    if (!empty($connection_options['dbal_driver'])) {
      $options['driver'] = $connection_options['dbal_driver'];
    }
    // If there is a 'pdo' key in Drupal, that needs to be mapped to the
    // 'driverOptions' key in DBAL.
    $options['driverOptions'] = $connection_options['pdo'] ?? [];
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
  public function driver() {
    return 'dbal';
  }

  /**
   * {@inheritdoc}
   */
  public function databaseType() {
    return $this->dbalExtension->getDbServerPlatform();
  }

  /**
   * {@inheritdoc}
   */
  public function version() {
    // Return the DBAL version.
    return InstalledVersions::getPrettyVersion('doctrine/dbal');
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    try {
      $this->dbalExtension->preCreateDatabase($database);
      $this->getDbalConnection()->createSchemaManager()->createDatabase($database);
      $this->dbalExtension->postCreateDatabase($database);
    }
    catch (DbalException $e) {
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
    @trigger_error('Drupal\Core\Database\Connection::nextId() is deprecated in drupal:10.2.0 and is removed from drupal:11.0.0. Modules should use instead the keyvalue storage for the last used id. See https://www.drupal.org/node/3349345', E_USER_DEPRECATED);
    $id = is_numeric($existing_id ?? 0) ? ($existing_id ?? 0) : 0;
    return $this->dbalExtension->delegateNextId($id);
  }

  /**
   * {@inheritdoc}
   */
  public function escapeField($field) {
    return $this->getDbalExtension()->getDbFieldName($field, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function escapeAlias($field) {
    return $this->getDbalExtension()->getDbAlias($field, TRUE);
  }

  /**
   * Gets the wrapped DBAL connection.
   *
   * @return \Doctrine\DBAL\Connection
   *   The DBAL connection wrapped by the extension object.
   */
  public function getDbalConnection(): DbalConnection {
    return $this->connection;
  }

  /**
   * Gets the DBAL extension.
   *
   * @return \Drupal\drudbal\Driver\Database\dbal\DbalExtension\DbalExtensionInterface
   *   The DBAL extension for this connection.
   */
  public function getDbalExtension() {
    return $this->dbalExtension;
  }

  /**
   * Gets the DBAL platform.
   *
   * @return \Doctrine\DBAL\Platforms\AbstractPlatform
   *   The DBAL platform for this connection.
   */
  public function getDbalPlatform(): DbalAbstractPlatform {
    return $this->getDbalConnection()->getDatabasePlatform();
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
    $driver_name = $connection_options['dbal_driver'];
    if (isset(static::$driverSchemeAliases[$driver_name])) {
      $driver_name = static::$driverSchemeAliases[$driver_name];
    }
    return static::$dbalClassMap[$driver_name];
  }

  /**
   * {@inheritdoc}
   */
  public static function createUrlFromConnectionOptions(array $connection_options) {
    $uri = new Uri();

    // Driver name as the URI scheme.
    $uri = $uri->withScheme($connection_options['driver']);

    // User credentials if existing.
    if (isset($connection_options['username'])) {
      $uri = $uri->withUserInfo($connection_options['username'], $connection_options['password'] ?? NULL);
    }

    $uri = $uri->withHost($connection_options['host'] ?? 'localhost');

    if (!empty($connection_options['port'])) {
      $uri = $uri->withPort($connection_options['port']);
    }

    $uri = $uri->withPath('/' . $connection_options['database']);

    // Add the 'module' key to the URI.
    $uri = Uri::withQueryValue($uri, 'module', 'drudbal');

    // Add the 'dbal_driver' key to the URI.
    if (!empty($connection_options['dbal_driver'])) {
      $uri = Uri::withQueryValue($uri, 'dbal_driver', $connection_options['dbal_driver']);
    }

    // Table prefix as the URI fragment.
    if ($connection_options['prefix'] !== '') {
      $uri = $uri->withFragment($connection_options['prefix']);
    }

    return (string) $uri;
  }

  /**
   * {@inheritdoc}
   */
  public static function createConnectionOptionsFromUrl($url, $root) {
    $uri = new Uri($url);
    if (empty($uri->getHost()) || empty($uri->getScheme()) || empty($uri->getPath())) {
      throw new \InvalidArgumentException('Minimum requirement: driver://host/database');
    }

    // Use reflection to get the namespace of the class being called.
    $reflector = new \ReflectionClass(get_called_class());

    // Build the connection information array.
    $connection_options = [
      'driver' => $uri->getScheme(),
      'host' => $uri->getHost(),
      // Strip the first leading slash of the path to get the database name.
      // Note that additional leading slashes have meaning for some database
      // drivers.
      'database' => substr($uri->getPath(), 1),
      'prefix' => $uri->getFragment() ?: '',
      'namespace' => $reflector->getNamespaceName(),
    ];

    $port = $uri->getPort();
    if (!empty($port)) {
      $connection_options['port'] = $port;
    }

    $user_info = $uri->getUserInfo();
    if (!empty($user_info)) {
      $user_info_elements = explode(':', $user_info, 2);
      $connection_options['username'] = $user_info_elements[0];
      $connection_options['password'] = $user_info_elements[1] ?? '';
    }

    // Add the 'dbal_driver' key to the connection options.
    $parts = [];
    parse_str($uri->getQuery(), $parts);
    $dbal_driver = $parts['dbal_driver'] ?? '';
    $connection_options['dbal_driver'] = $dbal_driver;

    return $connection_options;
  }

  /**
   * {@inheritdoc}
   */
  public function getFullQualifiedTableName($table) {
    return $this->getDbalExtension()->getDbFullQualifiedTableName($table);
  }

  /**
   * Set the list of prefixes used by this database connection.
   *
   * @param string $prefix
   *   A single prefix.
   */
  public function setPrefixPublic(string $prefix): void {
    $this->setPrefix($prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function hasJson(): bool {
    return $this->getDbalExtension()->delegateHasJson();
  }

  /**
   * @param array<int, mixed>|array<string, mixed>                                       $params
   * @param array<int, int|string|DbalType|null>|array<string, int|string|DbalType|null> $types
   *
   * @return array{string, list<mixed>, array<int,DbalType|int|string|null>}
   */
  public function expandArrayParameters(string $sql, array $params, array $types): array {
    if (!isset($this->parser)) {
      $this->parser = $this->getDbalConnection()->getDatabasePlatform()->createSQLParser();
    }

    $pms = [];
    foreach($params as $k => $v) {
      $pms[substr($k, 1)] = $v;
    }

    $visitor = new ExpandArrayParameters($pms, $types);

    $this->parser->parse($sql, $visitor);

    return [
      $visitor->getSQL(),
      $visitor->getParameters(),
      $visitor->getTypes(),
    ];
  }

  public function query($query, array $args = [], $options = []) {
    if ($this->dbalExtension->getDebugging()) {
      dump([$query, $args]);
    }
    return parent::query($query, $args, $options);
  }

  public function exceptionHandler() {
    return new ExceptionHandler($this);
  }

  public function select($table, $alias = NULL, array $options = []) {
    return new Select($this, $table, $alias, $options);
  }

  public function insert($table, array $options = []) {
    return new Insert($this, $table, $options);
  }

  public function merge($table, array $options = []) {
    return new Merge($this, $table, $options);
  }

  public function upsert($table, array $options = []) {
    return new Upsert($this, $table, $options);
  }

  public function update($table, array $options = []) {
    return new Update($this, $table, $options);
  }

  public function delete($table, array $options = []) {
    return new Delete($this, $table, $options);
  }

  public function truncate($table, array $options = []) {
    return new Truncate($this, $table, $options);
  }

  public function schema(): Schema {
    if (!isset($this->schema)) {
      $this->schema = new Schema($this);
    }
    $schema = $this->schema;
    assert($schema instanceof Schema);
    return $schema;
  }

  /**
   * @todo remove in D11
   */
  public function condition($conjunction) {
    return new Condition($conjunction);
  }

  /**
   * {@inheritdoc}
   */
  protected function driverTransactionManager(): TransactionManagerInterface {
    return new TransactionManager($this);
  }

}
