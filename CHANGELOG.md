# Changelog

All notable changes to `stackful/framework-support` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-28

### Added
- Initial release of `stackful/framework-support`.
- `FrameworkSupportServiceProvider` for automatic package discovery in Laravel 10.x, 11.x, and 12.x.
- `RuntimeManager` providing non-intrusive registration, validation, and multi-tier local caching.
- `EnvironmentResolver` for non-sensitive host and application context discovery.
- `ApplicationService` generic service facade for runtime operations.
- `RemoteClient` utilizing Laravel HTTP client with SSL enforcement, configurable timeouts, and sanitized logging.
- `RuntimeHelper` with UUID generation and sanitization utilities.
- Comprehensive PHPUnit unit and feature test suite with 100% mocked HTTP requests.
