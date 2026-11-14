# Payment security (x402 multichain)

## Quote binding

- Priced creates persist the full 402 body under each accept key and optional `fp:{fingerprint}`.
- Retries must match stored amount and selected accept (`Earnfi_X402_Priced_Quote::validate_paid_retry`).
- Multi-accept quotes require explicit accept match — no blind `accepts[0]` on the server.

## Facilitator selection

- Derived from stored accept metadata (`extra.facilitator` / registry), not client-supplied facilitator URLs.

## Replay / duplicate jobs

- Payment attempt store tracks quote + method; settlement idempotency on funding intents where applicable.
- Replayed successful `PAYMENT-SIGNATURE` should return existing job / replay flag, not double-fund.

## Network filter

- `X-Earnfi-Accepts-Filter` only **filters** eligible accepts; it cannot enable a disabled rail.

## Secrets

- Never commit treasury keys, FastNEAR API keys, or wallet seeds. Use WordPress encrypted settings and env vars for demo scripts.
