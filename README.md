# PlugNotas Modernization — COBOL + PHP + Webhooks

Experimental modernization of the existing Python `plug_notas` project. **This module does not issue invoices.** It prepares auditable sale allocations for later fiscal integration. The original Python script remains available in the repository but must not be used with credentials embedded in source.

## Why COBOL and PHP?

- **COBOL**: deterministic, fixed-precision financial allocation (70% services / 30% products), calculated in integer cents, without floating-point drift.
- **PHP 8.1+**: CSV ingestion, validation, structured JSONL output, and opt-in HTTPS webhook delivery, signed using HMAC-SHA256.
- **Integration contract**: an event `sale.allocated` with `customer` and `amountsCents`, leaving tax invoice payload construction to a separately reviewed fiscal module.

## Setup

```bash
mkdir -p bin
cobc -x -free cobol/split.cob -o bin/split-sale
php tests/test.php
php src/process.php --csv=clients.example.csv
```

On Windows compile to `bin/split-sale.exe` and pass `--cobol=bin/split-sale.exe`. Requires PHP CLI, GnuCOBOL; webhook option requires PHP cURL.

## Data format

CSV must have columns `Nome`, `CPF/CNPJ`, `Valor Total da Venda`. Monetary input uses a period and exactly two decimals; CPF/CNPJ must contain 11 or 14 digits (format check only, **not** checksum validation). No actual taxpayer data is included in the example. Output is JSON Lines, integer cents. Keep real customer data outside source control.

## Optional webhook

By default, **no network requests are made**. To send real events:

```bash
export WEBHOOK_URL='https://your-service.example/webhook'
export WEBHOOK_SECRET='your-long-secret-from-a-vault'
php src/process.php --csv=clients.example.csv --send-webhook
```

Consumer verification: compute `HMAC_SHA256(timestamp + '.' + exact_raw_body, shared_secret)` and compare (constant-time) with `X-Event-Signature`. Reject stale timestamps. The receiver must implement idempotency by `reference` (the demo reference depends on CSV row order). HTTP failures terminate processing; production workflows should add a durable outbox, retries, deduplication keys and secret redaction. Webhook URLs must be trusted, preconfigured destinations.

## Security and migration notes

**Urgent:** the original `plug-notas.py` in the root of this repository contains a committed API token. Revoke/rotate it with the provider and remove it from source and Git history before any production use. Never put fiscal API tokens, real customer identifiers, signing secrets, or webhook URLs in tracked files. The sample is synthetic. This module does not validate tax rules, sign tax documents, or guarantee compliance. Avoid automatically reproducing the original 70/30 fiscal split without review by a qualified accountant.

## Roadmap

- Provider-specific NFe/NFSe payloads and sandbox contract tests after accounting review.
- Transactional outbox, retries with exponential backoff, durable deduplication, monitoring and error audit.
- CPF/CNPJ checksum validation and schema-based input validation.
