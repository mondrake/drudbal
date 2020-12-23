TODO

* Connection::createUrlFromConnectionOptions does not export the module name
* standard profile in Oracle install
* lowercase identifiers Oracle
* tests Oracle
* new identifier management class


-----------

  mysql-pdo:
    name: "MySql with PDO"
    runs-on: ubuntu-latest
    env:
        DRUDBAL_ENV: "dbal/mysql"
        DBAL_URL: "mysql://root:@0.0.0.0:3306/drudbal"
        SIMPLETEST_DB: "dbal://root:@0.0.0.0:3306/drudbal?module=drudbal&dbal_driver=pdo_mysql"

    strategy:
      matrix:
        php-version:
          - "7.4"
        test-args:
          - "--group Database"

    services:
      mysql:
        image: "mysql:5.7"
        options: >-
          -e MYSQL_ALLOW_EMPTY_PASSWORD=yes
          -e MYSQL_DATABASE=drudbal
        ports:
          - "3306:3306"

    steps:
    - name: Install PHP
      uses: "shivammathur/setup-php@v2"
      with:
        php-version: "${{ matrix.php-version }}"
        coverage: "none"
        extensions: "date,dom,filter,gd,hash,json,pcre,pdo_mysql,session,SimpleXML,SPL,tokenizer,xml"
        ini-values: "zend.assertions=1"

    - name: Checkout Drupal
      run: git clone --depth=5 --branch=$DRUDBAL_DRUPAL_VERSION http://git.drupal.org/project/drupal.git .

    - name: Checkout DruDbal
      uses: actions/checkout@v2
      with:
        path: drudbal_staging

    - name: '#2657888 Add Date function support in DTBNG'
      run: curl https://www.drupal.org/files/issues/2657888-18.patch | git apply -v

    - name: '#2992274 Installer tests fail if contrib driver hides database credentials form fields'
      run: curl https://www.drupal.org/files/issues/2020-11-23/2992274-13.patch | git apply -v

    - name: '#3110546 Allow contributed modules (mostly database drivers) to override tests in core'
      run: git apply -v drudbal_staging/tests/travis_ci/alt-fix.patch

    - name: Install Composer dependencies
      run: |
        composer install --no-progress --ansi
        composer run-script drupal-phpunit-upgrade
        composer require drush/drush --no-progress --ansi

    - name: Composer require DruDbal from local staging
      run: |
        git -C drudbal_staging checkout -b travisci-run-branch
        composer config repositories.travisci-run '{"type": "path", "url": "drudbal_staging", "options": {"symlink": false}}'
        composer require "mondrake/drudbal:dev-travisci-run-branch" --no-progress --ansi

    - name: Install Drupal
      run: |
        cp modules/contrib/drudbal/tests/travis_ci/install_* .
        # Use the custom installer.
        php install_cli.php
        vendor/bin/drush runserver localhost:8080 &

    - name: Report installation
      run: php install_report.php

    - name: Run test ${{ matrix.test-args }}
      run: vendor/bin/phpunit -c core --color=always ${{ matrix.test-args }}
