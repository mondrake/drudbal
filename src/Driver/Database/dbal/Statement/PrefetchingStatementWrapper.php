<?php

namespace Drupal\drudbal\Driver\Database\dbal\Statement;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Result as DbalResult;
use Doctrine\DBAL\Statement as DbalStatement;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Event\StatementExecutionEndEvent;
use Drupal\Core\Database\Event\StatementExecutionStartEvent;
use Drupal\Core\Database\RowCountException;
use Drupal\Core\Database\StatementInterface;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;

/**
 * Implements a Statement whose results are pre-fetched upon execution.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class PrefetchingStatementWrapper implements \IteratorAggregate, StatementInterface {

  /**
   * The Drupal database connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Is rowCount() execution allowed.
   *
   * @var bool
   */
  protected $rowCountEnabled = FALSE;

  /**
   * The DBAL statement.
   */
  protected ?DbalStatement $dbalStatement;

  /**
   * The DBAL client connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $dbalConnection;

  /**
   * The DBAL executed statement result.
   */
  protected ?DbalResult $dbalResult;

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
   * @var array
   */
  protected $data = [];

  /**
   * The current row, retrieved in \PDO::FETCH_ASSOC format.
   */
  protected ?array $currentRow;

  /**
   * The key of the current row.
   */
  protected int $currentKey;

  /**
   * The list of column names in this result set.
   */
  protected array $columnNames;

  /**
   * The number of rows affected by the last query.
   */
  protected int $rowCount;

  /**
   * The number of rows in this result set.
   */
  protected int $resultRowCount = 0;

  /**
   * Holds the current fetch style (which will be used by the next fetch).
   * @see \PDOStatement::fetch()
   *
   * @var int
   */
  protected $fetchStyle = \PDO::FETCH_OBJ;

  /**
   * Holds supplementary current fetch options.
   *
   * Will be used by the next fetch.
   *
   * @var array
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
   * @var int
   */
  protected $defaultFetchStyle = \PDO::FETCH_OBJ;

  /**
   * Holds supplementary default fetch options.
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
   * @param \Drupal\drudbal\Driver\Database\dbal\Connection $connection
   *   The database connection object for this statement.
   * @param \Doctrine\DBAL\Connection $dbal_connection
   *   DBAL connection object.
   * @param string $query
   *   A string containing an SQL query.
   * @param array $driver_options
   *   (optional) An array of driver options for this query.
   * @param bool $row_count_enabled
   *   (optional) Enables counting the rows affected. Defaults to FALSE.
   */
  public function __construct(DruDbalConnection $connection, DbalConnection $dbal_connection, string $query, array $driver_options = [], bool $row_count_enabled = FALSE) {
    $this->connection = $connection;
    $this->rowCountEnabled = $row_count_enabled;

    $this->queryString = $query;
    $this->dbalConnection = $dbal_connection;
    $this->setFetchMode(\PDO::FETCH_OBJ);
    $this->driverOpts = $driver_options;
  }

  /**
   * Returns the DruDbal connection.
   */
  private function connection(): DruDbalConnection {
    $connection = $this->connection;
    assert($connection instanceof DruDbalConnection);
    return $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function getConnectionTarget(): string {
    return $this->connection()->getTarget();
  }

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    $args = $args ?? [];

    // Prepare the lower-level statement if it's not been prepared already.
    if (!isset($this->dbalStatement)) {
      // Replace named placeholders with positional ones if needed.
      if (!$this->connection()->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
        $this->paramsPositions = array_flip(array_keys($args));
        list($query, $args) = $this->connection()->expandArrayParameters($this->queryString, $args, []);
        $this->queryString = $query;
      }

      try {
        $this->connection()->getDbalExtension()->alterStatement($this->queryString, $args);
        $this->dbalStatement = $this->connection()->getDbalConnection()->prepare($this->queryString);
      }
      catch (DbalException $e) {
        throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
      }
    }
    elseif (!$this->connection()->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
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

    if ($this->connection()->isEventEnabled(StatementExecutionStartEvent::class)) {
      $startEvent = new StatementExecutionStartEvent(
        spl_object_id($this),
        $this->connection()->getKey(),
        $this->connection()->getTarget(),
        $this->getQueryString(),
        $args ?? [],
        $this->connection()->findCallerFromDebugBacktrace()
      );
      $this->connection()->dispatchEvent($startEvent);
    }

    try {
      $this->dbalResult = $this->dbalStatement->executeQuery($args);
    }
    catch (DbalException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
    }

    if ($this->rowCountEnabled) {
      $this->rowCount = $this->dbalResult->rowCount();
    }

    // Fetch all the data from the reply, in order to release any lock
    // as soon as possible.
    $this->data = $this->dbalResult->fetchAllAssociative();
    // Destroy the statement as soon as possible. See the documentation of
    // \Drupal\Core\Database\Driver\sqlite\Statement for an explanation.
    $this->dbalResult->free();
    unset($this->dbalResult, $this->dbalStatement);
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

    if (isset($startEvent) && $this->connection()->isEventEnabled(StatementExecutionEndEvent::class)) {
      $this->connection()->dispatchEvent(new StatementExecutionEndEvent(
        $startEvent->statementObjectId,
        $startEvent->key,
        $startEvent->target,
        $startEvent->queryString,
        $startEvent->args,
        $startEvent->caller,
        $startEvent->time
      ));
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    if (isset($this->currentRow)) {
      // Set the fetch parameter.
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

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
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

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \ArrayIterator {
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

  /**
   * {@inheritdoc}
   */
  public function fetchAllAssoc($key, $fetch = NULL) {
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

  /**
   * {@inheritdoc}
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
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

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
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

  /**
   * {@inheritdoc}
   */
  public function fetchAssoc() {
    if (isset($this->currentRow)) {
      $result = $this->currentRow;
      $this->next();
      return $result;
    }
    else {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject(string $class_name = NULL, array $constructor_arguments = NULL) {
    if (isset($this->currentRow)) {
      if (!isset($class_name)) {
        // Directly cast to an object to avoid a function call.
        $result = (object) $this->currentRow;
      }
      else {
        $this->fetchStyle = \PDO::FETCH_CLASS;
        $this->fetchOptions = [
          'class' => $class_name,
          'constructor_args' => $constructor_arguments,
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

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use the method.
    if ($this->rowCountEnabled) {
      return $this->rowCount;
    }
    else {
      throw new RowCountException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
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
