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
use Drupal\Core\Database\FetchModeTrait;
use Drupal\Core\Database\RowCountException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\StatementIteratorTrait;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;

/**
 * Implements a Statement whose results are pre-fetched upon execution.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class PrefetchingStatementWrapper implements \Iterator, StatementInterface {

  use StatementIteratorTrait;
  use FetchModeTrait;

  /**
   * Main data store.
   *
   * The resultset is stored as a \PDO::FETCH_ASSOC array.
   */
  protected array $data = [];

  /**
   * The list of column names in this result set.
   *
   * @var string[]
   */
  protected ?array $columnNames = NULL;

  /**
   * The number of rows matched by the last query.
   */
  protected ?int $rowCount = NULL;

  /**
   * Holds the default fetch style.
   */
  protected int $defaultFetchStyle = \PDO::FETCH_OBJ;

  /**
   * Holds fetch options.
   *
   * @var array<string,mixed>
   */
  protected array $fetchOptions = [
    'class' => 'stdClass',
    'constructor_args' => [],
    'object' => NULL,
    'column' => 0,
  ];

  /**
   * The DBAL statement.
   */
  protected ?DbalStatement $dbalStatement;

  /**
   * The DBAL executed statement result.
   */
  protected ?DbalResult $dbalResult;

  /**
   * Holds the index position of named parameters.
   *
   * Used in positional-only parameters binding drivers.
   */
  protected array $paramsPositions;

  /**
   * The number of rows in this result set.
   */
  protected int $resultRowCount = 0;

  /**
   * Constructs a Statement object.
   *
   * Preparation of the actual lower-level statement is deferred to the first
   * call of the execute method, to allow replacing named parameters with
   * positional ones if needed.
   *
   * @param \Drupal\drudbal\Driver\Database\dbal\Connection $connection
   *   The database connection object for this statement.
   * @param \Doctrine\DBAL\Connection $dbalConnection
   *   DBAL connection object.
   * @param string $queryString
   *   A string containing an SQL query.
   * @param array $driverOpts
   *   (optional) An array of driver options for this query.
   * @param bool $rowCountEnabled
   *   (optional) Enables counting the rows affected. Defaults to FALSE.
   */
  public function __construct(
    protected readonly DruDbalConnection $connection,
    protected readonly DbalConnection $dbalConnection,
    protected string $queryString,
    protected array $driverOpts = [],
    protected readonly bool $rowCountEnabled = FALSE,
  ) {
    $this->setFetchMode(\PDO::FETCH_OBJ);
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
      $this->markResultsetIterable((bool) $this->dbalResult);
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
  public function fetch($fetch_style = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    $currentKey = $this->getResultsetCurrentRowIndex();

    // We can remove the current record from the prefetched data, before
    // moving to the next record.
    unset($this->data[$currentKey]);
    $currentKey++;
    if (!isset($this->data[$currentKey])) {
      $this->markResultsetFetchingComplete();
      return FALSE;
    }

    // Now, format the next prefetched record according to the required fetch
    // style.
    $rowAssoc = $this->data[$currentKey];
    $row = match($fetch_style ?? $this->defaultFetchStyle) {
      \PDO::FETCH_ASSOC => $rowAssoc,
      \PDO::FETCH_BOTH => $this->assocToBoth($rowAssoc),
      \PDO::FETCH_NUM => $this->assocToNum($rowAssoc),
      \PDO::FETCH_LAZY, \PDO::FETCH_OBJ => $this->assocToObj($rowAssoc),
      \PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE => $this->assocToClassType($rowAssoc, $this->fetchOptions['constructor_args']),
      \PDO::FETCH_CLASS => $this->assocToClass($rowAssoc, $this->fetchOptions['class'], $this->fetchOptions['constructor_args']),
      \PDO::FETCH_INTO => $this->assocIntoObject($rowAssoc, $this->fetchOptions['object']),
      \PDO::FETCH_COLUMN => $this->assocToColumn($rowAssoc, $this->columnNames, $this->fetchOptions['column']),
      // @todo in Drupal 11, throw an exception if the fetch style cannot be
      //   matched.
      default => FALSE,
    };
    $this->setResultsetCurrentRow($row);
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    if (isset($mode) && !in_array($mode, $this->supportedFetchModes)) {
      @trigger_error('Fetch mode ' . ($this->fetchModeLiterals[$mode] ?? $mode) . ' is deprecated in drupal:10.2.0 and is removed from drupal:11.0.0. Use supported modes only. See https://www.drupal.org/node/3377999', E_USER_DEPRECATED);
    }
    $fetchStyle = $mode ?? $this->defaultFetchStyle;
    if (isset($column_index)) {
      $this->fetchOptions['column'] = $column_index;
    }
    if (isset($constructor_arguments)) {
      $this->fetchOptions['constructor_args'] = $constructor_arguments;
    }

    $result = [];
    while ($row = $this->fetch($fetchStyle)) {
      $result[] = $row;
    }
    return $result;
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
      while ($row = $this->fetch(\PDO::FETCH_ASSOC)) {
        $result[] = $row[$this->columnNames[$index]];
      }
      return $result;
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllAssoc($key, $fetch_style = NULL) {
    $fetchStyle = $fetch_style ?? $this->defaultFetchStyle;

    $result = [];
    while ($row = $this->fetch($fetchStyle)) {
      $result[$this->data[$this->getResultsetCurrentRowIndex()][$key]] = $row;
    }
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
    while ($row = $this->fetch(\PDO::FETCH_ASSOC)) {
      $result[$row[$key]] = $row[$value];
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchColumn($index = 0) {
    if ($row = $this->fetch(\PDO::FETCH_ASSOC)) {
      return $row[$this->columnNames[$index]];
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    return $this->fetchColumn($index);
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
  public function fetchObject(string $class_name = NULL, array $constructor_arguments = []) {
    if (!isset($class_name)) {
      return $this->fetch(\PDO::FETCH_OBJ);
    }
    $this->fetchOptions = [
      'class' => $class_name,
      'constructor_args' => $constructor_arguments,
    ];
    return $this->fetch(\PDO::FETCH_CLASS);
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
    if (!in_array($mode, $this->supportedFetchModes)) {
      @trigger_error('Fetch mode ' . ($this->fetchModeLiterals[$mode] ?? $mode) . ' is deprecated in drupal:10.2.0 and is removed from drupal:11.0.0. Use supported modes only. See https://www.drupal.org/node/3377999', E_USER_DEPRECATED);
    }
    $this->defaultFetchStyle = $mode;
    switch ($mode) {
      case \PDO::FETCH_CLASS:
        $this->fetchOptions['class'] = $a1;
        if ($a2) {
          $this->fetchOptions['constructor_args'] = $a2;
        }
        break;

      case \PDO::FETCH_COLUMN:
        $this->fetchOptions['column'] = $a1;
        break;

      case \PDO::FETCH_INTO:
        $this->fetchOptions['object'] = $a1;
        break;
    }
  }

}
