# Update Tools

Fast and smart. Update Tool checks for available software updates and creates pull requests.

[![Test](https://github.com/pantheon-systems/update-tool/actions/workflows/test.yml/badge.svg)](https://github.com/pantheon-systems/update-tool/actions/workflows/test.yml)
[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)
[![License](https://img.shields.io/badge/license-MIT-408677.svg)](LICENSE)

The Update Tool is a lightweight collection of commands that know how to create pull requests from various data sources that inform us of the most recent available version of certain software components on our platform. These commands are executed periodically via GitHub Actions cron schedules, as managed by the [Updatinator](https://github.com/pantheon-systems/updatinator) tool.

## Command List

The following commands are available:

- project:upstream:update: Given a repository that is a fork of an upstream repository, apply the changes from a newer tag.

## Authentication

There are two ways to provide authentication credentials when using the Update Tool commands.

- Environment variable: Define `GITHUB_TOKEN` with the appropriate token.
- On-disk cache: See [update-tool.yml](update-tool.yml) for the location to store the token. Use the `--as` option to select between different cache locations.

In production, this tool uses credentials provisioned by the [pantheon-systems/updatinator](https://github.com/pantheon-systems/updatinator) project via GitHub App tokens stored in Vault.

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

To release a new version of the Update Tool, create a new tag at the appropriate version. This triggers the test workflow; on success, a dispatch event triggers the publish workflow to create a GitHub release and upload the `update-tool.phar` artifact.

Rebuild [pantheon-systems/docker-updatinator](https://github.com/pantheon-systems/docker-updatinator) to deploy a new version of the tool to the automation processes.
