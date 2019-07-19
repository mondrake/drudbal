<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\Mysqli\MysqliException;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Statement as DbalStatement;

/**
 * Driver specific methods for mysqli.
 */
class MysqliExtension extends AbstractMySqlExtension {

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getWrappedResourceHandle()->get_client_info();
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
  public function delegateFetch(DbalStatement $dbal_statement, $mode, $fetch_class) {
    switch ($mode) {
      case \PDO::FETCH_ASSOC:
        $row = $dbal_statement->fetch(FetchMode::ASSOCIATIVE);
        if (!$row) {
          return FALSE;
        }
        foreach ($row as $column => &$value) {
          $value = $value === NULL ? NULL : (string) $value;
        }
        return $row;

      case \PDO::FETCH_NUM:
        $row = $dbal_statement->fetch(FetchMode::NUMERIC);
        if (!$row) {
          return FALSE;
        }
        foreach ($row as $column => &$value) {
          $value = $value === NULL ? NULL : (string) $value;
        }
        return $row;

      case \PDO::FETCH_BOTH:
        $row = $dbal_statement->fetch(FetchMode::MIXED);
        if (!$row) {
          return FALSE;
        }
        foreach ($row as $column => &$value) {
          $value = $value === NULL ? NULL : (string) $value;
        }
        return $row;

      case \PDO::FETCH_OBJ:
        $row = $dbal_statement->fetch(FetchMode::STANDARD_OBJECT);
        if (!$row) {
          return FALSE;
        }
        return $row;

      case \PDO::FETCH_COLUMN:
        $row = $dbal_statement->fetch(FetchMode::COLUMN);
        if (!$row) {
          return FALSE;
        }
        foreach ($row as $column => &$value) {
          $value = $value === NULL ? NULL : (string) $value;
        }
        return $row;

      case \PDO::FETCH_CLASS:
        $row = $dbal_statement->fetch(FetchMode::CUSTOM_OBJECT);
        return $row;

      case \PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE:
        $row = $dbal_statement->fetch(FetchMode::CUSTOM_OBJECT);
        return $row;

      default:
        throw new MysqliException("Unknown fetch type '{$mode}'");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateRowCount(DbalStatement $dbal_statement) {
    $wrapped_connection = $this->getDbalConnection()->getWrappedConnection()->getWrappedResourceHandle();
    if ($wrapped_connection->info === NULL) {
      return $dbal_statement->rowCount();
    }
    else {
      list($matched) = sscanf($wrapped_connection->info, "Rows matched: %d Changed: %d Warnings: %d");
      return $matched;
    }
  }

}
