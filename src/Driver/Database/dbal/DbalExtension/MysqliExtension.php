<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\Result as DbalResult;

/**
 * Driver specific methods for mysqli.
 */
class MysqliExtension extends AbstractMySqlExtension {

  /**
   * The low-level Mysqli connection object.
   */
  protected \mysqli $mysqliConnection;

  /**
   * Constructs a Mysqli extension object.
   */
  public function __construct(DruDbalConnection $connection) {
    parent::__construct($connection);
    $this->mysqliConnection = $this->getDbalConnection()->getNativeConnection();
  }

  /**
   * {@inheritdoc}
   */
  public function getDbServerPlatform(bool $strict = FALSE): string {
    if (!$strict) {
      return 'mysql';
    }
    $serverVersion = $this->mysqliConnection->get_server_info();
    $regex = '/^(?:5\.5\.5-)?(\d+\.\d+\.\d+.*-mariadb.*)/i';
    preg_match($regex, $serverVersion, $matches);
    return (empty($matches[1])) ? 'mysql' : 'mariadb';
  }

  /**
   * {@inheritdoc}
   */
  public function getDbServerVersion(): string {
    $serverVersion = $this->mysqliConnection->get_server_info();
    $regex = '/^(?:5\.5\.5-)?(\d+\.\d+\.\d+.*-mariadb.*)/i';
    preg_match($regex, $serverVersion, $matches);
    return $matches[1] ?? $serverVersion;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return mysqli_get_client_info();
  }

  /**
   * Transaction delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateCommit(): void {
    try {
     parent::delegateCommit();
    }
    catch (DbalConnectionException $e) {
     return;
    }
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateNamedPlaceholdersSupport() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function processFetchedRecord(array $record): array {
    // Enforce all values are of type 'string'.
    $result = [];
    foreach ($record as $column => $value) {
      $result[$column] = $value === NULL ? NULL : (string) $value;
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function delegateRowCount(DbalResult $dbal_result) {
    if ($this->mysqliConnection->info === NULL) {
      return $dbal_result->rowCount();
    }
    else {
      list($matched) = sscanf($this->mysqliConnection->info, "Rows matched: %d Changed: %d Warnings: %d");
      return $matched;
    }
  }

}
