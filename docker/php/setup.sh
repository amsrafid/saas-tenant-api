#!/bin/sh
set -e

[ -f .env ] || cp .env.example .env

composer install --no-interaction --prefer-dist

if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate
fi

# Seed only a database that has never been migrated, so a restart never duplicates demo data.
if php artisan migrate:status > /dev/null 2>&1; then
    php artisan migrate --force
else
    php artisan migrate --force --seed
fi

cat <<INFO

  API       http://localhost:${APP_PORT}/api/v1
  Swagger   http://localhost:${APP_PORT}/api/documentation

  Demo credentials (password: password)
    Platform admin   admin@platform.test
    Acme owner       owner@acme.test
    Globex owner     owner@globex.test

INFO
