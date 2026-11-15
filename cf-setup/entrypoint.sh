#!/bin/sh
set -eu
umask 077
php /opt/cf-setup/bin/startcode.php
exec php -S 0.0.0.0:8080 -t /opt/cf-setup/public /opt/cf-setup/public/index.php
