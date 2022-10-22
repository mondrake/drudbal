<?php

namespace Drupal\drudbal\StatementMonitoring\dbal;

use Drupal\drudbal\Driver\Database\dbal\Connection as BaseConnection;
use Drupal\database_statement_monitoring_test\LoggedStatementsTrait;

/**
 * DBAL driver Connection class that can log executed queries.
 */
class Connection extends BaseConnection {
  use LoggedStatementsTrait;

}
