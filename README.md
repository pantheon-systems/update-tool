# Updatinate

Fast and smart. Updatinate checks for available software updates and creates pull requests.

[![Travis CI](https://travis-ci.org/pantheon-systems/updatinate.svg?branch=master)](https://travis-ci.org/pantheon-systems/updatinate)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/pantheon-systems/updatinate/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/pantheon-systems/updatinate/?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/pantheon-systems/updatinate/badge.svg?branch=master)](https://coveralls.io/github/pantheon-systems/updatinate?branch=master) 
[![License](https://img.shields.io/badge/license-MIT-408677.svg)](LICENSE)

<table><tr width="25%"><td><img alt="Detinator" src="docs/images/roadrunner.png"/></td><td width="75%" valign="top">
The Updatinate tool is a lightweight collection of commands that know how to create 
pull requests from various data sources that inform us of the most recent available
version of certain software components on our platform. These commands are executed
periodically via `cron`, as managed by the 
[Updatinator](https://github.com/pantheon-systems/updatinator) tool.
</td></tr></table>

## Command List

The following commands are available:

- php:rpm:update: Check for new php releases in [php.net/distributions](http://php.net/distributions) and create a pull requests in the [pantheon-systems/rpmbuild-php](https://github.com/pantheon-systems/rpmbuild-php) project as needed.
- php:cookbook:update: After a php rpmbuild completes, this command will create a pull request in the [php cookbook](https://github.com/pantheon-cookbooks/php) to deploy the new RPMs.

## Authentication

There are two ways to provide authentication credentials when using the updatinate commands.

- Environment variable: Define `GITHUB_TOKEN` with the apporpriate personal access token.
- On-disk cache: See [updatinate.yml](updatinate.yml) for the location to store the personal access token. Use the `--as` option to select between different cache locations.

The authentication credentials you will need can be found in the [pantheon-upstream onelogin note](https://pantheon.onelogin.com/notes/58434). 

## Automation

The [pantheon-systems/updatinator](https://github.com/pantheon-systems/updatinator) project runs the automation processes in CircleCI 2.0 scripts.

## Local Development

Clone the GitHub repository and run `composer install` to get started.

### Running the tests

The test suite may be run locally by way of some simple composer scripts:

| Test             | Command
| ---------------- | ---
| Run all tests    | `composer test`
| PHPUnit tests    | `composer unit`
| PHP linter       | `composer lint`
| Code style       | `composer cs`     
| Fix style errors | `composer cbf`

### Releasing

To release a new version of the updatinate tool, run:

- `composer release`

This will release a stable version of whatever is indicated in the VERSION file. e.g. if VERSION contains `1.0.3-dev`, then version `1.0.3` will be tagged and released, and the VERSION file will be updated to `1.0.4-dev`. To release version `1.1.0` instead, manually edit the VERSION file to `1.1.0-dev` and then run `composer release`.

The updatinate.phar file will be uploaded to GitHub on every release. Rebuild [pantheon-systems/docker-updatinator](https://github.com/pantheon-systems/docker-updatinator) to deploy a new version of the tool to the automation processes.
