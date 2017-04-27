<?php

namespace Drupal\Driver\Database\dbal\DbalExtension;

/**
 * Driver specific methods for pdo_mysql.
 */
class PDOMySql extends AbstractMySqlExtension {

  /**
   * {@inheritdoc}
   */
  public function clientVersion() {
    return $this->dbalConnection->getWrappedConnection()->getAttribute(\PDO::ATTR_CLIENT_VERSION);
  }

}
