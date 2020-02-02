#!/bin/bash
LOG_FILE='/tmp/satis.log'

echo "[$(date)] Build started" >> $LOG_FILE
/usr/local/bin/php /var/www/satisfy/bin/console satisfy:rebuild -vvv --skip-errors /var/www/satisfy/satis.json /var/www/html >> $LOG_FILE 2>&1
echo "[$(date)] Build finished" >> $LOG_FILE
printf "\n\n" >> $LOG_FILE
