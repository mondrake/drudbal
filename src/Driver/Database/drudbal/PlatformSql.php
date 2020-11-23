<?php

namespace Drupal\drudbal\Driver\Database\drudbal;

use Drupal\Core\Database\Connection as DatabaseConnection;

/**
 * DruDbal implementation of \Drupal\Core\Database\PlatformSql.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\drudbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class PlatformSql {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a PlatformSql object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The Drupal database connection.
   */
  public function __construct(DatabaseConnection $connection) {
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function getDateFieldSql($field, $string_date) {
    return $this->connection->getDbalExtension()->delegateGetDateFieldSql($field, $string_date);
  }

  /**
   * {@inheritdoc}
   */
  public function getDateFormatSql($field, $format) {
    return $this->connection->getDbalExtension()->delegateGetDateFormatSql($field, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function setTimezoneOffset($offset) {
    return $this->connection->getDbalExtension()->delegateSetTimezoneOffset($offset);
  }

  /**
   * {@inheritdoc}
   */
  public function setFieldTimezoneOffsetSql($field, $offset) {
    return $this->connection->getDbalExtension()->delegateSetFieldTimezoneOffsetSql($field, $offset);
  }

}
