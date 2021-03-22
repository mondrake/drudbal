The driver can also install on __Oracle__ 11.2.0.2.0, using DBAL 'oci8' driver, but fails tests. Big problems with Oracle:
1. there's a hard limit of 30 chars on database asset identifiers (tables, triggers, indexes, etc.). Apparently Oracle 12.2 overcomes that limit, raising length to 128 chars, but this currently requires all sort of workarounds as many objects names in Drupal are longer than that.
2. Oracle treats NULL and '' (empty string) in the same way. Drupal practice is to use these as different items - it builds CREATE TABLE statements with column definitions like "cid VARCHAR(255) DEFAULT '' NOT NULL" which is self-contradicting in Oracle terms.
3. DBAL schema introspection is very slow on Oracle, see https://github.com/doctrine/dbal/issues/2676. This makes difficult to run the interactive installer since as at each batch request the schema get rebuilt.

## Driver classes
Class                         | Status        |
------------------------------|---------------|
Connection                    | Implemented as a wrapper around ```DBAL\Connection```. |
Delete                        | Implemented. Can execute DBAL queries directly if no comments are required in the SQL statement.  |
Insert                        | Implemented with overrides to the ```execute``` and ```::__toString``` methods. |
Merge                         | Inheriting from ```\Drupal\Core\Database\Query\Merge```. DBAL does not support MERGE constructs, the INSERT with UPDATE fallback implemented by the base class fits the purpose. |
Schema                        | Implemented. |
Select                        | Implemented with override to the ```::__toString``` method. Consider integrating at higher level. |
Statement/StatementWrapper    | Implemented as a wrapper around ```DBAL\Statement```. |
Transaction                   | Inheriting from ```\Drupal\Core\Database\Transaction```. Maybe in the future look into DBAL Transaction Management features. |
Truncate                      | Implemented with overrides to the ```execute``` and ```::__toString``` methods. |
Update                        | Implemented. |
Upsert                        | Implemented with overrides to the ```execute``` and ```::__toString``` methods. DBAL does not support UPSERT, so implementation opens a transaction and proceeds with an INSERT attempt, falling back to UPDATE in case of failure. |
Install/Tasks	                | Implemented. |
