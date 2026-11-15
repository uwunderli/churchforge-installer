#!/bin/sh
set -eu
umask 077
php /opt/cf-setup/bin/startcode.php
exec php -d upload_max_filesize=6M -d post_max_size=14M -d file_uploads=1 -S 0.0.0.0:8080 -t /opt/cf-setup/public /opt/cf-setup/public/index.php
