Oracle implementation

CI

Using wnameless/oracle-xe-11g-r2 (Oracle XE 11g)
--------------------------------
services:
  oracle:
    image: "wnameless/oracle-xe-11g-r2"
    ports:
      - "1521:1521"

Using gvenzl/oracle-xe (Oracle XE 11, 18 and 21)
--------------------------------
services:
  oracle:
    image: gvenzl/oracle-xe:21
    ports:
      - "1521:1521"
    env:
      ORACLE_PASSWORD: oracle
  #    APP_USER: drudt
   #   APP_USER_PASSWORD: oracle
    options: >-
      --health-cmd healthcheck.sh
      --health-interval 20s
      --health-timeout 10s
      --health-retries 10
