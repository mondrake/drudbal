<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\KernelTests\Core\Database\BasicSyntaxTest as BasicSyntaxTestBase;

/**
 * Tests SQL syntax interpretation.
 *
 * In order to ensure consistent SQL handling throughout Drupal
 * across multiple kinds of database systems, we test that the
 * database system interprets SQL syntax in an expected fashion.
 *
 * @group Database
 */
class BasicSyntaxTest extends BasicSyntaxTestBase {

  /**
   * Tests allowing square brackets in queries.
   */
  public function testAllowSquareBrackets() {
    $this->connection->insert('test')
      ->fields(['name'])
      ->values([
        'name' => '[square]',
      ])
      ->execute();

    // Note that this is a very bad example query because arguments should be
    // passed in via the $args parameter.
    $result = $this->connection->query("select \"name\" from {test} where \"name\" = '[square]'", [], ['allow_square_brackets' => TRUE]);
    $this->assertSame('[square]', $result->fetchField());

    // Test that allow_square_brackets has no effect on arguments.
    $result = $this->connection->query("select [name] from {test} where [name] = :value", [':value' => '[square]']);
    $this->assertSame('[square]', $result->fetchField());
    $result = $this->connection->query("select \"name\" from {test} where \"name\" = :value", [':value' => '[square]'], ['allow_square_brackets' => TRUE]);
    $this->assertSame('[square]', $result->fetchField());

    // Test square brackets using the query builder.
    $result = $this->connection->select('test')->fields('test', ['name'])->condition('name', '[square]')->execute();
    $this->assertSame('[square]', $result->fetchField());
  }

}
