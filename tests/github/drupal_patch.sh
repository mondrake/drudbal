#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#2992274 Installer tests fail if contrib driver hides database credentials form fields
# curl https://www.drupal.org/files/issues/2022-06-01/2992274-25.patch | git apply -v
git apply -v ./drudbal_staging/tests/github/2992274-local.patch

#3389397 WebDriverCurlService::execute() needs a @return annotation
curl https://git.drupalcode.org/project/drupal/-/merge_requests/4865.diff | git apply -v

#3397302 Notation of placeholders in SqlContentEntityStorageRevisionDataCleanupTest is incorrect
curl https://git.drupalcode.org/project/drupal/-/merge_requests/5158.diff | git apply -v

#3396559 Only set content-length header in specific situations
curl https://git.drupalcode.org/project/drupal/-/merge_requests/5121.diff | git apply -v
