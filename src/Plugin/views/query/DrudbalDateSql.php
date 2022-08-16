<?php

namespace Drupal\drudbal\Plugin\views\query;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\drudbal\Driver\Database\dbal\Connection as DruDbalConnection;
use Drupal\views\Plugin\views\query\DateSqlInterface;

/**
 * DruDbal date handling for Views.
 *
 * This class should only be used by the Views SQL query plugin.
 *
 * @see \Drupal\views\Plugin\views\query\Sql
 */
class DrudbalDateSql implements DateSqlInterface {

  use DependencySerializationTrait;

  /**
   * Constructs the DrudbalDateSql object.
   */
  public function __construct(
    protected Connection $database
  ) {}

  /**
   * Returns the DruDbal connection.
   */
  private function connection(): DruDbalConnection {
    return $this->connection;
  }

  /**
   * {@inheritdoc}
   */
  public function getDateField($field, $string_date) {
    return $this->connection()->getDbalExtension()->delegateGetDateFieldSql($field, $string_date);
  }

  /**
   * {@inheritdoc}
   */
  public function getDateFormat($field, $format) {
    return $this->connection()->getDbalExtension()->delegateGetDateFormatSql($field, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function setFieldTimezoneOffset(&$field, $offset) {
    return $this->connection()->getDbalExtension()->delegateSetFieldTimezoneOffsetSql($field, $offset);
  }

  /**
   * {@inheritdoc}
   */
  public function setTimezoneOffset($offset) {
    return $this->connection()->getDbalExtension()->delegateSetTimezoneOffset($offset);
  }

}
