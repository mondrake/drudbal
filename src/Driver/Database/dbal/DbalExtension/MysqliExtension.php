<?php

namespace Drupal\drudbal\Driver\Database\dbal\DbalExtension;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Result as DbalResult;

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
  public function processFetchedRecord(array $record) : array {
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
    $wrapped_connection = $this->getDbalConnection()->getWrappedConnection()->getWrappedResourceHandle();
    if ($wrapped_connection->info === NULL) {
      return $dbal_result->rowCount();
    }
    else {
      list($matched) = sscanf($wrapped_connection->info, "Rows matched: %d Changed: %d Warnings: %d");
      return $matched;
    }
  }

}
