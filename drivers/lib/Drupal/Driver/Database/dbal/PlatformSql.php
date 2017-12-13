<?php

namespace Drupal\Driver\Database\dbal;

use Drupal\Core\Database\Connection as DatabaseConnection;

/**
 * DruDbal implementation of \Drupal\Core\Database\PlatformSql.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
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
    if ($string_date) {
      return $field;
    }

    // Base date field storage is timestamp, so the date to be returned here is
    // epoch + stored value (seconds from epoch).
    return "DATE_ADD('19700101', INTERVAL $field SECOND)";
  }

  /**
   * {@inheritdoc}
   */
  public function getDateFormatSql($field, $format) {
    // An array of PHP-to-MySQL replacement patterns.
    static $replace = [
      'Y' => '%Y',
      'y' => '%y',
      'M' => '%b',
      'm' => '%m',
      'n' => '%c',
      'F' => '%M',
      'D' => '%a',
      'd' => '%d',
      'l' => '%W',
      'j' => '%e',
      'W' => '%v',
      'H' => '%H',
      'h' => '%h',
      'i' => '%i',
      's' => '%s',
      'A' => '%p',
    ];

    $format = strtr($format, $replace);
    return "DATE_FORMAT($field, '$format')";
  }

  /**
   * {@inheritdoc}
   */
  public function setTimezoneOffset($offset) {
    $this->database->query("SET @@session.time_zone = '$offset'");
  }

  /**
   * {@inheritdoc}
   */
  public function setFieldTimezoneOffsetSql($field, $offset) {
    if (!empty($offset)) {
      $field = "($field + INTERVAL $offset SECOND)";
    }
  }

}