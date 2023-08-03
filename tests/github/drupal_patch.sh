#!/bin/sh -e

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#2992274 Installer tests fail if contrib driver hides database credentials form fields
# curl https://www.drupal.org/files/issues/2022-06-01/2992274-25.patch | git apply -v
git apply -v ./drudbal_staging/tests/github/2992274-local.patch

#Revert #3295790 Post-response task running (destructable services) are actually blocking; add test coverage and warn for common misconfiguration
git apply -v ./drudbal_staging/tests/github/3295790-revert.patch
