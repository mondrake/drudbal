# DruDbal

[![Build Status](https://travis-ci.org/mondrake/drudbal.svg?branch=master)](https://travis-ci.org/mondrake/drudbal)

An __experimental__, work in progress, Drupal driver for Doctrine DBAL. __Do not use if not for trial. No support, sorry :)__

## Concept
The concept is to use Doctrine DBAL as an additional database abstraction layer. The code of the DBAL Drupal database driver is meant
to be 'database agnostic', i.e. the driver should be able to execute on any db platform that DBAL supports (in theory, practically
there still need to be db-platform specific hacks through the concept of DBAL extensions, see below).

The Drupal database ```Connection``` class that this driver implements opens a ```DBAL\Connection```, and hands over
statements' execution to it. DBAL\Connection itself wraps a ```PDO``` connection (at least for the pdo_mysql driver). In addition,
the DBAL connection provides additional features like the Schema Manager that can introspect a database schema and build
DDL statements, a Query Builder that can build SQL statements based on the database platform in use, etc. etc.

To overcome DBAL limitations and/or fit Drupal specifics, the DBAL Drupal database driver also instantiates an additional object
called ```DBALExtension```, unique for the DBAL Driver in use, to which some operations that are db- or Drupal-specific are
delegated.

## Status
(Updated: May 5, 2017)

The code in the ```master``` branch is meant to be working on a __MySql database, using either the 'mysql' or the 'mysqli' DBAL driver__.

'Working' means:
1. it is possible to install a Drupal site via the installer, selecting 'Doctrine DBAL' as the database of choice;
2. it is passing a selection of core PHPUnit tests for the Database, Cache and Entity groups of tests, executed on Travis CI.

The status of the driver classes implementation is as follows:

Class                         | Status        |
------------------------------|---------------|
Connection                    | Implemented. |
Delete                        | Implemented. Can execute DBAL queries directly if no comments are required in the SQL statement.  |
Insert                        | Implemented with overrides to the ```execute``` and ```::__toString``` methods. |
Merge                         | Inheriting from ```\Drupal\Core\Database\Query\Merge```. DBAL does not support MERGE constructs, the INSERT with UPDATE fallback implemented by the base class fits the purpose. |
Schema                        | Implemented. |
Select                        | Implemented with override to the ```::__toString``` method. Consider integrating at higher level. |
Transaction                   | Inheriting from ```\Drupal\Core\Database\Transaction```. Maybe in the future look into DBAL Transaction Management features. |
Truncate                      | Implemented with overrides to the ```execute``` and ```::__toString``` methods. |
Update                        | Implemented. |
Upsert                        | Implemented with overrides to the ```execute``` and ```::__toString``` methods. DBAL does not support UPSERT, so implementation opens a transaction and proceeds with an INSERT attempt, falling back to UPDATE in case of failure. |
Install/Tasks	                | Implemented. |
Statement/MysqliDbalStatement	| Implements a wrapper to the _mysqli_ statement. |
Statement/PDODbalStatement  	| Overrides the base class ```\Drupal\Core\Database\Statement```, to allow executing DBAL queries directly. |

## Installation

Very rough instructions to install Drupal from scratch with this db driver under the hood:

1. Get a fresh code build of Drupal via Composer. Use latest Drupal dev. Use PHP 7.0+.

2. Get Doctrine DBAL, use latest version:
```
$ composer require doctrine/dbal:^2.5.12
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
and enter a 'database URL' compliant with Doctrine DBAL syntax. __Note:__ the driver only works for mysql or mysqli DBAL drivers.

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
https://github.com/doctrine/dbal/issues/2380     | Unsigned numeric columns not generated correctly | Fixed in 2.6.0 |
https://github.com/doctrine/migrations/issues/17 | Data loss on table renaming. | |
https://github.com/doctrine/dbal/pull/2720       | SQLParserUtils::getPlaceholderPositions fails if there are quoted strings containing only backslashes | |
https://github.com/doctrine/dbal/pull/2725       | Duplicate 'COMMENT' part in SQL statement | |

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
