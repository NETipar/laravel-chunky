# Laravel Chunky

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `netipar/laravel-chunky`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## Chunky Architecture

- The PHP source follows a ports-and-adapters layout: `src/Ports` holds the contracts (`UploadRepository`, `BatchRepository`, `ChunkStore`, `LockProvider`, `Clock`), `src/Adapters` the database/filesystem/lock/storage implementations, `src/Domain` the immutable records and status enums, and `src/Services` the upload/batch/assembly orchestration.
- HTTP endpoints live in `src/Http` (invokable controllers + FormRequests, error envelope via `ErrorCode`), events in `src/Events` (broadcast-capable via `BroadcastsChunkyEvent`), console commands in `src/Console`.
- Config key and publish tags use the `chunky` slug (`config/chunky.php`, `chunky-config`, `chunky-migrations`), not the `laravel-chunky` composer slug.
- Repository implementations must pass the shared contract suites in `tests/Contracts`; wire new implementations into `tests/Support/repositories.php`.
- The frontend clients are a pnpm workspace under `packages/` (`core`, `vue3`, `react`, `alpine`); the Livewire component is PHP-side (`src/Livewire`, optional dependency). Frontend validation: `pnpm typecheck && pnpm test && pnpm -r run build`.
- Consumer documentation is bilingual: `docs/en/` and `docs/hu/` each hold `protocol.md` and `configuration.md` — keep the two languages in sync when endpoint, config, or event behavior changes. `docs/openapi.yaml` is the language-neutral spec; `docs/*.md` at the root (`v1-*-plan.md`, `behavior-inventory.md`) are internal working documents.
