# DruDbal

[![Build Status](https://travis-ci.org/mondrake/drudbal.svg?branch=master)](https://travis-ci.org/mondrake/drudbal)
[![Total Downloads](https://img.shields.io/packagist/dt/mondrake/drudbal.svg)](https://packagist.org/packages/mondrake/drudbal)
[![License](https://img.shields.io/github/license/mondrake/drudbal.svg)](https://packagist.org/packages/mondrake/drudbal)

An __experimental__ Drupal driver for Doctrine DBAL. __Do not use if not for trial. No support, sorry :)__

## Concept
The concept is to use Doctrine DBAL as an additional database abstraction layer. The code of the DBAL Drupal database driver is meant
to be 'database agnostic', i.e. the driver should be able to execute on any db platform that DBAL supports (in theory, practically
there still need to be db-platform specific hacks through the concept of DBAL extensions, see below).

The Drupal database ```Connection``` class that this driver implements opens a ```DBAL\Connection```, and hands over statements' execution to it. ```DBAL\Connection``` itself wraps a lower level driver connection (```PDO``` for pdo_mysql and pdo_sqlite drivers, ```mysqli``` for the mysqli driver).
Similarly, the ```Statement``` class is a wrapper of a ```DBAL\Statement```, which itself wraps a DBAL-driver level ```Statement```.
The DBAL connection provides additional features like the Schema Manager that can introspect a database schema and build DDL statements, a Query Builder that can build SQL statements based on the database platform in use, etc. etc.

To overcome DBAL limitations and/or fit Drupal specifics, the DBAL Drupal database driver also instantiates an additional object
called ```DBALExtension```, unique for the DBAL Driver in use, to which some operations that are db- or Drupal-specific are
delegated.

## Status
(Updated: June 16, 2019)

The code in the ```master``` branch is working on a __MySql database__, using either the 'mysql' or the 'mysqli' DBAL driver, and on a __SQlite database__, using the 'sqlite' DBAL driver.

'Working' means:
1. it is possible to install a Drupal site via the installer, selecting 'Doctrine DBAL' as the database of choice;
2. it is passing a selection of core PHPUnit tests for the Database, Cache, Entity and views groups of tests, executed on Travis CI. The latest patches for the issues listed in 'Related Drupal issues' below need to be applied to get a clean test run.

The driver can also install on __Oracle__ 11.2.0.2.0, using DBAL 'oci8' driver, but fails tests. Big problems with Oracle:
1. there's a hard limit of 30 chars on database asset identifiers (tables, triggers, indexes, etc.). Apparently Oracle 12.2 overcomes that limit, raising length to 128 chars, but this currently requires all sort of workarounds as many objects names in Drupal are longer than that.
2. Oracle treats NULL and '' (empty string) in the same way. Drupal practice is to use these as different items - it builds CREATE TABLE statements with column definitions like "cid VARCHAR(255) DEFAULT '' NOT NULL" which is self-contradicting in Oracle terms.
3. DBAL schema introspection is very slow on Oracle, see https://github.com/doctrine/dbal/issues/2676. This makes difficult to run the interactive installer since as at each batch request the schema get rebuilt.

## Driver classes
Class                         | Status        |
------------------------------|---------------|
Connection                    | Implemented as a wrapper around ```DBAL\Connection```. |
Delete                        | Implemented. Can execute DBAL queries directly if no comments are required in the SQL statement.  |
Insert                        | Implemented with overrides to the ```execute``` and ```::__toString``` methods. |
Merge                         | Inheriting from ```\Drupal\Core\Database\Query\Merge```. DBAL does not support MERGE constructs, the INSERT with UPDATE fallback implemented by the base class fits the purpose. |
Schema                        | Implemented. |
Select                        | Implemented with override to the ```::__toString``` method. Consider integrating at higher level. |
Statement                     | Implemented as a wrapper around ```DBAL\Statement```. |
Transaction                   | Inheriting from ```\Drupal\Core\Database\Transaction```. Maybe in the future look into DBAL Transaction Management features. |
Truncate                      | Implemented with overrides to the ```execute``` and ```::__toString``` methods. |
Update                        | Implemented. |
Upsert                        | Implemented with overrides to the ```execute``` and ```::__toString``` methods. DBAL does not support UPSERT, so implementation opens a transaction and proceeds with an INSERT attempt, falling back to UPDATE in case of failure. |
Install/Tasks	                | Implemented. |

## Installation
(Updated: June 16, 2019)

Very rough instructions to install Drupal from scratch with this db driver under the hood:

1. Requirements: build a Drupal code base via Composer, using latest Drupal development branch code and PHP 7.3+.

2. Get the library via Composer, it will install Doctrine DBAL as well:
  ```
  $ composer require mondrake/drudbal:dev-master
  ```

3. Create a directory for the contrib driver, and create a symlink to the 'dbal' subdirectory of the module.
This way, when running ```composer update``` for ```mondrake/drudbal```, the driver will be updated.
  ```
  $ mkdir -p [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
  $ cd [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
  $ ln -s [DRUPAL_ROOT]/libraries/drudbal/lib dbal
  ```

4. Launch the interactive installer. Proceed as usual and when on the db selection form, select 'Doctrine DBAL'
and enter a 'database URL' compliant with Doctrine DBAL syntax. __Note:__ the driver works only with _mysql, mysqli, oci8 or sqlite_ DBAL drivers.

![configuration](https://cloud.githubusercontent.com/assets/1174864/24586418/7f86feb4-17a0-11e7-820f-eb1483dad07f.png)

5. If everything goes right, when you're welcomed to the new Drupal installation, visit the Status Report. The 'database'
section will report something like:

![status_report](https://user-images.githubusercontent.com/1174864/29685128-ca25375c-8914-11e7-8305-9ba369f68067.png)

## Related DBAL issues/PRs
Issue | Description   | Info          |
------|---------------|---------------|
https://github.com/doctrine/dbal/issues/1349     | DBAL-182: Insert and Merge Query Objects | |
https://github.com/doctrine/dbal/issues/1320     | DBAL-163: Upsert support in DBAL | |
https://github.com/doctrine/dbal/pull/682        | [WIP] [DBAL-218] Add bulk insert query | |
https://github.com/doctrine/dbal/issues/1033     | DBAL-1096: schema-tool:update does not understand columnDefinition correctly | |
https://github.com/doctrine/migrations/issues/17 | Data loss on table renaming. | |
https://github.com/doctrine/dbal/issues/2676     | Optimize Oracle SchemaManager | |
https://github.com/doctrine/dbal/pull/2415       | Add some MySQL platform data in Tables | fixed in 2.9.0 |

## Related Drupal issues
Issue | Description   |
------|---------------|
tbd | Add tests for Upsert with default values |
[2657888](https://www.drupal.org/node/2657888) | Add Date function support in DTBNG |
tbd | Ensure that when INSERTing a NULL value in a database column, SELECTing it back returns NULL and not empty string - for all fetch modes |
tbd | UpdateTestBase::runUpdate should reset database schema after updating |
[2992274](https://www.drupal.org/project/drupal/issues/2992274) | Installer tests fail if contrib driver hides database credentials form fields |
