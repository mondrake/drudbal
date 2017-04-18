# DruDbal
An __experimental__, work in progress, Drupal driver for Doctrine DBAL. The concept is to use Doctrine DBAL as an
additional database abstraction layer, implementing a database agnostic Drupal driver, that hands over database
operations to DBAL. Do not use if not for trial. No support, sorry :)

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
