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
class MysqliDbalStatement implements \IteratorAggregate, StatementInterface {

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
   * @var array
   */
  protected static $_paramTypeMap = array(
      \PDO::PARAM_STR => 's',
      \PDO::PARAM_BOOL => 'i',
      \PDO::PARAM_NULL => 's',
      \PDO::PARAM_INT => 'i',
      \PDO::PARAM_LOB => 's' // TODO Support LOB bigger then max package size.
  );

  /**
   * @var \mysqli
   */
  protected $_conn;

  /**
   * @var \mysqli_stmt
   */
  protected $_stmt;

  /**
   * @var null|boolean|array
   */
  protected $_columnNames;

  /**
   * @var null|array
   */
  protected $_rowBindedValues;

  /**
   * @var array
   */
  protected $_bindedValues;

  /**
   * @var string
   */
  protected $types;

  /**
   * Contains ref values for bindValue().
   *
   * @var array
   */
  protected $_values = array();

  /**
   * @var integer
   */
  protected $_defaultFetchMode;

  /**
   * Indicates whether the statement is in the state when fetching results is possible
   *
   * @var bool
   */
  protected $result = false;

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
    if (($allow_row_count = $this->dbh->popStatementOption('allowRowCount')) !== NULL) {
      $this->allowRowCount = $allow_row_count;
    }

    list($positional_statement, $positional_params, $positional_types) = SQLParserUtils::expandListParameters($statement, $params, []);
    $this->_conn = $dbh->getDbalConnection()->getWrappedConnection()->getWrappedResourceHandle();
    $this->_stmt = $this->_conn->prepare($positional_statement);

    if (false === $this->_stmt) {
        throw new MysqliException($this->_conn->error, $this->_conn->sqlstate, $this->_conn->errno);
    }

    $paramCount = $this->_stmt->param_count;
    if (0 < $paramCount) {
        $this->types = str_repeat('s', $paramCount);
        $this->_bindedValues = array_fill(1, $paramCount, null);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    if (isset($options['fetch'])) {
      if (is_string($options['fetch'])) {
        // @todo remove these comments??? \PDO::FETCH_PROPS_LATE tells __construct() to run before properties
        // are added to the object.
        $this->setFetchMode(\PDO::FETCH_CLASS);
        $this->fetchClass = $options['fetch'];
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    $logger = $this->dbh->getLogger();
    if (!empty($logger)) {
      $query_start = microtime(TRUE);
    }

    if (null !== $this->_bindedValues) {
      if (null !== $args) {
        if ( ! $this->_bindValues($args)) {
          throw new MysqliException($this->_stmt->error, $this->_stmt->errno);
        }
      } else {
        if (!call_user_func_array(array($this->_stmt, 'bind_param'), array($this->types) + $this->_bindedValues)) {
          throw new MysqliException($this->_stmt->error, $this->_stmt->sqlstate, $this->_stmt->errno);
        }
      }
    }

    if ( ! $this->_stmt->execute()) {
      throw new MysqliException($this->_stmt->error, $this->_stmt->sqlstate, $this->_stmt->errno);
    }

    if (null === $this->_columnNames) {
      $meta = $this->_stmt->result_metadata();
      if (false !== $meta) {
        $columnNames = array();
        foreach ($meta->fetch_fields() as $col) {
          $columnNames[] = $col->name;
        }
        $meta->free();
        $this->_columnNames = $columnNames;
      }
      else {
        $this->_columnNames = false;
      }
    }

    if (false !== $this->_columnNames) {
      // Store result of every execution which has it. Otherwise it will be impossible
      // to execute a new statement in case if the previous one has non-fetched rows
      // @link http://dev.mysql.com/doc/refman/5.7/en/commands-out-of-sync.html
      $this->_stmt->store_result();

      // Bind row values _after_ storing the result. Otherwise, if mysqli is compiled with libmysql,
      // it will have to allocate as much memory as it may be needed for the given column type
      // (e.g. for a LONGBLOB field it's 4 gigabytes)
      // @link https://bugs.php.net/bug.php?id=51386#1270673122
      //
      // Make sure that the values are bound after each execution. Otherwise, if closeCursor() has been
      // previously called on the statement, the values are unbound making the statement unusable.
      //
      // It's also important that row values are bound after _each_ call to store_result(). Otherwise,
      // if mysqli is compiled with libmysql, subsequently fetched string values will get truncated
      // to the length of the ones fetched during the previous execution.
      $this->_rowBindedValues = array_fill(0, count($this->_columnNames), null);

      $refs = array();
      foreach ($this->_rowBindedValues as $key => &$value) {
        $refs[$key] =& $value;
      }

      if (!call_user_func_array(array($this->_stmt, 'bind_result'), $refs)) {
        throw new MysqliException($this->_stmt->error, $this->_stmt->sqlstate, $this->_stmt->errno);
      }
    }

    $this->result = true;

    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start);
    }

    return true;
  }

  /**
   * Binds a array of values to bound parameters.
   *
   * @param array $values
   *
   * @return boolean
   */
  protected function _bindValues($values) {
    $params = [];
    $types = str_repeat('s', count($values));
    $params[0] = $types;

    foreach ($values as &$v) {
      $params[] =& $v;
    }

    return call_user_func_array([$this->_stmt, 'bind_param'], $params);
  }

  /**
   * @return boolean|array
   */
  protected function _fetch() {
    $ret = $this->_stmt->fetch();

    if (true === $ret) {
      $values = [];
      foreach ($this->_rowBindedValues as $v) {
        $values[] = $v;
      }

      return $values;
    }

    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    // do not try fetching from the statement if it's not expected to contain result
    // in order to prevent exceptional situation
    if (!$this->result) {
      return false;
    }

    $values = $this->_fetch();
    if (null === $values) {
      return false;
    }

    if (false === $values) {
      throw new MysqliException($this->_stmt->error, $this->_stmt->sqlstate, $this->_stmt->errno);
    }

    $mode = $mode ?: $this->_defaultFetchMode;

    switch ($mode) {
      case \PDO::FETCH_NUM:
        return $values;

      case \PDO::FETCH_ASSOC:
        return array_combine($this->_columnNames, $values);

      case \PDO::FETCH_BOTH:
        $ret = array_combine($this->_columnNames, $values);
        $ret += $values;
        return $ret;

      case \PDO::FETCH_OBJ:
        return (object) array_combine($this->_columnNames, $values);

      case \PDO::FETCH_CLASS:
        $ret = new $this->fetchClass;
        for ($i = 0; $i < count($values); $i++) {
          $property = $this->_columnNames[$i];
          $ret->$property = $values[$i];
        }
        return $ret;

      default:
        throw new MysqliException("Unknown fetch type '{$mode}'");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    $mode = $mode ?: $this->_defaultFetchMode;

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
  public function errorCode() {
    return $this->_stmt->errno;
  }

  /**
   * {@inheritdoc}
   */
  public function errorInfo() {
    return $this->_stmt->error;
  }

  /**
   * {@inheritdoc}
   */
  public function closeCursor() {
    $this->_stmt->free_result();
    $this->result = false;
    return true;
  }

  /**
   * {@inheritdoc}
   */
  public function columnCount() {
    return $this->_stmt->field_count;
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
        $this->setFetchMode(\PDO::FETCH_CLASS, $fetch);  // @todo won't work
      }
      else {
        $this->setFetchMode($fetch ?: $this->_defaultFetchMode);
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
      if (false === $this->_columnNames) {
        return $this->_stmt->affected_rows;
      }
      return $this->_stmt->num_rows;
    }
    else {
      throw new RowCountException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
    $this->_defaultFetchMode = $mode;
    return true;
  }

}
