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
class MysqliDbalStatement extends PDODbalStatement {

  /**
   * Constructs a MysqliDbalStatement object.
   *
   * @param \Drupal\Driver\Database\dbal\Connection $dbh
   *   The database connection object for this statement.
   */
  public function __construct(DruDbalConnection $dbh, $statement, $params, array $driver_options = []) {
    $this->dbh = $dbh;
    $this->setFetchMode(\PDO::FETCH_OBJ);
    if (($allow_row_count = $this->dbh->popStatementOption('allowRowCount')) !== NULL) {
      $this->allowRowCount = $allow_row_count;
    }
    list($positional_statement, $positional_params, $positional_types) = SQLParserUtils::expandListParameters($statement, $params, []);
//var_export(get_class($dbh));
//var_export(get_class($dbh->getDbalConnection()));
//var_export(get_class($dbh->getDbalConnection()->getWrappedConnection()));
    $conn = $dbh->getDbalConnection()->getWrappedConnection()->getWrappedResourceHandle();
//var_export(get_class($conn));
//var_export($statement);
//$statement = str_replace(':sid', '?', $statement);
    $stmt = $conn->prepare($positional_statement);
var_export($statement);echo('<br/>');
var_export($positional_statement);echo('<br/>');
var_export($params);echo('<br/>');
var_export($positional_params);echo('<br/>');
    if (false === $stmt) {
        throw new MysqliException($conn->error, $conn->sqlstate, $conn->errno);
    }
var_export($stmt);echo('<br/>');
    $ret = $stmt->execute();
var_export($ret);echo('<br/>');
die;
  }

}
