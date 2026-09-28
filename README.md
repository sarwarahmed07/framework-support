# Stackful Framework Support

[![Latest Version on Packagist](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://packagist.org/packages/stackful/framework-support)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-777bb4.svg)](https://php.net)
[![Laravel Compatibility](https://img.shields.io/badge/Laravel-10.x%20%7C%2011.x%20%7C%2012.x-red.svg)](https://laravel.com)
[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-black.svg)](LICENSE)

Self-contained private infrastructure and runtime support package for Stackful Laravel products, including InvoixPro.

---

## Zero-Configuration Installation

This package is completely self-contained. The consuming Laravel application requires **zero configuration**, **no `.env` variables**, and **no published config files**.

### Installation

```bash
composer require stackful/framework-support
```

That's it! 

The package leverages Laravel Package Auto-Discovery. Upon installation, it automatically:
1. Registers the package runtime in the Laravel service container.
2. Identifies the host application context, domain, and environment automatically.
3. Initializes the cloud synchronization client in memory with AES-256-GCM authenticated encryption.
4. Performs registration and cached heartbeat checks silently in the background without affecting application performance.

---

## Features

- **Zero Consumer-Side Setup**: No `.env` keys, no published configuration files, no manual service provider registration.
- **Automatic Environment Discovery**: Automatically detects domain, application URL, Laravel version, PHP runtime, OS, and hostname.
- **Fail-Safe & Non-Blocking**: Network drops or temporary cloud issues fail gracefully without disrupting host application requests or throwing unhandled errors.
- **Encrypted In-Memory Secrets**: Encrypted with AES-256-GCM. No plaintext service accounts or private keys are ever stored on disk or exposed to the consuming application.

---

## Local Development Setup (Package Maintainers Only)

To configure or update the internal encrypted cloud payload locally before release:

```bash
php artisan framework-support:configure --file="/path/to/service-account.json"
```

---

## Running Tests

Execute PHPUnit test suite:

```bash
./vendor/bin/phpunit
```

---

## License

Proprietary. Copyright (c) Stackful. All rights reserved.
