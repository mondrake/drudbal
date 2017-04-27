<?php

namespace Drupal\Driver\Database\dbal\Statement;

use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\RowCountException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\Driver\Mysqli\MysqliException;
use Doctrine\DBAL\SQLParserUtils;

/**
 * DruDbal implementation of StatementInterface for Mysqli connections.
 */
class MysqliDbalStatement extends \PDOStatement implements StatementInterface {

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
   * Constructs a PDODbalStatement object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $dbh
   *   The database connection object for this statement.
   */
  public function __construct(DruDbalConnection $dbh, $statement, array $driver_options = []) {
    $this->dbh = $dbh;
    $this->setFetchMode(\PDO::FETCH_OBJ);
    if (($allow_row_count = $this->dbh->popStatementOption('allowRowCount')) !== NULL) {
      $this->allowRowCount = $allow_row_count;
    }
//var_export(get_class($dbh));
//var_export(get_class($dbh->getDbalConnection()));
//var_export(get_class($dbh->getDbalConnection()->getWrappedConnection()));
    $conn = $dbh->getDbalConnection()->getWrappedConnection()->getWrappedResourceHandle();
//var_export(get_class($conn));
var_export($statement);
//$statement = str_replace(':sid', '?', $statement);
    $stmt = $conn->prepare($statement);
    if (false === $stmt) {
        throw new MysqliException($conn->error, $conn->sqlstate, $conn->errno);
    }
var_export($stmt);
die;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = NULL, $options = []) {
    if (isset($options['fetch'])) {
      if (is_string($options['fetch'])) {
        // \PDO::FETCH_PROPS_LATE tells __construct() to run before properties
        // are added to the object.
        $this->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $options['fetch']);
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    $logger = $this->dbh->getLogger();
    if (!empty($logger)) {
      $query_start = microtime(TRUE);
    }

    if (is_array($args)) {
      $return = parent::execute($args);
    }
    elseif ($args === NULL) {
      $return = parent::execute();
    }

    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start);
    }

    return $return;
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
    return $this->fetchAll(\PDO::FETCH_COLUMN, $index);
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
        $this->setFetchMode($fetch);
      }
    }

    foreach ($this as $record) {
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
    $this->setFetchMode(\PDO::FETCH_NUM);
    foreach ($this as $record) {
      $return[$record[$key_index]] = $record[$value_index];
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    // Call \PDOStatement::fetchColumn to fetch the field.
    return $this->fetchColumn($index);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAssoc() {
    // Call \PDOStatement::fetch to fetch the row.
    return $this->fetch(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use the method.
    if ($this->allowRowCount) {
      return parent::rowCount();
    }
    else {
      throw new RowCountException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
    // Call \PDOStatement::setFetchMode to set fetch mode.
    // \PDOStatement is picky about the number of arguments in some cases so we
    // need to be pass the exact number of arguments we where given.
    switch (func_num_args()) {
      case 1:
        return parent::setFetchMode($mode);
      case 2:
        return parent::setFetchMode($mode, $a1);
      case 3:
      default:
        return parent::setFetchMode($mode, $a1, $a2);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    // Call \PDOStatement::fetchAll to fetch all rows.
    // \PDOStatement is picky about the number of arguments in some cases so we
    // need to be pass the exact number of arguments we where given.
    switch (func_num_args()) {
      case 0:
        return parent::fetchAll();
      case 1:
        return parent::fetchAll($mode);
      case 2:
        return parent::fetchAll($mode, $column_index);
      case 3:
      default:
        return parent::fetchAll($mode, $column_index, $constructor_arguments);
    }
  }

}
