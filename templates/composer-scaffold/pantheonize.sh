#!/bin/bash

# Patch file must be out of working directory to avoid getting it overwritten by sed.
# Run this as PATCH_FILE=/path/to/pantheonize.patch ./pantheonize.sh

set -e

LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/Drupal\\/Pantheon\\/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/drupal-scaffold/composer-scaffold/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/drupal scaffold/composer scaffold/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/drupal:scaffold/composer:scaffold/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/DRUPAL_SCAFFOLD/COMPOSER_SCAFFOLD/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/Drupal scaffold/Composer scaffold/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/drupal-composer\/composer-scaffold/drupal-composer\/drupal-scaffold/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/drupal\/core-composer-scaffold/pantheon-systems\/composer-scaffold/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/"homepage": "https:\/\/www.drupal.org\/project\/drupal"/"homepage": "https:\/\/github.com\/pantheon-systems\/composer-scaffold"/g' {} +
LC_ALL=C find . -maxdepth 2 -type f -name '*' -exec sed -i .bak 's/Drupal Composer Scaffold/Composer Scaffold/g' {} +

git apply < $PATCH_FILE

git clean -f **/*.bak
git clean -f *.bak