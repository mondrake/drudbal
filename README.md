# DruDbal

[![Build Status](https://travis-ci.org/mondrake/drudbal.svg?branch=master)](https://travis-ci.org/mondrake/drudbal)

An __experimental__, work in progress, Drupal driver for Doctrine DBAL. __Do not use if not for trial. No support, sorry :)__

## Concept
The concept is to use Doctrine DBAL as an additional database abstraction layer. The code of the DBAL Drupal database driver is meant
to be 'database agnostic', i.e. the driver should be able to execute on any db platform that DBAL supports (in theory, practically
there still need to be db-platform specific hacks through the concept of DBAL extensions, see below).

The Drupal database ```Connection``` class that this driver implements opens a ```DBAL\Connection```, and hands over statements' execution to it. DBAL\Connection itself wraps a lower level driver connection (```PDO``` for pdo_mysql and pdo_sqlite drivers, ```mysqli``` for the mysqli driver).
Similarly, the ```Statement``` class is a wrapper of a ```DBAL\Statement```, which itself wraps a DBAL-driver level Statement.
The DBAL connection provides additional features like the Schema Manager that can introspect a database schema and build DDL statements, a Query Builder that can build SQL statements based on the database platform in use, etc. etc.

To overcome DBAL limitations and/or fit Drupal specifics, the DBAL Drupal database driver also instantiates an additional object
called ```DBALExtension```, unique for the DBAL Driver in use, to which some operations that are db- or Drupal-specific are
delegated.

## Status
(Updated: July 25, 2017)

The code in the ```master``` branch is working on a __MySql database__, using either the 'mysql' or the 'mysqli' DBAL driver, and on a __SQlite database__, using the 'sqlite' DBAL driver.

'Working' means:
1. it is possible to install a Drupal site via the installer, selecting 'Doctrine DBAL' as the database of choice;
2. it is passing a selection of core PHPUnit tests for the Database, Cache, Entity and views groups of tests, executed on Travis CI. The latest patches for the issues listed in 'Related Drupal issues' below need to be applied to get a clean test run.

The code in the ```dev-oracle``` branch installs on __Oracle__ 11.2.0.2.0, using DBAL 'oci8' driver, but fails tests. Big problems with Oracle:
1. there's a hard limit of 30 chars on database asset identifiers (tables, triggers, indexes, etc.). Apparently Oracle 12.2 overcomes that limit, raising lenght to 128 chars, but this currently requires all sort of workarounds as many objects names in Drupal are longer than that.
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

Very rough instructions to install Drupal from scratch with this db driver under the hood:

1. Requirements: build a Drupal code base via Composer, using latest Drupal development branch code and PHP 7.1+.

2. Get Doctrine DBAL, use latest version:
```
$ composer require doctrine/dbal:^2.6.0
```

3. Clone this repository to your contrib modules path:
```
$ cd [DRUPAL_ROOT]/[path_to_contrib_modules]
$ git clone https://github.com/mondrake/drudbal.git
```

4. Create a directory for the contrib driver, and create a symlink to the 'dbal' subdirectory of the module. This way, when git pulling updates from the module's repo, the driver code will also be aligned.
```
$ mkdir -p [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
$ cd [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
$ ln -s [DRUPAL_ROOT]/[path_to_contrib_modules]/drudbal/drivers/lib/Drupal/Driver/Database/dbal dbal
```

5. Launch the interactive installer. Proceed as usual and when on the db selection form, select 'Doctrine DBAL'
and enter a 'database URL' compliant with Doctrine DBAL syntax. __Note:__ the driver works only with mysql, mysqli or sqlite DBAL drivers.

![configuration](https://cloud.githubusercontent.com/assets/1174864/24586418/7f86feb4-17a0-11e7-820f-eb1483dad07f.png)

6. If everything goes right, when you're welcomed to the new Drupal installation, visit the Status Report. The 'database'
section will report something like:

![status_report](https://cloud.githubusercontent.com/assets/1174864/24586319/d294c5f8-179d-11e7-8cb7-884522124e8c.png)

## Related DBAL issues/PRs
Issue | Description   | Info          |
------|---------------|---------------|
https://github.com/doctrine/dbal/issues/1349     | DBAL-182: Insert and Merge Query Objects | |
https://github.com/doctrine/dbal/issues/1320     | DBAL-163: Upsert support in DBAL | |
https://github.com/doctrine/dbal/pull/682        | [WIP] [DBAL-218] Add bulk insert query | |
https://github.com/doctrine/dbal/pull/2717       | Introspect table comments in Doctrine\DBAL\Schema\Table when generating schema | |
https://github.com/doctrine/dbal/issues/1033     | DBAL-1096: schema-tool:update does not understand columnDefinition correctly | |
https://github.com/doctrine/dbal/pull/881        | Add Mysql per-column charset support | |
https://github.com/doctrine/dbal/pull/2412       | Add mysql specific indexes with lengths | |
https://github.com/doctrine/migrations/issues/17 | Data loss on table renaming. | |
https://github.com/doctrine/dbal/issues/2676     | Optimize Oracle SchemaManager  | |

## Related Drupal issues
Issue | Description   |
------|---------------|
[2605284](https://www.drupal.org/node/2605284) | Testing framework does not work with contributed database drivers |
[2867788](https://www.drupal.org/node/2867788) | Log::findCaller fails to report the correct caller function with non-core drivers |
[2868273](https://www.drupal.org/node/2868273) | Missing a test for table TRUNCATE while in transaction |
[2871374](https://www.drupal.org/node/2871374) | SelectTest::testVulnerableComment fails when driver overrides Select::\_\_toString |
tbd | Add tests for Upsert with default values |
[2874499](https://www.drupal.org/node/2874499) | Test failures when db driver is set to not support transactions |
[2875679](https://www.drupal.org/node/2875679) | BasicSyntaxTest::testConcatFields fails with contrib driver |
[2879677](https://www.drupal.org/node/2879677) | Decouple getting table vs column comments in Schema |
[2881522](https://www.drupal.org/node/2881522) | Add a Schema::getPrimaryKeyColumns method to remove database specific logic from test |
