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
    $row = $dbal_statement->fetch(FetchMode::ASSOCIATIVE);
    if (!$row) {
      return FALSE;
    }
    foreach ($row as $column => &$value) {
      $value = $value === NULL ? NULL : (string) $value;
    }
    switch ($mode) {
      case \PDO::FETCH_ASSOC:
        return $row;

      case \PDO::FETCH_NUM:
        $num = [];
        foreach ($row as $column => $value) {
          $num[] = $value;
        }
        return $num;

      case \PDO::FETCH_BOTH:
        $num = [];
        foreach ($row as $column => $value) {
          $num[] = $value;
        }
        return $row + $num;

      case \PDO::FETCH_OBJ:
        return (object) $row;

      case \PDO::FETCH_CLASS:
        $class_obj = new $fetch_class();
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
