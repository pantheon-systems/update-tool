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

## Usage

### Local

Clone and install dependencies, then invoke the `update-tool` binary directly:

```sh
git clone https://github.com/pantheon-systems/update-tool.git
cd update-tool
composer install

# Provide a token (or use the on-disk cache described above).
export GITHUB_TOKEN=<your-token>

# List the available commands.
./update-tool list

# Apply a newer upstream tag to a fork.
./update-tool project:upstream:update <project>
```

### CI (GitHub Actions)

Consumers check out this repository at a pinned release tag and run it from source. There is no published binary to download.

```yaml
jobs:
  update:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout update-tool
        uses: actions/checkout@v4
        with:
          repository: pantheon-systems/update-tool
          ref: 0.8.4 # pin to a released tag; bump to adopt a new release
          path: update-tool
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - name: Install update-tool
        run: |
          cd update-tool
          composer install --no-dev --optimize-autoloader
      - name: Run update-tool
        env:
          GITHUB_TOKEN: ${{ secrets.YOUR_APP_TOKEN }}
        run: ./update-tool/update-tool project:upstream:update <project>
```

> Pin `ref:` to a bare version tag (e.g. `0.8.4`), not a `v`-prefixed one. See [Releasing](#releasing) for the tag scheme.

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

Releases are cut automatically by [pantheon-systems/action-autotag](https://github.com/pantheon-systems/action-autotag) when a pull request is merged to `master`. There is no manual tagging step.

The version bump is derived from the [Conventional Commits](https://www.conventionalcommits.org/) in the merge:

| Commit contains                                  | Bump    | Example (from `0.8.4`) |
| ------------------------------------------------ | ------- | ---------------------- |
| `feat:`                                          | minor   | `0.9.0`                |
| `fix:`, `chore:`, `ci:`, or anything else        | patch   | `0.8.5`                |
| `!` after the type (e.g. `feat!:`) or a `BREAKING CHANGE:` footer | major | `1.0.0`                |

The `release` workflow then creates the git tag and a GitHub release with auto-generated notes. Tags use the bare, non-`v`-prefixed scheme (e.g. `0.8.5`) because downstream consumers pin to bare version tags.

Consumers ([updatinator](https://github.com/pantheon-systems/updatinator), [wordpress-internal](https://github.com/pantheon-systems/wordpress-internal), [drops-7](https://github.com/pantheon-systems/drops-7)) check out this repository at a pinned tag and run it from source (`composer install`), so no build artifact is published. To adopt a new release in a consumer, bump the `ref:` it checks out.

The `--version` string is read from the tag Composer records at install time (`Composer\InstalledVersions`), so it always matches the checked-out release — a tag checkout reports e.g. `0.8.5`, a branch checkout reports `dev-master`. There is no `VERSION` file to maintain.
