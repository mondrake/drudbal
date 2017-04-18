# DruDbal
An __experimental__, work in progress, Drupal driver for Doctrine DBAL. __Do not use if not for trial. No support, sorry :)__

## Concept
The concept is to use Doctrine DBAL as an additional database abstraction layer. The code of the DBAL Drupal database driver is meant
to be 'database agnostic', i.e. the driver should be able to execute on any db platform that DBAL supports (theoretically ;)).

The Drupal database ```Connection``` class that this driver implements opens a ```DBAL\Connection```, and hands over
statements' execution to it. DBAL\Connection itself wraps a ```PDO``` connection (at least for the pdo_mysql driver). In addition,
the DBAL connection provides additional features like the Schema Manager that can introspect a database schema and build
DDL statements, a Query Builder that can build SQL statements based on the database platform in use, etc. etc.

To overcome DBAL limitations and/or fit Drupal specifics, the DBAL Drupal database driver also instantiates an additional object
called ```DBALExtension```, unique for the DBAL Driver in use, to which some operations that are db- or Drupal-specific are
delegated.

## Status
The code in the ```master``` branch is meant to be working on a MySql database, using a PDO connection. 

'Working' means passing a ```phpunit --group Database``` test run with the driver being used. The latest patches
referenced below in the 'Related Drupal issues' need to be applied to get the test run.

The status of the driver classes implementation is as follows:

Class | Status   |
------|---------------|
Connection  | implemented |
Delete      | implemented |
Insert      | implemented |
Merge       | currently inheriting from base class, needs work |
Schema      | implemented |
Select      | currently inheriting from base class, needs work |
Transaction | inheriting from base class. OK as is ATM, maybe look into DBAL Transaction Management features in the future |
Truncate    | implemented |
Update      | implemented |
Upsert      | implemented |
Install/Tasks	| implemented |

## Installation

Very rough instructions to install Drupal from scratch with this db driver under the hood:

1. Get a fresh code build of Drupal via Composer. Use latest Drupal dev. Use PHP 7.0+. Only works with MySql/PDO.

2. Get Doctrine DBAL, use latest version:
```
$ composer require doctrine/dbal:^2.5.12
```

3. Clone this repository to your contrib modules path:
```
$ cd [DRUPAL_ROOT]/[path_to_contrib_modules]
$ git clone https://github.com/mondrake/drudbal.git
```

4. Create a directory for the contrib driver, and create a symlink to the 'dbal' subdirectory of the module.
This way, when git pulling updates from the module's repo, the driver code will also be aligned.
```
$ mkdir -p [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
$ cd [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
$ ln -s [DRUPAL_ROOT]/[path_to_contrib_modules]/drudbal/drivers/lib/Drupal/Driver/Database/dbal dbal
```

5. Launch the interactive installer. Proceed as usual and when on the db selection form, select 'Doctrine DBAL'
and enter a 'database URL' compliant with Doctrine DBAL syntax:

![configuration](https://cloud.githubusercontent.com/assets/1174864/24586418/7f86feb4-17a0-11e7-820f-eb1483dad07f.png)

6. If everything goes right, when you're welcomed to the new Drupal installation, visit the Status Report. The 'database'
section will report something like:

![status_report](https://cloud.githubusercontent.com/assets/1174864/24586319/d294c5f8-179d-11e7-8cb7-884522124e8c.png)

## Related DBAL issues/PRs
Issue | Description   |
------|---------------|
https://github.com/doctrine/dbal/issues/1320 | DBAL-163: Upsert support in DBAL |
https://github.com/doctrine/dbal/pull/682    | [WIP] [DBAL-218] Add bulk insert query |
https://github.com/doctrine/dbal/issues/1335 | DBAL-175: Table comments in Doctrine\DBAL\Schema\Table Object |
https://github.com/doctrine/dbal/issues/1033 | DBAL-1096: schema-tool:update does not understand columnDefinition correctly |
https://github.com/doctrine/dbal/pull/881    | Add Mysql per-column charset support |
https://github.com/doctrine/dbal/pull/2412   | Add mysql specific indexes with lengths |
https://github.com/doctrine/dbal/issues/2380 | Unsigned numeric columns not generated correctly |

## Related Drupal issues
Issue | Description   |
------|---------------|
[2605284](https://www.drupal.org/node/2605284) | Testing framework does not work with contributed database drivers |
[2867700](https://www.drupal.org/node/2867700) | ConnectionUnitTest::testConnectionOpen fails if the driver is not implementing a PDO connection |
[2867788](https://www.drupal.org/node/2867788) | Log::findCaller fails to report the correct caller function with non-core drivers |
