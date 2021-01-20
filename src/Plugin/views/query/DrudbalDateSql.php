<?php

namespace Drupal\drudbal\Plugin\views\query;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs the DrudbalDateSql object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->connection = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function getDateField($field, $string_date) {
    return $this->connection->getDbalExtension()->delegateGetDateFieldSql($field, $string_date);
  }

  /**
   * {@inheritdoc}
   */
  public function getDateFormat($field, $format) {
    return $this->connection->getDbalExtension()->delegateGetDateFormatSql($field, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function setFieldTimezoneOffset(&$field, $offset) {
    return $this->connection->getDbalExtension()->delegateSetFieldTimezoneOffsetSql($field, $offset);
  }

  /**
   * {@inheritdoc}
   */
  public function setTimezoneOffset($offset) {
    return $this->connection->getDbalExtension()->delegateSetTimezoneOffset($offset);
  }

}
