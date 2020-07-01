# DruDbal
(Updated: March 30, 2020)

[![Build Status](https://travis-ci.org/mondrake/drudbal.svg?branch=master)](https://travis-ci.org/mondrake/drudbal)
[![License](https://img.shields.io/github/license/mondrake/drudbal.svg)](https://packagist.org/packages/mondrake/drudbal)

An __experimental__ Drupal driver for Doctrine DBAL. __Do not use if not for trial. No support, sorry :)__

## Concept
The concept is to use Doctrine DBAL as an additional database abstraction
layer. The code of the DBAL Drupal database driver is meant to be 'database
agnostic', i.e. the driver should be able to execute on any db platform that
DBAL supports (in theory, practically there still need to be db-platform
specific hacks through the concept of DBAL extensions, see below).

The Drupal database ```Connection``` class that this driver implements opens
a ```DBAL\Connection```, and hands over statements' execution to it.
```DBAL\Connection``` itself wraps a lower level driver connection (```PDO```
for pdo_mysql and pdo_sqlite drivers, ```mysqli``` for the mysqli driver).
Similarly, the ```Statement``` class is a wrapper of a ```DBAL\Statement```,
which itself wraps a DBAL-driver level ```Statement```.
The DBAL connection provides additional features like the Schema Manager
that can introspect a database schema and build DDL statements, a Query
Builder that can build SQL statements based on the database platform in use,
etc. etc.

To overcome DBAL limitations and/or fit Drupal specifics, the DBAL Drupal
database driver also instantiates an additional object called
```DBALExtension```, unique for the DBAL Driver in use, to which some
operations that are db- or Drupal-specific are delegated.

## Status

The code in the ```master``` branch is working on a __MySql database__, using
either the 'mysql' or the 'mysqli' DBAL driver, and on a __SQlite database__,
using the 'sqlite' DBAL driver.

'Working' means:
1. it is possible to install a Drupal site via the installer, selecting
   'Doctrine DBAL' as the database of choice;
2. it is passing a selection of core PHPUnit tests , executed on Travis CI.
   The latest patches for the issues listed in 'Related Drupal issues' below
   need to be applied to get a clean test run.

## Installation

Very rough instructions to install Drupal from scratch with this db driver
under the hood:

1. Requirements:
    * PHP 7.3+
    * latest Drupal development branch code, 9.1.x
    * codebase built via Composer

2. Get the DruDbal module from Packagist via Composer, it will install Doctrine
   DBAL as well:
  ```
  $ composer require mondrake/drudbal:dev-master
  ```

3. Launch the interactive installer. Proceed as usual and when on the db
   selection form, select 'Doctrine DBAL' and enter a 'database URL' compliant
   with Doctrine DBAL syntax. __Note:__ the driver works only with _mysql,
   mysqli or sqlite_ DBAL drivers.

![configuration](https://cloud.githubusercontent.com/assets/1174864/24586418/7f86feb4-17a0-11e7-820f-eb1483dad07f.png)

4. If everything goes right, when you're welcomed to the new Drupal
   installation, visit the Status Report. The 'database' section will report
   something like:

![status_report](https://user-images.githubusercontent.com/1174864/29685128-ca25375c-8914-11e7-8305-9ba369f68067.png)

## Related DBAL issues/PRs
Issue                                            | Description                                                                  | Info           |
-------------------------------------------------|------------------------------------------------------------------------------|----------------|
https://github.com/doctrine/dbal/issues/1349     | DBAL-182: Insert and Merge Query Objects                                     |                |
https://github.com/doctrine/dbal/issues/1320     | DBAL-163: Upsert support in DBAL                                             |                |
https://github.com/doctrine/dbal/pull/2762       | Bulk inserts                                                                 |                |
https://github.com/doctrine/dbal/issues/1033     | DBAL-1096: schema-tool:update does not understand columnDefinition correctly |                |
https://github.com/doctrine/migrations/issues/17 | Data loss on table renaming.                                                 |                |
https://github.com/doctrine/dbal/issues/2676     | Optimize Oracle SchemaManager                                                |                |
https://github.com/doctrine/dbal/pull/2415       | Add some MySQL platform data in Tables                                       | fixed in 2.9.0 |

## Related Drupal issues
Issue                                                           | Description                                                                                                                             |
----------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
tbd                                                             | Add tests for Upsert with default values                                                                                     |
[2657888](https://www.drupal.org/node/2657888)                  | Add Date function support in DTBNG                                                                                      |
tbd                                                             | Ensure that when INSERTing a NULL value in a database column, SELECTing it back returns NULL and not empty string - for all fetch modes |
tbd                                                             | UpdateTestBase::runUpdate should reset database schema after updating                                                             |
[2992274](https://www.drupal.org/project/drupal/issues/2992274) | Installer tests fail if contrib driver hides database credentials form fields                                                           |
[3128616](https://www.drupal.org/project/drupal/issues/3128616) | Replace \Drupal\Core\Database\Connection::destroy() with a proper destructor                                                                                 |
