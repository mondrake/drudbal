<?php

namespace Drupal\drudbal\Driver\Database\dbal\Statement;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Event\StatementExecutionEndEvent;
use Drupal\Core\Database\Event\StatementExecutionStartEvent;
use Drupal\Core\Database\FetchModeTrait;
use Drupal\Core\Database\RowCountException;
use Drupal\Core\Database\StatementWrapperIterator;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;

/**
 * DruDbal implementation of \Drupal\Core\Database\Statement.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class StatementWrapper extends StatementWrapperIterator {

  use FetchModeTrait;

  /**
   * The DBAL client connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  protected $dbalConnection;

  /**
   * The DBAL executed statement result.
   *
   * @var \Doctrine\DBAL\Result
   */
  protected $dbalResult;

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
   * Holds supplementary fetch options.
   *
   * @var array
   */
  protected $fetchOptions = [
    'class' => 'stdClass',
    'constructor_args' => [],
  ];

  /**
   * Holds the current fetch style (which will be used by the next fetch).
   * @see \PDOStatement::fetch()
   *
   * @var int
   */
  protected $fetchStyle = \PDO::FETCH_OBJ;

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
  public function execute($args = [], $options = []) {
    /** @var Statement|null $clientStatement */
    $clientStatement = $this->clientStatement;

    $args = $args ?? [];

    // Prepare the lower-level statement if it's not been prepared already.
    if (!isset($clientStatement)) {
      // Replace named placeholders with positional ones if needed.
      if (!$this->connection()->getDbalExtension()->delegateNamedPlaceholdersSupport()) {
        $this->paramsPositions = array_flip(array_keys($args));
        list($query, $args) = $this->connection()->expandArrayParameters($this->queryString, $args, []);
        $this->queryString = $query;
      }

      try {
        $this->connection()->getDbalExtension()->alterStatement($this->queryString, $args);
        $this->clientStatement = $this->dbalConnection->prepare($this->queryString);
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
      $this->dbalResult = $this->clientStatement->executeQuery($args);
    }
    catch (DbalException $e) {
      throw new DatabaseExceptionWrapper($e->getMessage(), $e->getCode(), $e);
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
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
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
    $row = $this->connection()->getDbalExtension()->processFetchedRecord($dbal_row);

    return match($mode) {
      \PDO::FETCH_ASSOC => $row,
      \PDO::FETCH_BOTH => $this->assocToBoth($row),
      \PDO::FETCH_NUM => $this->assocToNum($row),
      \PDO::FETCH_LAZY, \PDO::FETCH_OBJ => $this->assocToObj($row),
      \PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE => $this->assocToClassType($row, $this->fetchOptions['constructor_args']),
      \PDO::FETCH_CLASS => $this->assocToClass($row, $this->fetchOptions['class'], $this->fetchOptions['constructor_args']),
      \PDO::FETCH_INTO => $this->assocIntoObject($row, $this->fetchOptions['object']),
      \PDO::FETCH_COLUMN => $this->assocToColumn($row, $columnNames, $this->fetchOptions['column']),
      default => throw new DatabaseExceptionWrapper("Unknown fetch type '{$mode}'"),
    };
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
  public function getQueryString() {
    return $this->queryString;
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
    if (($ret = $this->fetch(\PDO::FETCH_NUM)) === FALSE) {
      return FALSE;
    }
    return $ret[$index] === NULL ? NULL : (string) $ret[$index];
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject(string $class_name = NULL, array $constructor_arguments = NULL) {
    if (isset($class_name)) {
      $this->fetchStyle = \PDO::FETCH_CLASS;
      $this->fetchOptions = [
        'class' => $class_name,
        'constructor_args' => $constructor_arguments,
      ];
    }
    return $this->fetch($class_name ?? \PDO::FETCH_OBJ);
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    // SELECT query should not use the method.
    if ($this->rowCountEnabled) {
      return $this->connection()->getDbalExtension()->delegateRowCount($this->dbalResult);
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
