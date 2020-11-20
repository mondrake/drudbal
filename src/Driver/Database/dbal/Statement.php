<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Result;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\RowCountException;
use Drupal\Core\Database\StatementInterface;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;

// @todo organize better prefetch vs normal
// @todo DBAL 2.6.0:
// Normalize method signatures for `fetch()` and `fetchAll()`, ensuring compatibility with the `PDOStatement` signature
// `ResultStatement#fetchAll()` must define 3 arguments in order to be compatible with `PDOStatement#fetchAll()`

/**
 * DruDbal implementation of \Drupal\Core\Database\Statement.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Statement implements \IteratorAggregate, StatementInterface {

  /**
   * Reference to the database connection object for this statement.
   *
   * The name $dbh is inherited from \PDOStatement.
   *
   * @var \Drupal\drudbal\Driver\Database\dbal\Connection
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
  protected $dbalStatement = NULL;

  /**
   * The DBAL executed statement result.
   *
   * @var \Doctrine\DBAL\Result
   */
  protected $dbalResult = NULL;

  /**
   * Holds supplementary driver options.
   *
   * @var array
   */
  protected $driverOpts;

  /**
   * Holds the index position of named parameters.
   *
   * Used in positional-only parameters binding drivers.
   *
   * @var array
   */
  protected $paramsPositions;

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
   * Main data store.
   *
   * Used when prefetching all data.
   *
   * @var Array
   */
  protected $data = [];

  /**
   * The current row, retrieved in \PDO::FETCH_ASSOC format.
   *
   * Used when prefetching all data.
   *
   * @var Array
   */
  protected $currentRow = NULL;

  /**
   * The key of the current row.
   *
   * Used when prefetching all data.
   *
   * @var int
   */
  protected $currentKey = NULL;

  /**
   * The list of column names in this result set.
   *
   * Used when prefetching all data.
   *
   * @var Array
   */
  protected $columnNames = NULL;

  /**
   * The number of rows affected by the last query.
   *
   * Used when prefetching all data.
   *
   * @var int
   */
  protected $rowCount = NULL;

  /**
   * The number of rows in this result set.
   *
   * Used when prefetching all data.
   *
   * @var int
   */
  protected $resultRowCount = 0;

  /**
   * Holds the current fetch style (which will be used by the next fetch).
   * @see \PDOStatement::fetch()
   *
   * Used when prefetching all data.
   *
   * @var int
   */
  protected $fetchStyle = \PDO::FETCH_OBJ;

  /**
   * Holds supplementary current fetch options (which will be used by the next fetch).
   *
   * Used when prefetching all data.
   *
   * @var Array
   */
  protected $fetchOptions = [
    'class' => 'stdClass',
    'constructor_args' => [],
    'object' => NULL,
    'column' => 0,
  ];

  /**
   * Holds the default fetch style.
   *
   * Used when prefetching all data.
   *
   * @var int
   */
  protected $defaultFetchStyle = \PDO::FETCH_OBJ;

  /**
   * Holds supplementary default fetch options.
   *
   * Used when prefetching all data.
   *
   * @var array
   */
  protected $defaultFetchOptions = [
    'class' => 'stdClass',
    'constructor_args' => [],
    'object' => NULL,
    'column' => 0,
  ];

  /**
   * Constructs a Statement object.
   *
   * Preparation of the actual lower-level statement is deferred to the first
   * call of the execute method, to allow replacing named parameters with
   * positional ones if needed.
   *
   * @param \Drupal\drudbal\Driver\Database\dbal\Connection $dbh
   *   The database connection object for this statement.
   * @param string $query
   *   A string containing an SQL query.
   * @param array $driver_options
   *   (optional) An array of driver options for this query.
   */
  public function __construct(DruDbalConnection $dbh, string $query, array $driver_options = []) {
    $this->queryString = $query;
    $this->dbh = $dbh;
    $this->setFetchMode(\PDO::FETCH_OBJ);
    $this->driverOpts = $driver_options;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    // Prepare the lower-level statement if it's not been prepared already.
    if (!$this->dbalStatement) {
      // Replace named placeholders with positional ones if needed.
      if (!$this->dbh->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
        $this->paramsPositions = array_flip(array_keys($args));
        list($query, $args) = $this->dbh->expandArrayParameters($this->queryString, $args, []);
        $this->queryString = $query;
      }

      try {
        $this->dbh->getDbalExtension()->alterStatement($this->queryString, $args);
        $this->dbalStatement = $this->dbh->getDbalConnection()->prepare($this->queryString);
      }
      catch (DbalException $e) {
        throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
      }
    }
    elseif (!$this->dbh->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
      // Transform the $args to positional if needed.
      $tmp = [];
      foreach ($this->paramsPositions as $param => $pos) {
        $tmp[$pos] = $args[$param];
      }
      $args = $tmp;
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

    try {
      $this->dbalResult = $this->dbalStatement->execute($args);
    }
    catch (DbalException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }

    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      if ($options['return'] == Database::RETURN_AFFECTED) {
        $this->rowCount = $this->dbalResult->rowCount();
      }

      // Fetch all the data from the reply, in order to release any lock
      // as soon as possible.
      $this->data = $this->dbalResult->fetchAllAssociative();
      // Destroy the statement as soon as possible. See the documentation of
      // \Drupal\Core\Database\Driver\sqlite\Statement for an explanation.
      $this->dbalResult->free();
      unset($this->dbalResult);
      unset($this->dbalStatement);
      $this->dbalResult = NULL;
      $this->dbalStatement = NULL;

      $this->resultRowCount = count($this->data);

      if ($this->resultRowCount) {
        $this->columnNames = array_keys($this->data[0]);
      }
      else {
        $this->columnNames = [];
      }

      // Initialize the first row in $this->currentRow.
      $this->next();
    }

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
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      if (isset($this->currentRow)) {
        // Set the fetch parameter.
        $this->fetchStyle = isset($fetch_style) ? $fetch_style : $this->defaultFetchStyle;
        $this->fetchOptions = $this->defaultFetchOptions;

        // Grab the row in the format specified above.
        $return = $this->current();
        // Advance the cursor.
        $this->next();

        // Reset the fetch parameters to the value stored using setFetchMode().
        $this->fetchStyle = $this->defaultFetchStyle;
        $this->fetchOptions = $this->defaultFetchOptions;
        return $return;
      }
      else {
        return FALSE;
      }
    }
    else {
      if (is_string($mode)) {
        $this->setFetchMode(\PDO::FETCH_CLASS, $mode);
        $mode = \PDO::FETCH_CLASS;
      }
      else {
        $mode = $mode ?: $this->defaultFetchMode;
      }

      $dbal_row = $this->dbalResult->fetchAssociative();
      if (!$dbal_row) {
        return FALSE;
      }
      $row = $this->dbh->getDbalExtension()->processFetchedRecord($dbal_row);
      switch ($mode) {
        case \PDO::FETCH_ASSOC:
          return $row;

        case \PDO::FETCH_NUM:
          return array_values($row);

        case \PDO::FETCH_BOTH:
          return $row + array_values($row);

        case \PDO::FETCH_OBJ:
          return (object) $row;

        case \PDO::FETCH_CLASS:
          $class_obj = new $this->fetchClass();
          foreach ($row as $column => $value) {
            $class_obj->$column = $value;
          }
          return $class_obj;

        case \PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE:
          $class = array_shift($row);
          $class_obj = new $class();
          foreach ($row as $column => $value) {
            $class_obj->$column = $value;
          }
          return $class_obj;

        default:
            throw new DbalException("Unknown fetch type '{$mode}'");
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      $this->fetchStyle = isset($mode) ? $mode : $this->defaultFetchStyle;
      $this->fetchOptions = $this->defaultFetchOptions;
      if (isset($column_index)) {
        $this->fetchOptions['column'] = $column_index;
      }
      if (isset($constructor_arguments)) {
        $this->fetchOptions['constructor_args'] = $constructor_arguments;
      }

      $result = [];
      // Traverse the array as PHP would have done.
      while (isset($this->currentRow)) {
        // Grab the row in the format specified above.
        $result[] = $this->current();
        $this->next();
      }

      // Reset the fetch parameters to the value stored using setFetchMode().
      $this->fetchStyle = $this->defaultFetchStyle;
      $this->fetchOptions = $this->defaultFetchOptions;
      return $result;
    }
    else {
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
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      if (isset($this->columnNames[$index])) {
        $result = [];
        // Traverse the array as PHP would have done.
        while (isset($this->currentRow)) {
          $result[] = $this->currentRow[$this->columnNames[$index]];
          $this->next();
        }
        return $result;
      }
      else {
        return [];
      }
    }
    else {
      return $this->fetchAll(\PDO::FETCH_COLUMN, $index);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllAssoc($key, $fetch = NULL) {
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      $this->fetchStyle = isset($fetch) ? $fetch : $this->defaultFetchStyle;
      $this->fetchOptions = $this->defaultFetchOptions;

      $result = [];
      // Traverse the array as PHP would have done.
      while (isset($this->currentRow)) {
        // Grab the row in its raw \PDO::FETCH_ASSOC format.
        $result_row = $this->current();
        $result[$this->currentRow[$key]] = $result_row;
        $this->next();
      }

      // Reset the fetch parameters to the value stored using setFetchMode().
      $this->fetchStyle = $this->defaultFetchStyle;
      $this->fetchOptions = $this->defaultFetchOptions;
      return $result;
    }
    else {
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
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      if (!isset($this->columnNames[$key_index]) || !isset($this->columnNames[$value_index])) {
        return [];
      }

      $key = $this->columnNames[$key_index];
      $value = $this->columnNames[$value_index];

      $result = [];
      // Traverse the array as PHP would have done.
      while (isset($this->currentRow)) {
        $result[$this->currentRow[$key]] = $this->currentRow[$value];
        $this->next();
      }
      return $result;
    }
    else {
      $return = [];
      $this->setFetchMode(\PDO::FETCH_ASSOC);
      while ($record = $this->fetch(\PDO::FETCH_ASSOC)) {
        $cols = array_keys($record);
        $return[$record[$cols[$key_index]]] = $record[$cols[$value_index]];
      }
      return $return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      if (isset($this->currentRow) && isset($this->columnNames[$index])) {
        // We grab the value directly from $this->data, and format it.
        $return = $this->currentRow[$this->columnNames[$index]];
        $this->next();
        return $return;
      }
      else {
        return FALSE;
      }
    }
    else {
      if (($ret = $this->fetch(\PDO::FETCH_NUM)) === FALSE) {
        return FALSE;
      }
      return $ret[$index] === NULL ? NULL : (string) $ret[$index];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAssoc() {
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      if (isset($this->currentRow)) {
        $result = $this->currentRow;
        $this->next();
        return $result;
      }
      else {
        return FALSE;
      }
    }
    else {
      return $this->fetch(\PDO::FETCH_ASSOC);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject($class_name = NULL, $constructor_args = []) {
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      if (isset($this->currentRow)) {
        if (!isset($class_name)) {
          // Directly cast to an object to avoid a function call.
          $result = (object) $this->currentRow;
        }
        else {
          $this->fetchStyle = \PDO::FETCH_CLASS;
          $this->fetchOptions = [
            'class' => $class_name,
            'constructor_args' => $constructor_args,
          ];
          // Grab the row in the format specified above.
          $result = $this->current();
          // Reset the fetch parameters to the value stored using setFetchMode().
          $this->fetchStyle = $this->defaultFetchStyle;
          $this->fetchOptions = $this->defaultFetchOptions;
        }

        $this->next();

        return $result;
      }
      else {
        return FALSE;
      }
    }
    else {
      return $this->fetch($class_name ?? \PDO::FETCH_OBJ);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use the method.
    if ($this->allowRowCount) {
      if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
        return $this->rowCount;
      }
      else {
        return $this->dbh->getDbalExtension()->delegateRowCount($this->dbalResult);
      }
    }
    else {
      throw new RowCountException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
    // Handle via prefetched data if needed.
    if ($this->dbh->getDbalExtension()->onSelectPrefetchAllData()) {
      $this->defaultFetchStyle = $mode;
      switch ($mode) {
        case \PDO::FETCH_CLASS:
          $this->defaultFetchOptions['class'] = $a1;
          if ($a2) {
            $this->defaultFetchOptions['constructor_args'] = $a2;
          }
          break;
        case \PDO::FETCH_COLUMN:
          $this->defaultFetchOptions['column'] = $a1;
          break;
        case \PDO::FETCH_INTO:
          $this->defaultFetchOptions['object'] = $a1;
          break;
      }

      // Set the values for the next fetch.
      $this->fetchStyle = $this->defaultFetchStyle;
      $this->fetchOptions = $this->defaultFetchOptions;
    }
    else {
      $this->defaultFetchMode = $mode;
      if ($mode === \PDO::FETCH_CLASS) {
        $this->fetchClass = $a1;
      }
      return TRUE;
    }
  }

  /**
   * Return the current row formatted according to the current fetch style.
   *
   * This is the core method of this class. It grabs the value at the current
   * array position in $this->data and format it according to $this->fetchStyle
   * and $this->fetchMode.
   *
   * @return mixed
   *   The current row formatted as requested.
   */
  public function current() {
    if (isset($this->currentRow)) {
      switch ($this->fetchStyle) {
        case \PDO::FETCH_ASSOC:
          return $this->currentRow;
        case \PDO::FETCH_BOTH:
          // \PDO::FETCH_BOTH returns an array indexed by both the column name
          // and the column number.
          return $this->currentRow + array_values($this->currentRow);
        case \PDO::FETCH_NUM:
          return array_values($this->currentRow);
        case \PDO::FETCH_LAZY:
          // We do not do lazy as everything is fetched already. Fallback to
          // \PDO::FETCH_OBJ.
        case \PDO::FETCH_OBJ:
          return (object) $this->currentRow;
        case \PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE:
          $class_name = array_shift($this->currentRow);
          // Deliberate no break.
        case \PDO::FETCH_CLASS:
          if (!isset($class_name)) {
            $class_name = $this->fetchOptions['class'];
          }
          if (count($this->fetchOptions['constructor_args'])) {
            $reflector = new \ReflectionClass($class_name);
            $result = $reflector->newInstanceArgs($this->fetchOptions['constructor_args']);
          }
          else {
            $result = new $class_name();
          }
          foreach ($this->currentRow as $k => $v) {
            $result->$k = $v;
          }
          return $result;
        case \PDO::FETCH_INTO:
          foreach ($this->currentRow as $k => $v) {
            $this->fetchOptions['object']->$k = $v;
          }
          return $this->fetchOptions['object'];
        case \PDO::FETCH_COLUMN:
          if (isset($this->columnNames[$this->fetchOptions['column']])) {
            return $this->currentRow[$this->columnNames[$this->fetchOptions['column']]];
          }
          else {
            return;
          }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function next() {
    if (!empty($this->data)) {
      $this->currentRow = reset($this->data);
      $this->currentKey = key($this->data);
      unset($this->data[$this->currentKey]);
    }
    else {
      $this->currentRow = NULL;
    }
  }

}
