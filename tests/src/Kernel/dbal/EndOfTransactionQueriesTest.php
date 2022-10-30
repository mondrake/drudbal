<?php

namespace Drupal\Tests\drudbal\Kernel\dbal;

use Drupal\KernelTests\Core\Cache\DriverSpecificEndOfTransactionQueriesTestBase;

/**
 * Tests that cache tag invalidation queries are delayed to the end of transactions.
 *
 * @group Cache
 */
class EndOfTransactionQueriesTest extends DriverSpecificEndOfTransactionQueriesTestBase {
}
