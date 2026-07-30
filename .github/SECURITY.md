# Security Policy

## Supported Versions

We provide security patches for the latest minor release line. Older lines
receive fixes only for Critical / High vulnerabilities at our discretion.

| Version | Supported          |
| ------- | ------------------ |
| 0.17.x  | ✅ Yes             |
| < 0.17  | ❌ No              |

## Reporting a Vulnerability

If you discover a security vulnerability in `netipar/laravel-chunky`, please
report it privately. **Do not open a public GitHub issue.**

Two channels:

1. **GitHub Private Vulnerability Reporting** (preferred): use the "Report a
   vulnerability" button at <https://github.com/NETipar/laravel-chunky/security/advisories/new>.
2. **Email**: send a description to `dev@netipar.hu` with:
   - A clear description of the issue and its impact
   - Steps to reproduce
   - The affected version(s)
   - Any proof-of-concept code (if applicable)

We will acknowledge your report within 72 hours and provide an estimated
timeline for a fix. Once the issue is resolved, we publish a GitHub
Security Advisory crediting the reporter (unless you request otherwise).

## Disclosure Policy

We follow coordinated disclosure: we publish the advisory only after a
patched release is available on Packagist and npm.

## Hardening Surface

The package treats these as security-relevant boundaries:

- **Path traversal** — `file_name` is regex-validated and the assembler
  applies `basename()` defense-in-depth.
- **IDOR** — every upload/batch HTTP route, the broadcast channels, and
  the Livewire component delegate to the bound `Authorizer` interface.
- **Mass assignment** — Eloquent models use explicit `$fillable`.
- **Broadcast payload sanitisation** — `disk` / `finalPath` are stripped
  by default; opt-in via `chunky.broadcasting.expose_internal_paths`.
- **Idempotency** — chunk POSTs cache responses by `(uploadId, chunkIndex,
  Idempotency-Key|checksum)` to prevent duplicate side-effects on retry.
- **Rate / size caps** — `chunky.metadata.max_keys`,
  `chunky.max_files_per_batch`, `chunky.max_chunks_per_upload` and the
  per-route `throttle:chunky` rate limiter bound user-supplied input.
- **Lock-driver compatibility guard** — boot-time refusal to run with
  `lock_driver = "cache"` against `array` / `file` cache stores. The
  `FilesystemTracker` and batch-counter critical sections throw rather
  than silently fall through when no lock can be acquired.
- **Cache-key namespacing** — every lock / idempotency / counter key is
  prefixed via `chunky.cache.prefix` (default `chunky:v1:`), so future
  payload-shape changes can invalidate cached entries cleanly without
  cooperating cache backends.

If you believe one of these surfaces is bypassable, that's a security
issue — report privately as above.
