# Security Policy

## Supported Versions

We provide security patches for the latest minor release line. Older lines
receive fixes only for Critical / High vulnerabilities at our discretion.

| Version | Supported          |
| ------- | ------------------ |
| 0.14.x  | ✅ Yes             |
| < 0.14  | ❌ No              |

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
- **Rate / size caps** — `chunky.metadata.max_keys` and
  `chunky.max_files_per_batch` bound user-supplied input.

If you believe one of these surfaces is bypassable, that's a security
issue — report privately as above.
