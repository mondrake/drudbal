<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\RowCountException;
use Drupal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Mysqli\MysqliException;  // @todo no
use Doctrine\DBAL\SQLParserUtils;                 // @todo no if possible

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
   * @todo
   */
  protected $dbalStatement;

  /**
   * @var integer
   */
  protected $defaultFetchMode;

  /**
   * @todo
   *
   * @var string
   */
  protected $queryString;

  /**
   * @todo
   *
   * @var string
   */
  protected $fetchClass;

  /**
   * Constructs a MysqliDbalStatement object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $dbh
   *   The database connection object for this statement.
   */
  public function __construct(DruDbalConnection $dbh, $statement, $params, array $driver_options = []) {

    $this->queryString = $statement;

    $this->dbh = $dbh;
    $this->setFetchMode(\PDO::FETCH_OBJ);
    if (($allow_row_count = $this->dbh->popStatementOption('allowRowCount')) !== NULL) {  // @todo remove
      $this->allowRowCount = $allow_row_count;
    }

    // Replace named placeholders with positional ones if needed.
    if (!$this->dbh->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
      $statement = strtr($statement, [
         '\\\\' => "]]]]DOUBLESLASHESDRUDBAL[[[[",  // @todo remove once DBAL 2.5.13 is out
      ]);
      list($statement, $params) = SQLParserUtils::expandListParameters($statement, $params, []);
      $statement = strtr($statement, [
         "]]]]DOUBLESLASHESDRUDBAL[[[[" => '\\\\',  // @todo remove once DBAL 2.5.13 is out
      ]);
    }

    try {
      $this->dbalStatement = $dbh->getDbalConnection()->prepare($statement);
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
      $statement = strtr($this->queryString, [  // @todo remove once DBAL 2.5.13 is out
         '\\\\' => "]]]]DOUBLESLASHESDRUDBAL[[[[",
      ]);
      list($statement, $args) = SQLParserUtils::expandListParameters($statement, $args, []);
      $statement = strtr($statement, [  // @todo remove once DBAL 2.5.13 is out
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

    $statement_executed = $this->dbalStatement->execute($args);

    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start);
    }

    return true;
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

    if ($mode <= \PDO::FETCH_BOTH) {
      $row = $this->dbalStatement->fetch($mode);
      if ($mode === \PDO::FETCH_ASSOC) {
        foreach ($row as $column => &$value) {
          $value = (string) $value;
        }
      }
      return $row;
    }
    else {
      $row = $this->dbalStatement->fetch(\PDO::FETCH_ASSOC);
      if (!$row) {
        return FALSE;
      }
      switch ($mode) {
        case \PDO::FETCH_OBJ:
          $ret = new \stdClass();
          foreach ($row as $column => $value) {
            $ret->$column = (string) $value;
          }
          return $ret;

        case \PDO::FETCH_CLASS:
          $ret = new $this->fetchClass();
          foreach ($row as $column => $value) {
            $ret->$column = (string) $value;
          }
          return $ret;

        default:
          throw new MysqliException("Unknown fetch type '{$mode}'");  // @todo generic exc
      }
    }

 /*    $row = $this->dbalStatement->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
      return FALSE;
    }
    foreach ($row as $column => &$value) {
      $value = (string) $value;
    }
    switch ($mode) {
      case \PDO::FETCH_NUM:
        return array_values($row);

      case \PDO::FETCH_ASSOC:
        return $row;

      case \PDO::FETCH_BOTH:
        $row += array_values($row);
        return $row;

      case \PDO::FETCH_OBJ:
        return (object) $row;

      case \PDO::FETCH_CLASS:
        $ret = new $this->fetchClass();
        foreach ($row as $column => $value) {
          $ret->$column = $value;
        }
        return $ret;

      default:
        throw new MysqliException("Unknown fetch type '{$mode}'");  // @todo generic exc

    }*/
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    $mode = $mode ?: $this->defaultFetchMode;

    $rows = array();
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
      while (($row = $this->fetch($mode)) !== false) {
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
    return true;
  }

}
