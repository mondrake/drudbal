<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\RowCountException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\SQLParserUtils;

/**
 * DruDbal implementation of \Drupal\Core\Database\Statement.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Statement implements \IteratorAggregate, StatementInterface {

  /**
   * Reference to the database connection object for this statement.
   *
   * The name $dbh is inherited from \PDOStatement.
   *
   * @var \Drupal\Driver\Database\dbal\Connection
   */
  public $dbh;

  /**
   * Is rowCount() execution allowed.
   *
   * @var bool
   */
  public $allowRowCount = FALSE;

  /**
   * The DBAL statement.
   *
   * @var \Doctrine\DBAL\Statement
   */
  protected $dbalStatement;

  /**
   * The default fetch mode.
   *
   * See http://php.net/manual/pdo.constants.php for the definition of the
   * constants used.
   *
   * @var int
   */
  protected $defaultFetchMode;

  /**
   * The query string, in its form with placeholders.
   *
   * @var string
   */
  protected $queryString;

  /**
   * The class to be used for returning row results.
   *
   * Used when fetch mode is \PDO::FETCH_CLASS.
   *
   * @var string
   */
  protected $fetchClass;

  /**
   * Constructs a Statement object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $dbh
   *   The database connection object for this statement.
   * @param string $statement
   *   A string containing an SQL query. Passed by reference.
   * @param array $params
   *   (optional) An array of values to substitute into the query at placeholder
   *   markers. Passed by reference.
   * @param array $driver_options
   *   (optional) An array of driver options for this query.
   */
  public function __construct(DruDbalConnection $dbh, &$statement, array &$params, array $driver_options = []) {
    $this->queryString = $statement;
    $this->dbh = $dbh;
    $this->setFetchMode(\PDO::FETCH_OBJ);

    // Replace named placeholders with positional ones if needed.
    if (!$this->dbh->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
      // @todo remove once DBAL 2.5.13 is out
      $statement = strtr($statement, [
        '\\\\' => "]]]]DOUBLESLASHESDRUDBAL[[[[",
      ]);
      list($statement, $params) = SQLParserUtils::expandListParameters($statement, $params, []);
      // @todo remove once DBAL 2.5.13 is out
      $statement = strtr($statement, [
        "]]]]DOUBLESLASHESDRUDBAL[[[[" => '\\\\',
      ]);
    }

    try {
      $this->dbh->getDbalExtension()->alterStatement($statement, $params);
      $this->dbalStatement = $dbh->getDbalConnection()->prepare($statement);
if ($this->dbh->getDbalExtension()->getDebugging()) {
  $xx = $this->dbalStatement->getWrappedStatement();
  oci_execute($xx, OCI_DESCRIBE_ONLY); // Use OCI_DESCRIBE_ONLY if not fetching rows
  $ncols = oci_num_fields($xx);
  for ($i = 1; $i <= $ncols; $i++) {
    error_log(oci_field_name($xx, $i) . ' - ' . oci_field_type($xx, $i) . ' - ' . oci_field_size($xx, $i));
  }
  $this->dbh->getDbalExtension()->setDebugging(FALSE);
}
    }
    catch (DBALException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    // Replace named placeholders with positional ones if needed.
    if (!$this->dbh->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
      // @todo remove once DBAL 2.5.13 is out
      $statement = strtr($this->queryString, [
        '\\\\' => "]]]]DOUBLESLASHESDRUDBAL[[[[",
      ]);
      list($statement, $args) = SQLParserUtils::expandListParameters($statement, $args, []);
      // @todo remove once DBAL 2.5.13 is out
      $statement = strtr($statement, [
        "]]]]DOUBLESLASHESDRUDBAL[[[[" => '\\\\',
      ]);
    }

    if (isset($options['fetch'])) {
      if (is_string($options['fetch'])) {
        $this->setFetchMode(\PDO::FETCH_CLASS, $options['fetch']);
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    $logger = $this->dbh->getLogger();
    if (!empty($logger)) {
      $query_start = microtime(TRUE);
    }

    $this->dbalStatement->execute($args);

    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    if (is_string($mode)) {
      $this->setFetchMode(\PDO::FETCH_CLASS, $mode);
      $mode = \PDO::FETCH_CLASS;
    }
    else {
      $mode = $mode ?: $this->defaultFetchMode;
    }

    return $this->dbh->getDbalExtension()->delegateFetch($this->dbalStatement, $mode, $this->fetchClass);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    if (is_string($mode)) {
      $this->setFetchMode(\PDO::FETCH_CLASS, $mode);
      $mode = \PDO::FETCH_CLASS;
    }
    else {
      $mode = $mode ?: $this->defaultFetchMode;
    }

    $rows = [];
    if (\PDO::FETCH_COLUMN == $mode) {
      if ($column_index === NULL) {
        $column_index = 0;
      }
      while (($record = $this->fetch(\PDO::FETCH_ASSOC)) !== FALSE) {
        $cols = array_keys($record);
        $rows[] = $record[$cols[$column_index]];
      }
    }
    else {
      while (($row = $this->fetch($mode)) !== FALSE) {
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator() {
    return new \ArrayIterator($this->fetchAll());
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryString() {
    return $this->queryString;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchCol($index = 0) {
    $ret = $this->fetchAll(\PDO::FETCH_COLUMN, $index);
    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllAssoc($key, $fetch = NULL) {
    $return = [];
    if (isset($fetch)) {
      if (is_string($fetch)) {
        $this->setFetchMode(\PDO::FETCH_CLASS, $fetch);
      }
      else {
        $this->setFetchMode($fetch ?: $this->defaultFetchMode);
      }
    }

    while ($record = $this->fetch()) {
      $record_key = is_object($record) ? $record->$key : $record[$key];
      $return[$record_key] = $record;
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    $return = [];
    $this->setFetchMode(\PDO::FETCH_ASSOC);
    while ($record = $this->fetch(\PDO::FETCH_ASSOC)) {
      $cols = array_keys($record);
      $return[$record[$cols[$key_index]]] = $record[$cols[$value_index]];
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    $record = $this->fetch(\PDO::FETCH_ASSOC);
    if (!$record) {
      return FALSE;
    }
    $cols = array_keys($record);
    $ret = $record[$cols[$index]];
    return empty($ret) ? NULL : (string) $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAssoc() {
    return $this->fetch(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject() {
    return $this->fetch(\PDO::FETCH_OBJ);
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use the method.
    if ($this->allowRowCount) {
      return $this->dbh->getDbalExtension()->delegateRowCount($this->dbalStatement);
    }
    else {
      throw new RowCountException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
    $this->defaultFetchMode = $mode;
    if ($mode === \PDO::FETCH_CLASS) {
      $this->fetchClass = $a1;
    }
    return TRUE;
  }

}
