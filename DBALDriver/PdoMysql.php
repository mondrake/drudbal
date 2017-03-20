<?php

namespace Drupal\Driver\Database\drubal\DBALDriver;

use Drupal\Core\Database\Database;
use Drupal\Driver\Database\drubal\Connection;
use Doctrine\DBAL\Connection as DBALConnection;

/**
 * Driver specific methods for pdo_mysql.
 */
abstract class PDOMySql {

  /**
   * Minimum required mysql version.
   */
  const MYSQLSERVER_MINIMUM_VERSION = '5.5.3';

  /**
   * Minimum required MySQLnd version.
   */
  const MYSQLND_MINIMUM_VERSION = '5.0.9';

  /**
   * Minimum required libmysqlclient version.
   */
  const LIBMYSQLCLIENT_MINIMUM_VERSION = '5.5.3';

  /**
   * Error code for "Unknown database" error.
   */
  const DATABASE_NOT_FOUND = 1049;

  /**
   * Error code for "Access denied" error.
   */
  const ACCESS_DENIED = 1045;

  /**
   * Error code for "Can't initialize character set" error.
   */
  const UNSUPPORTED_CHARSET = 2019;

  /**
   * Driver-specific error code for "Unknown character set" error.
   */
  const UNKNOWN_CHARSET = 1115;

  /**
   * SQLSTATE error code for "Syntax error or access rule violation".
   */
  const SQLSTATE_SYNTAX_ERROR = 42000;

  /**
   * @todo
   */
  public static function installConnect() {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    try {
      // Now actually do a check.
      try {
        Database::getConnection();
      }
      catch (\Exception $e) {
        // Detect utf8mb4 incompability.
        if ($e->getCode() == self::UNSUPPORTED_CHARSET || ($e->getCode() == self::SQLSTATE_SYNTAX_ERROR && $e->errorInfo[1] == self::UNKNOWN_CHARSET)) {
          $results['fail'][] = t('Your MySQL server and PHP MySQL driver must support utf8mb4 character encoding. Make sure to use a database system that supports this (such as MySQL/MariaDB/Percona 5.5.3 and up), and that the utf8mb4 character set is compiled in. See the <a href=":documentation" target="_blank">MySQL documentation</a> for more information.', [':documentation' => 'https://dev.mysql.com/doc/refman/5.0/en/cannot-initialize-character-set.html']);
          $info = Database::getConnectionInfo();
          $info_copy = $info;
          // Set a flag to fall back to utf8. Note: this flag should only be
          // used here and is for internal use only.
          $info_copy['default']['_dsn_utf8_fallback'] = TRUE;
          // In order to change the Database::$databaseInfo array, we need to
          // remove the active connection, then re-add it with the new info.
          Database::removeConnection('default');
          Database::addConnectionInfo('default', 'default', $info_copy['default']);
          // Connect with the new database info, using the utf8 character set so
          // that we can run the checkEngineVersion test.
          Database::getConnection();
          // Revert to the old settings.
          Database::removeConnection('default');
          Database::addConnectionInfo('default', 'default', $info['default']);
        }
        else {
          // Rethrow the exception.
          throw $e;
        }
      }
      $results['pass'][] = t('Drupal can CONNECT to the database ok.');
    }
    catch (\Exception $e) {
      // Attempt to create the database if it is not found.
      if ($e->getCode() == self::DATABASE_NOT_FOUND) {
        // Remove the database string from connection info.
        $connection_info = Database::getConnectionInfo();
        $database = $connection_info['default']['database'];
        unset($connection_info['default']['database']);

        // In order to change the Database::$databaseInfo array, need to remove
        // the active connection, then re-add it with the new info.
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $connection_info['default']);

        try {
          // Now, attempt the connection again; if it's successful, attempt to
          // create the database.
          Database::getConnection()->createDatabase($database);
        }
        catch (DatabaseNotFoundException $e) {
          // Still no dice; probably a permission issue. Raise the error to the
          // installer.
          $results['fail'][] = t('Database %database not found. The server reports the following message when attempting to create the database: %error.', ['%database' => $database, '%error' => $e->getMessage()]);
        }
      }
      else {
        // Database connection failed for some other reason than the database
        // not existing.
        $results['fail'][] = t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist or does the database user have sufficient privileges to create the database?</li><li>Have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname?</li></ul>', ['%error' => $e->getMessage()]);
      }
    }
    return $results;
  }

  /**
   * @todo
   */
  public static function runInstallTasks(Connection $connection) {
    $results = [
      'fail' => [],
      'pass' => [],
    ];

    // Ensure that MySql has the right minimum version.
    if (version_compare($connection->getDbServerVersion(), self::MYSQLSERVER_MINIMUM_VERSION, '<')) {
      $results['fail'][] = t("The MySQL server version %version is less than the minimum required version %minimum_version.", [
        '%version' => $connection->getDbServerVersion(),
        '%minimum_version' => self::MYSQLSERVER_MINIMUM_VERSION,
      ]);
    }

    // Ensure that InnoDB is available.
    $engines = $connection->query('SHOW ENGINES')->fetchAllKeyed();
    if (isset($engines['MyISAM']) && $engines['MyISAM'] == 'DEFAULT' && !isset($engines['InnoDB'])) {
      $results['fail'][] = t('The MyISAM storage engine is not supported.');
    }

    // Ensure that the MySQL driver supports utf8mb4 encoding.
    $version = $connection->clientVersion();
    if (FALSE !== strpos($version, 'mysqlnd')) {
      // The mysqlnd driver supports utf8mb4 starting at version 5.0.9.
      $version = preg_replace('/^\D+([\d.]+).*/', '$1', $version);
      if (version_compare($version, self::MYSQLND_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The MySQLnd driver version %version is less than the minimum required version. Upgrade to MySQLnd version %mysqlnd_minimum_version or up, or alternatively switch mysql drivers to libmysqlclient version %libmysqlclient_minimum_version or up.", ['%version' => $version, '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION, '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION]);
      }
    }
    else {
      // The libmysqlclient driver supports utf8mb4 starting at version 5.5.3.
      if (version_compare($version, self::LIBMYSQLCLIENT_MINIMUM_VERSION, '<')) {
        $results['fail'][] = t("The libmysqlclient driver version %version is less than the minimum required version. Upgrade to libmysqlclient version %libmysqlclient_minimum_version or up, or alternatively switch mysql drivers to MySQLnd version %mysqlnd_minimum_version or up.", ['%version' => $version, '%libmysqlclient_minimum_version' => self::LIBMYSQLCLIENT_MINIMUM_VERSION, '%mysqlnd_minimum_version' => self::MYSQLND_MINIMUM_VERSION]);
      }
    }

    return $results;
  }

  /**
   * @todo
   */
  public static function preConnectionConstruct(array &$connection_options = []) {
  }

  /**
   * @todo
   */
  public static function postConnectionConstruct(array &$connection_options = []) {
  }

  /**
   * @todo
   */
  public static function transactionSupport(array &$connection_options = []) {
    // This driver defaults to transaction support, except if explicitly passed FALSE.
    return !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);
  }

  /**
   * @todo
   */
  public static function transactionalDDLSupport(array &$connection_options = []) {
    // MySQL never supports transactional DDL.
    return FALSE;
  }

  /**
   * @todo
   */
  public static function preConnectionOpen(array &$connection_options = []) {
    if (isset($connection_options['_dsn_utf8_fallback']) && $connection_options['_dsn_utf8_fallback'] === TRUE) {
      // Only used during the installer version check, as a fallback from utf8mb4.
      $charset = 'utf8';
    }
    else {
      $charset = 'utf8mb4';
    }
    // Character set is added to dsn to ensure PDO uses the proper character
    // set when escaping. This has security implications. See
    // https://www.drupal.org/node/1201452 for further discussion.
    $connection_options['charset'] = $charset;
    // Allow PDO options to be overridden.
    $connection_options += [
      'driverOptions' => [],
    ];
    $connection_options['driverOptions'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      // So we don't have to mess around with cursors and unbuffered queries by default.
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
      // Make sure MySQL returns all matched rows on update queries including
      // rows that actually didn't have to be updated because the values didn't
      // change. This matches common behavior among other database systems.
      \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
      // Because MySQL's prepared statements skip the query cache, because it's dumb.
      \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ];
    if (defined('\PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      // An added connection option in PHP 5.5.21 to optionally limit SQL to a
      // single statement like mysqli.
      $connection_options['driverOptions'] += [\PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE];
    }
  }

  /**
   * @todo
   */
  public static function postConnectionOpen(DBALConnection $connection, array &$connection_options = []) {
    // Force MySQL to use the UTF-8 character set. Also set the collation, if a
    // certain one has been set; otherwise, MySQL defaults to
    // 'utf8mb4_general_ci' for utf8mb4.
    if (!empty($connection_options['collation'])) {
      $connection->exec('SET NAMES ' . $connection_options['charset'] . ' COLLATE ' . $connection_options['collation']);
    }
    else {
      $connection->exec('SET NAMES ' . $connection_options['charset']);
    }

    // Set MySQL init_commands if not already defined.  Default Drupal's MySQL
    // behavior to conform more closely to SQL standards.  This allows Drupal
    // to run almost seamlessly on many different kinds of database systems.
    // These settings force MySQL to behave the same as postgresql, or sqlite
    // in regards to syntax interpretation and invalid data handling.  See
    // https://www.drupal.org/node/344575 for further discussion. Also, as MySQL
    // 5.5 changed the meaning of TRADITIONAL we need to spell out the modes one
    // by one.
    $connection_options += [
      'init_commands' => [],
    ];
    $connection_options['init_commands'] += [
      'sql_mode' => "SET sql_mode = 'ANSI,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,ONLY_FULL_GROUP_BY'",
    ];
    // Execute initial commands.
    foreach ($connection_options['init_commands'] as $sql) {
      $connection->exec($sql);
    }
  }

  /**
   * @todo
   */
  public static function preCreateDatabase(DBALConnection $connection, $database) {
  }

  /**
   * @todo
   */
  public static function postCreateDatabase(DBALConnection $connection, $database) {
    // Set the database as active.
    $connection->exec("USE $database");
  }

}
