<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Transaction as DatabaseTransaction;

/**
 * DruDbal implementation of \Drupal\Core\Database\Transaction.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Transaction extends DatabaseTransaction {

}
