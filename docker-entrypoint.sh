#!/bin/sh
set -e
php bin/install.php
exec "$@"
