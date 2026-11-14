# Public repository (Strategy B+)

NEAR hackathon screening checks that the project **runs** and matches the README. EarnFi’s submission is **WordPress-free**: SDK, MCP, contracts, examples, and live API tests — not CMS plugin source.

## Generate the submission tree

From the private monorepo:

```bash
node scripts/hackathon/export-public-repo.mjs --out ../earnfi-near-hackathon --init-git
```

Optional: `--force` to replace an existing output directory.

## Verify before push

```bash
node scripts/hackathon/verify-no-wordpress.mjs --dir ../earnfi-near-hackathon
cd ../earnfi-near-hackathon
npm install
npm run build:sdk
npm test
npm run test:live
node examples/hackathon/02-near-quote.mjs
```

## Excluded by design

- All `*.php`, `growl-fun.php`, `includes/` server modules
- Admin, database dumps, secrets

## Included

See `scripts/hackathon/export-manifest.json` — packages, docs, contracts, examples, hackathon HTML reference.

Push to GitHub (e.g. `github.com/earnfidotfun/earnfi`) and paste the URL in the hackathon form with the X demo post.