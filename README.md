# Stackful Framework Support

[![Latest Version on Packagist](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://packagist.org/packages/stackful/framework-support)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-777bb4.svg)](https://php.net)
[![Laravel Compatibility](https://img.shields.io/badge/Laravel-10.x%20%7C%2011.x%20%7C%2012.x-red.svg)](https://laravel.com)
[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-black.svg)](LICENSE)

Production-ready private infrastructure and runtime support package for Stackful Laravel products, including InvoixPro and future suite applications.

---

## Table of Contents
1. [Overview](#overview)
2. [Key Architecture Principles](#key-architecture-principles)
3. [Compatibility](#compatibility)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Environment Variables](#environment-variables)
7. [Developer Usage](#developer-usage)
8. [Validation & Caching Workflow](#validation--caching-workflow)
9. [Server-Side Architecture (Zero-Credential Client)](#server-side-architecture-zero-credential-client)
10. [Local Development](#local-development)
11. [Running Tests](#running-tests)
12. [Release & Versioning](#release--versioning)

---

## Overview

`stackful/framework-support` provides a clean, neutral framework support foundation across Stackful applications. It eliminates brittle application-level middleware and ad-hoc diagnostics by providing standardized runtime management, environment resolution, and secure client-to-API communication.

---

## Key Architecture Principles

- **Neutral Naming**: Uses standard framework and runtime service semantics (`RuntimeManager`, `ApplicationService`, `RemoteClient`).
- **Zero Client Credentials**: No Firebase private keys, service accounts, or database passwords are ever bundled or transferred. All infrastructure operations are managed server-side at `api.stackful.dev`.
- **Intelligent Caching**: Avoids calling external endpoints on every request. Validations and registrations are cached locally using multi-tier storage with configurable TTLs.
- **Fail-Safe & Non-Destructive**: Gracefully handles network timeouts and remote outages without crashing user applications.
- **Strict Privacy**: Collects only standard environment telemetry (domain, Laravel version, PHP version) required for runtime service operation.

---

## Compatibility

- **PHP**: `^8.2` (PHP 8.2, 8.3, 8.4)
- **Laravel**: `10.x`, `11.x`, `12.x`

---

## Installation

### 1. Require the Package

```bash
composer require stackful/framework-support
```

The package supports **Laravel Auto-Discovery**. The `FrameworkSupportServiceProvider` is automatically registered.

### 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=framework-support-config
```

---

## Configuration

The published configuration file resides in `config/framework-support.php`:

```php
return [
    'enabled' => env('FRAMEWORK_SUPPORT_ENABLED', true),

    'endpoint' => env(
        'FRAMEWORK_SUPPORT_ENDPOINT',
        'https://api.stackful.dev'
    ),

    'product' => env(
        'FRAMEWORK_SUPPORT_PRODUCT'
    ),

    'key' => env(
        'FRAMEWORK_SUPPORT_KEY'
    ),

    'timeout' => (int) env('FRAMEWORK_SUPPORT_TIMEOUT', 10),

    'validation_cache' => (int) env('FRAMEWORK_SUPPORT_VALIDATION_CACHE', 86400),

    'registration_cache' => (int) env('FRAMEWORK_SUPPORT_REGISTRATION_CACHE', 604800),
];
```

---

## Environment Variables

Add these variables to your product's `.env` file:

```dotenv
FRAMEWORK_SUPPORT_ENABLED=true
FRAMEWORK_SUPPORT_ENDPOINT=https://api.stackful.dev
FRAMEWORK_SUPPORT_PRODUCT=invoixpro
FRAMEWORK_SUPPORT_KEY=your_product_runtime_key_here
FRAMEWORK_SUPPORT_TIMEOUT=10
```

---

## Developer Usage

### Using `RuntimeManager`

```php
use Stackful\FrameworkSupport\Runtime\RuntimeManager;

$runtime = app(RuntimeManager::class);

// Initialize installation identifier & register if needed
$status = $runtime->initialize();

// Check if registered
if ($runtime->registered()) {
    // Validate runtime state (uses cached validation result by default)
    $validation = $runtime->validate();
}

// Retrieve unique installation identifier
$uuid = $runtime->installationId();
```

### Using `ApplicationService`

```php
use Stackful\FrameworkSupport\Services\ApplicationService;

$appService = app(ApplicationService::class);

// Check if operational
if ($appService->isReady()) {
    // Execute authorized runtime operation
    $response = $appService->executeRuntimeOperation('fetch_manifest', [
        'region' => 'us-east-1',
    ]);
}
```

---

## Validation & Caching Workflow

```
Application Request
       │
       ▼
Is Validation Cached?
 ├── YES ──► Return Cached Result (Zero HTTP Calls)
 └── NO  ──► POST https://api.stackful.dev/v1/runtime/validate
                  │
                  ▼
             Cache Response (TTL: 86,400s / 24h)
```

---

## Server-Side Architecture (Zero-Credential Client)

```
┌───────────────────────────────┐
│ Customer Laravel Application  │
│ (InvoixPro / Stackful App)    │
└───────────────┬───────────────┘
                │
                │ HTTPS (Signed API Key)
                ▼
┌───────────────────────────────┐
│       api.stackful.dev        │
│  - Verifies Product & Domain  │
│  - Authorizes Installation    │
└───────────────┬───────────────┘
                │
                │ Server-Side Only
                ▼
┌───────────────────────────────┐
│  Firebase / Cloud Services    │
│  (Service Account Kept Here)  │
└───────────────────────────────┘
```

---

## Local Development

To link and test locally in a Laravel application:

In your Laravel `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../framework-support"
    }
]
```

Run:

```bash
composer require stackful/framework-support:@dev
```

---

## Running Tests

Execute PHPUnit test suite:

```bash
composer test
# or
./vendor/bin/phpunit
```

---

## Release & Versioning

This package follows [SemVer](https://semver.org/).
- `1.0.0` - Initial production release.
- `1.1.0` - Feature enhancements.
- `2.0.0` - Breaking changes.

---

## License

Proprietary. Copyright (c) Stackful. All rights reserved.
