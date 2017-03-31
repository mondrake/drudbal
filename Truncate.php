<?php

namespace Drupal\Driver\Database\drubal;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

/**
 * DRUBAL implementation of \Drupal\Core\Database\Query\Truncate.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to driver specific code
 * in Drupal\Driver\Database\drubal\DBALDriver\[driver_name] classes and
 * execution handed over to there.
 */
class Truncate extends QueryTruncate { }
