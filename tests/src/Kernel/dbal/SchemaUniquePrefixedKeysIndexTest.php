<?php

namespace Drupal\Tests\drudbal\Kernel\dbal;

use Drupal\KernelTests\Core\Database\SchemaUniquePrefixedKeysIndexTestBase;

/**
 * Tests adding UNIQUE keys to tables.
 *
 * @group Databasexx
 */
class SchemaUniquePrefixedKeysIndexTest extends SchemaUniquePrefixedKeysIndexTestBase {

  /**
   * {@inheritdoc}
   */
  protected string $columnValue = '1234567890 foo';

}
