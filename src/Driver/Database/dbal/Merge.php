<?php

namespace Drupal\drudbal\Driver\Database\dbal;

use Drupal\Core\Database\Query\Merge as QueryMerge;
use Drupal\Core\Database\Query\InvalidMergeQueryException;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * DruDbal implementation of \Drupal\Core\Database\Query\Merge.
 *
 * Note: there should not be db platform specific code here. Any tasks that
 * cannot be managed by Doctrine DBAL should be added to extension specific
 * code in Drupal\drudbal\Driver\Database\dbal\DbalExtension\[dbal_driver_name]
 * classes and execution handed over to there.
 */
class Merge extends QueryMerge {

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $connection, string $table, array $options = []) {
    // @todo Remove the __construct in Drupal 11.
    // @see https://www.drupal.org/project/drupal/issues/3256524
    parent::__construct($connection, $table, $options);
    unset($this->queryOptions['return']);
  }

}
