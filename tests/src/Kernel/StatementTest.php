<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\StatementInterface;
use Drupal\KernelTests\Core\Database\StatementTest as StatementTestBase;

/**
 * Tests the Statement classes.
 *
 * @group Database
 */
class StatementTest extends StatementTestBase {

  /**
   * Tests accessing deprecated properties.
   */
  public function testGetDeprecatedProperties(): void {
    $this->markTestSkipped('It\'s deprecated, Jim.');
  }

  /**
   * Tests writing deprecated properties.
   */
  public function testSetDeprecatedProperties(): void {
    $this->markTestSkipped('It\'s deprecated, Jim.');
  }

}
