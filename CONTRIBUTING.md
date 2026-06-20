# Contributing

Thanks for taking the time to improve SymPress Nginx Cache.

## Local Setup

```bash
composer install
composer test
composer static-analysis
composer cs
```

The package uses PHP 8.5, Symfony components, WordPress APIs, PHPUnit, PHPStan
and PHPCS through the shared SymPress QA tooling.

## Pull Requests

- Keep pull requests focused on one behavior or documentation change.
- Add or update tests for purge, path, URL policy or configuration changes.
- Run the available checks before opening a pull request.
- Use Conventional Commits for commit messages, for example
  `feat(nginx-cache): add cache layer probe`.

## Coding Guidelines

- Keep filesystem operations behind dedicated services.
- Validate cache paths and URLs before destructive purge actions.
- Prefer explicit value objects for purge requests and cache status data.
- Keep WordPress hooks thin and route behavior through services.
