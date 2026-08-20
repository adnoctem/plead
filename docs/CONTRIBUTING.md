# Ad Noctem Collective - `plead` Repository Contributing Guidelines

Contributions are welcome via GitHub's Pull Requests. This document outlines the process to help get your contribution accepted.

## ⚒️ Building

The project is a plain [Symfony Console](https://symfony.com/doc/current/components/console.html) application on PHP 8.4+.
There is no build step beyond installing dependencies:

```bash
composer install
```

The executable entrypoint is [`bin/plead`](../bin/plead). Run it from the repository root:

```bash
php bin/plead list
```

PHP 8.4 or newer is required (the local development environment uses PHP 8.5). The `ext-pdo_sqlite` extension is
required for the local data store.

## 🔧 Development Workflow

### Running Tests

The test suite uses [PHPUnit](https://phpunit.de/):

```bash
vendor/bin/phpunit
```

Run a single test class or method:

```bash
vendor/bin/phpunit --filter AutoresponderCommandsTest
vendor/bin/phpunit --filter testSetUpdatesDescription
```

Tests live in [`tests/`](../tests), mirroring the `src/` layout. New behavior is expected to ship with tests —
the reconciler (the safety-critical convergence logic) and the config merge precedence are covered explicitly.

### Syntax Checking

There is no separate linter for application code; PHP's built-in syntax check covers the basics:

```bash
find src tests bin -name '*.php' -print0 | xargs -0 -n1 php -l
php -l bin/plead
```

### Pre-Commit Hooks

The repository ships a pre-configured [`.pre-commit-config.yaml`](../.pre-commit-config.yaml) that runs YAML,
Markdown, and workflow-action checks before each commit. After installing [pre-commit](https://pre-commit.com/),
activate the hooks from the repository root:

```bash
pre-commit install
```

## 🗂️ Repository Layout

| Path                  | Purpose                                                                                                                                 |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `bin/plead`           | CLI entrypoint                                                                                                                          |
| `src/`                | Application code (PSR-4, `App\` namespace)                                                                                              |
| `src/Command/`        | Symfony Console commands (`mail:group:*`, `mail:address:*`, `mail:autoresponder:*`, `domain:*`, `config:*`, `db:*`)                     |
| `src/Command/Mail/`   | `AbstractMailCommand` (shared `--local` option), `AbstractWatchCommand` (spinner loop)                                                  |
| `src/Reconciler/`     | Convergence logic shared by one-shot commands and watchers                                                                              |
| `src/Gateway/`        | Thin wrapper over the Plesk XML-RPC client (the only code that talks to Plesk)                                                          |
| `src/Repository/`     | SQLite repositories (desired state + audit trail)                                                                                       |
| `src/Util/`           | `Spinner`, `InteractiveProcessLauncher`, `DateNormalizer`                                                                               |
| `src/Logging/`        | Monolog formatter                                                                                                                       |
| `src/Config/`         | Config discovery/merge (YAML/JSON), path providers per OS                                                                               |
| `config/`             | SQLite schema                                                                                                                           |
| `templates/`          | Twig templates (auto-reply message rendering, autoescape off)                                                                           |
| `tests/`              | PHPUnit tests (mirrors `src/`)                                                                                                          |
| `docs/`               | `AGENTS.md` (agent instructions, symlinked to repo root), `plesk-xml-api-notes.md`, `CONTRIBUTING.md`, `ACKNOWLEDGEMENTS.md`, `TODO.md` |
| `secrets/`            | **Gitignored.** Project-internal planning documents. Never commit anything from this directory.                                         |

## ℹ️ Commit Message Format

This specification is inspired by and supersedes the **AngularJS commit message format**.

We have very precise rules over how our Git commit messages must be formatted.
This format leads to **easier to read commit history**.

Each commit message consists of a **header**, a **body**, and a **footer**.

```text
<header>
<BLANK LINE>
<body>
<BLANK LINE>
<footer>
```

The `header` is mandatory and must conform to the [Commit Message Header](#commit-header) format.

The `body` is mandatory for all commits except for those of type "docs".
When the body is present it must be at least 20 characters long and must conform to
the [Commit Message Body](#commit-body) format.

The `footer` is optional. The [Commit Message Footer](#commit-footer) format describes what the footer is used for and
the structure it must have.

### <a name="commit-header"></a>Commit Message Header

```text
<type>(<scope>): <short summary>
  │       │             │
  │       │             └─⫸ Summary in present tense. Not capitalized. No period at the end.
  │       │
  │       └─⫸ Commit Scope: src|templates|config|docs
  │
  └─⫸ Commit Type: build|ci|docs|feat|fix|perf|refactor|test|chore
```

The `<type>` and `<summary>` fields are mandatory, the `(<scope>)` field is optional.

#### Type

Must be one of the following:

- **feat**: New features
- **fix**: Bugfixes
- **docs**: Documentation changes
- **refactor**: Code changes which neither add features nor fix bugs
- **test**: Adding tests or improving upon existing tests
- **chore**: Miscellaneous maintenance tasks which can generally be ignored
- **build**: Changes or improvements to the build tool or to the project's dependencies
- **ci**: Changes to CI configuration files and scripts

#### Scopes

The following is the list of supported scopes:

- `src` — Application code under `src/` and the `bin/plead` entrypoint
- `templates` — Changes to Twig templates (`templates/`)
- `config` — Changes to configuration files (`.php-cs-fixer.dist.php`, `.pre-commit-config.yaml`, `.releaserc`, `config/`, etc.)
- `docs` — Documentation changes (`README.md`, `docs/`)

#### Summary

Use the summary field to provide a succinct description of the change:

- use the imperative, present tense: "change" not "changed" nor "changes"
- don't capitalize the first letter
- no dot (.) at the end

#### <a name="commit-body"></a>Commit Message Body

Just as in the summary, use the imperative, present tense: "fix" not "fixed" nor "fixes".

Explain the motivation for the change in the commit message body. This commit message should explain _why_ you are
making the change.
You can include a comparison of the previous behavior with the new behavior in order to illustrate the impact of the
change.

#### <a name="commit-footer"></a>Commit Message Footer

The footer can contain information about breaking changes and deprecations and is also the place to reference GitHub
issues, Jira tickets, and other PRs that this commit closes or is related to.
For example:

```text
BREAKING CHANGE: <breaking change summary>
<BLANK LINE>
<breaking change description + migration instructions>
<BLANK LINE>
<BLANK LINE>
Fixes #<issue number>
```

or

```text
DEPRECATED: <what is deprecated>
<BLANK LINE>
<deprecation description + recommended update path>
<BLANK LINE>
<BLANK LINE>
Closes #<pr number>
```

Breaking Change section should start with the phrase "BREAKING CHANGE: " followed by a summary of the breaking change, a
blank line, and a detailed description of the breaking change that also includes migration instructions.

Similarly, a Deprecation section should start with "DEPRECATED: " followed by a short description of what is deprecated,
a blank line, and a detailed description of the deprecation that also mentions the recommended update path.

#### Revert commits

If the commit reverts a previous commit, it should begin with `revert:`, followed by the header of the reverted commit.

The content of the commit message body should contain:

- information about the SHA of the commit being reverted in the following format: `This reverts commit <SHA>`,
- a clear description of the reason for reverting the commit message.

## ✅ How to Contribute

1. Fork this repository, develop, and test your changes
2. Run `composer install` and `vendor/bin/phpunit` to ensure your changes pass all tests
3. Add your GitHub username to the [`AUTHORS`](../.github/AUTHORS) and [`CODEOWNERS`](../.github/CODEOWNERS) files
4. Submit a pull request

_**NOTE**_: In order to make testing and merging of PRs easier, please submit changes to unrelated areas of the
repository in separate PRs.

### Technical Requirements

- Must target PHP 8.4 or higher
- All new source files must declare `declare(strict_types=1);`
- Autoloading follows PSR-4 (`App\` maps to `src/`, `App\Tests\` maps to `tests/`)
- New behavior must be covered by PHPUnit tests
- Mutating commands must route through the shared reconcilers and respect `--dry-run`
- Live list paths must use the gateway's bulk methods (one batched HTTP POST, never N+1 round trips)
- New gateway mutation packets must be validated against the live server before shipping — see the workflow in [`docs/AGENTS.md`](AGENTS.md) and the empirically-verified shapes in [`docs/plesk-xml-api-notes.md`](plesk-xml-api-notes.md)
- Never commit credentials, secrets, or anything from `secrets/` (gitignored)
- Pass `pre-commit` hooks with no findings

### Versioning

Releases are managed by [semantic-release](https://semantic-release.org/) using the
[Conventional Commits](https://www.conventionalcommits.org/) preset (see [`.releaserc`](../.releaserc)); versions are
derived from commit messages on the `main` (and `next`) branches. No manual version bumps are required.

Breaking changes must be marked with a `BREAKING CHANGE:` footer in the commit message, which triggers a MAJOR release.
New features trigger a MINOR release, and fixes or documentation changes trigger a PATCH release.
