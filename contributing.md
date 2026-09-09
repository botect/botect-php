# Contributing to Botect PHP SDK

Thank you for contributing! Bug reports, documentation improvements, tests, and
code contributions are welcome.

## Issues and proposals

Search [existing issues](https://github.com/botect/botect-php/issues) before opening
a new one. For bugs, include the SDK, PHP, and Laravel versions (if applicable),
steps to reproduce, and the expected and actual behavior. Remove credentials and
personal data from examples and logs.

For substantial features or breaking changes, open an issue first so we can agree
on the approach before you begin implementation.

## Development setup

Use PHP 8.4 or later and Composer 2 for the default development setup. Install the
PHP extensions required by Composer and the tests, including cURL, DOM, XML,
mbstring, SQLite3, and PDO SQLite.

1. Fork the repository on GitHub and clone your fork.
2. Add the upstream repository and create your branch from `develop`:

   ```bash
   git remote add upstream https://github.com/botect/botect-php.git
   git fetch upstream
   git switch -c feature/your-change upstream/develop
   composer install
   ```

The SDK supports plain PHP 8.3+, Laravel 12 on PHP 8.3+, and Laravel 13 on PHP 8.4+.
Keep changes compatible with these supported environments. The GitHub Actions
matrix checks PHP 8.3–8.5 and Laravel 12–13 where compatible.

## Making changes

- Keep each pull request focused on one change and follow the existing code style.
- Keep the plain PHP core independent of Laravel; place Laravel integration in `src/Laravel`.
- Add or update tests for behavior changes and bug fixes.
- Update the README and the `Unreleased` section of the changelog when user-facing behavior changes.
- Avoid unrelated formatting changes or dependency upgrades.

Before submitting, run:

```bash
composer lint
composer validate --strict
composer check
```

`composer lint` formats PHP files. `composer check` runs the test suite, the plain
PHP integration check, and the PHP-FPM smoke test. The FPM check skips when a
compatible binary is unavailable; set `PHP_FPM_BINARY` to its path when needed.
Tests use local fixtures and do not require production Botect credentials.

## Pull requests

Push your branch to your fork and open a pull request against **`develop`** in
`botect/botect-php`. Explain the problem, what changed, and how you tested it.
Link related issues and call out any compatibility impact.

Maintainers review contributions and merge them after the relevant checks pass.
Respond to review feedback by pushing further commits to the same branch. You do
not need write access to this repository or Gitflow installed to contribute.
Please keep discussions respectful and constructive.

## Branches and releases

Maintainers use Gitflow:

| Branch | Purpose |
| --- | --- |
| `main` | Production releases |
| `develop` | Changes for the next release; normal pull request target |
| `feature/*` | Individual features |
| `bugfix/*` | Fixes for development work |
| `release/*` | Final testing, changelog, and release preparation |
| `hotfix/*` | Urgent fixes based on released code |

Gitflow configuration is local to each clone. Maintainers initializing a new clone
should run `git flow init`, select `main` for production and `develop` for
development, and keep the standard branch prefixes. Run `git flow config list`
to verify the configuration.

Maintainers handle release branches, version tags, and publication. Release and
hotfix changes are merged into both `main` and `develop`. Package versions follow
[Semantic Versioning](https://semver.org/); published tags are never rewritten.
Contributors do not need to bump a version or create a release tag.

## License

By submitting a contribution, you agree that it is provided under the project's
[MIT license](license.md).
