# Update Tools

Fast and smart. Update Tool checks for available software updates and creates pull requests.

[![CircleCI](https://dl.circleci.com/status-badge/img/gh/pantheon-systems/update-tool/tree/master.svg?style=svg)](https://dl.circleci.com/status-badge/redirect/gh/pantheon-systems/update-tool/tree/master)
[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)
[![License](https://img.shields.io/badge/license-MIT-408677.svg)](LICENSE)

<table><tr width="25%"><td><img alt="Detinator" src="docs/images/roadrunner.png"/></td><td width="75%" valign="top">
The Update Tool is a lightweight collection of commands that know how to create pull requests from various data sources that inform us of the most recent available version of certain software components on our platform. These commands are executed periodically via `cron`, as managed by the <a href="https://github.com/pantheon-systems/updatinator">Updatinator</a> tool.
</td></tr></table>

## Command List

The following commands are available:

- project:upstream:update: Given a repository that is a fork of an upstream repository, apply the changes from a newer tag.

## Authentication

There are two ways to provide authentication credentials when using the Update Tool commands.

- Environment variable: Define `GITHUB_TOKEN` with the apporpriate personal access token.
- On-disk cache: See [update-tool.yml](update-tool.yml) for the location to store the personal access token. Use the `--as` option to select between different cache locations.

The authentication credentials you will need can be found in the production Vault: `secret/github/user__pantheon-upstream`

### Rotating Credentials

*Production:* In production, this tool uses the credentials defined in the [pantheon-systems/updatinator](https://github.com/pantheon-systems/updatinator) project.

*Testing:* CircleCI needs a GitHub token for a service account that has access to the projects in the [test-configurations.yml](tests/fixtures/home/test-configuration.yml) fixtures file. Currently, the github user pantheon-ci-bot is being used. Access it via:

```
pvault production read secret/github/access-tokens/pantheon-ci-bot
```

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

To release a new version of the Update Tool, run:

- `composer release`

This will release a stable version of whatever is indicated in the VERSION file. e.g. if VERSION contains `1.0.3-dev`, then version `1.0.3` will be tagged and released, and the VERSION file will be updated to `1.0.4-dev`. To release version `1.1.0` instead, manually edit the VERSION file to `1.1.0-dev` and then run `composer release`.

The update-tool.phar file will be uploaded to GitHub on every release. Rebuild [pantheon-systems/docker-updatinator](https://github.com/pantheon-systems/docker-updatinator) to deploy a new version of the tool to the automation processes.
