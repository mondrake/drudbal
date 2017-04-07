# DruDbal
An __experimental__, work in progress, Drupal driver for Doctrine DBAL. The concept is to use Doctrine DBAL as an
additional database abstraction layer, implementing a database agnostic Drupal driver, that hands over database
operations to DBAL.

## Setup
```
$ composer require drupal/drudbal:^1
```

```
$ mkdir -p [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
$ cd [DRUPAL_ROOT]/drivers/lib/Drupal/Driver/Database/
$ ln -s [DRUPAL_ROOT]/[path_to_contrib_modules]/drudbal/drivers/lib/Drupal/Driver/Database/dbal dbal
```

## Database configuration
![configuration](https://cloud.githubusercontent.com/assets/1174864/24586418/7f86feb4-17a0-11e7-820f-eb1483dad07f.png)

## Status report
![status_report](https://cloud.githubusercontent.com/assets/1174864/24586319/d294c5f8-179d-11e7-8cb7-884522124e8c.png)

## Related Drupal issues
Issue | Description   |
------|---------------|
[2605284](https://www.drupal.org/node/2605284) | Testing framework does not work with contributed database drivers |
[2867700](https://www.drupal.org/node/2867700) | ConnectionUnitTest::testConnectionOpen fails if the driver is not implementing a PDO connection |
[2867788](https://www.drupal.org/node/2867788) | Log::findCaller fails to report the correct caller function with non-core drivers |
