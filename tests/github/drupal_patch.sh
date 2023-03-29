#!/bin/sh -e

#2992274 Installer tests fail if contrib driver hides database credentials form fields
# curl https://www.drupal.org/files/issues/2022-06-01/2992274-25.patch | git apply -v

#3110546 Allow contributed modules (mostly database drivers) to override tests in core
curl https://git.drupalcode.org/project/drupal/-/merge_requests/291.diff | git apply -v

#3256642 Autoload classes of database drivers modules' dependencies
curl https://git.drupalcode.org/project/drupal/-/merge_requests/2844.diff | git apply -v

#3347497 Introduce a FetchModeTrait to allow emulating PDO fetch modes
curl https://git.drupalcode.org/project/drupal/-/merge_requests/3676.diff | git apply -v
