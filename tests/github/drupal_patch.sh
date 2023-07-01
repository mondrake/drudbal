#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#2992274 Installer tests fail if contrib driver hides database credentials form fields
# curl https://www.drupal.org/files/issues/2022-06-01/2992274-25.patch | git apply -v
git apply -v ./drudbal_staging/tests/github/2992274-local.patch

#3347497 Introduce a FetchModeTrait to allow emulating PDO fetch modes
curl https://git.drupalcode.org/project/drupal/-/merge_requests/3676.diff | git apply -v

#3355841 Allow DriverSpecificSchemaTestBase::testChangePrimaryKeyToSerial to execute for non-core drivers
curl https://www.drupal.org/files/issues/2023-04-23/3355841-2.patch | git apply -v

#3371751 Fix HelpSearch queries to ensure db identifiers are properly escaped
curl https://git.drupalcode.org/project/drupal/-/merge_requests/4301.diff | git apply -v
