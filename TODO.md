TODO

* new identifier management class
* driver::getName was removed

SCHEMA
------
* DBAL 2.7: implemented mysql column-level collation, check

STATEMENT
---------
* DBAL 2.6.0: Normalize method signatures for `fetch()` and `fetchAll()`, ensuring compatibility with the `PDOStatement` signature
* DBAL 2.6.0: `ResultStatement#fetchAll()` must define 3 arguments in order to be compatible with `PDOStatement#fetchAll()`
