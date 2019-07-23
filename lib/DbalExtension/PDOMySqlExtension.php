<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Driver\PDOException;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Statement as DbalStatement;

/**
 * Driver specific methods for pdo_mysql.
 */
class PDOMySqlExtension extends AbstractMySqlExtension {

  /**
   * {@inheritdoc}
   */
  public static function preConnectionOpen(array &$connection_options, array &$dbal_connection_options) {
    parent::preConnectionOpen($connection_options, $dbal_connection_options);
    $dbal_connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by
      // default.
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's
      // dumb.
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ];
    if (defined('\PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      // An added connection option in PHP 5.5.21 to optionally limit SQL to a
      // single statement like mysqli.
      $dbal_connection_options['driverOptions'] += [\PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delegateClientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
  }

  /**
   * Statement delegated methods.
   */

  /**
   * {@inheritdoc}
   */
  public function delegateFetch(DbalStatement $dbal_statement, $mode, $fetch_class) {
    $row = $dbal_statement->fetch(FetchMode::ASSOCIATIVE);
    if (!$row) {
      return FALSE;
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
        throw new PDOException("Unknown fetch type '{$mode}'");
    }
  }

}
