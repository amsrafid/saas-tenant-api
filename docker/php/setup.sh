#!/bin/sh
set -e

[ -f .env ] || cp .env.example .env

composer install --no-interaction --prefer-dist

if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate
fi

php artisan migrate --force --seed

cat <<INFO

  API       http://localhost:${APP_PORT}/api/v1
  Swagger   http://localhost:${APP_PORT}/api/documentation

  Demo credentials (password: password)
    Platform admin   admin@platform.test
    Acme owner       owner@acme.test
    Acme admin       admin@acme.test
    Acme member      member1@acme.test
    Acme member      member2@acme.test
    Globex owner     owner@globex.test
    Globex admin     admin@globex.test
    Globex member    member@globex.test

INFO
