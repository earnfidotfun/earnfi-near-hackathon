#!/usr/bin/env node
/**
 * Copy a curated, sanitized EarnFi tree for public NEAR hackathon submission.
 * Excludes WordPress core, secrets, admin-only surfaces, and SQL dumps.
 *
 * Usage:
 *   node scripts/hackathon/export-public-repo.mjs --out ../earnfi-near-hackathon
 *   node scripts/hackathon/export-public-repo.mjs --out ../earnfi-near-hackathon --init-git
 *   node scripts/hackathon/export-public-repo.mjs --out ../earnfi-near-hackathon --dry-run
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../..');
function parseArgs(argv) {
    const out = {
        out: '',
        profile: 'platform',
        initGit: false,
        gitOnly: false,
        dryRun: false,
        force: false,
        help: false,
    };
    for (let i = 2; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--out') out.out = argv[++i] ?? '';
        else if (a === '--profile') out.profile = argv[++i] ?? 'platform';
        else if (a === '--init-git') out.initGit = true;
        else if (a === '--git-only') out.gitOnly = true;
        else if (a === '--dry-run') out.dryRun = true;
        else if (a === '--force') out.force = true;
        else if (a === '-h' || a === '--help') out.help = true;
    }
    return out;
}

function manifestPathForProfile(profile) {
    const p = profile === 'bplus' || profile === 'b+' ? 'bplus' : 'platform';
    const name = p === 'bplus' ? 'export-manifest.json' : 'export-manifest-platform.json';
    return path.join(__dirname, name);
}

function globToRegExp(glob) {
    let g = glob.replace(/\\/g, '/');
    if (g.startsWith('**/')) g = g.slice(3);
    const parts = g.split('**');
    let re = '^';
    for (let i = 0; i < parts.length; i++) {
        const seg = parts[i];
        if (seg) {
            re += seg
                .split('*')
                .map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
                .join('[^/]*');
        }
        if (i < parts.length - 1) re += '.*';
    }
    re += '$';
    return new RegExp(re, 'i');
}

function normalizeRel(p) {
    return p.split(path.sep).join('/');
}

function shouldExclude(rel, manifest) {
    const n = normalizeRel(rel);
    for (const f of manifest.excludeFiles ?? []) {
        if (n === normalizeRel(f) || n.endsWith('/' + normalizeRel(f))) return true;
    }
    for (const g of manifest.excludeGlobs ?? []) {
        const rg = globToRegExp(g);
        if (rg.test(n)) return true;
    }
    return false;
}

function matchesInclude(rel, manifest) {
    const n = normalizeRel(rel);
    for (const f of manifest.includeFiles ?? []) {
        if (n === normalizeRel(f)) return true;
    }
    for (const g of manifest.includeGlobs ?? []) {
        const rg = globToRegExp(g);
        if (rg.test(n)) return true;
    }
    return false;
}

function walkFiles(dir, base = dir) {
    const out = [];
    if (!fs.existsSync(dir)) return out;
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, ent.name);
        if (ent.isDirectory()) {
            if (ent.name === 'node_modules' || ent.name === '.git') continue;
            out.push(...walkFiles(full, base));
        } else if (ent.isFile()) {
            out.push(path.relative(base, full));
        }
    }
    return out;
}

function collectIncludedFiles(manifest) {
    const set = new Set();
    for (const f of manifest.includeFiles ?? []) {
        const abs = path.join(REPO_ROOT, f);
        if (fs.existsSync(abs) && fs.statSync(abs).isFile()) {
            set.add(normalizeRel(f));
        }
    }
    const roots = new Set();
    for (const g of manifest.includeGlobs ?? []) {
        const clean = g.replace(/\/\*\*$/, '').replace(/\*\*$/, '');
        roots.add(clean.split('/')[0] === '**' ? REPO_ROOT : path.join(REPO_ROOT, clean.split('/')[0]));
    }
    for (const g of manifest.includeGlobs ?? []) {
        const prefix = g.replace(/\*\*.*$/, '').replace(/\/$/, '');
        const start = prefix ? path.join(REPO_ROOT, prefix) : REPO_ROOT;
        if (!fs.existsSync(start)) continue;
        const stat = fs.statSync(start);
        if (stat.isFile()) {
            set.add(normalizeRel(path.relative(REPO_ROOT, start)));
            continue;
        }
        const files = walkFiles(start, REPO_ROOT);
        for (const rel of files) {
            if (matchesInclude(rel, manifest)) set.add(normalizeRel(rel));
        }
    }
    return [...set].filter((rel) => !shouldExclude(rel, manifest)).sort();
}

function sanitizeContent(text, manifest) {
    let out = text;
    for (const rule of manifest.sanitizePatterns ?? []) {
        out = out.replace(new RegExp(rule.regex, 'g'), rule.replace);
    }
    return out;
}

function isTextFile(rel) {
    return /\.(php|js|mjs|cjs|ts|tsx|json|md|html|css|yml|yaml|sh|txt|xml|svg)$/i.test(rel);
}

function writeExportReadme(outDir, manifest) {
    const body = `# ${manifest.repoName}

**EarnFi — The Work & Money Layer for AI Agents**  
NEAR Hackathon 2026 submission repository (Strategy B+).

Autonomous agents can **discover capabilities**, **commission human and specialist work**, **fund execution** with programmable payments, and **retrieve evidence and receipts**. Production Agent API, Work API, and MCP run at [app.earnfi.fun](https://app.earnfi.fun).

This repo contains **agent SDK, MCP, API contracts, examples, and live integration tests** — not a self-hosted backend. NEAR mainnet pay-in uses **x402** with **Solvador** as the facilitator; **EarnFi** coordinates quotes, settlement verification, and job funding.

## Problem

Agents reason and call tools, but many tasks need **humans or physical-world execution**. EarnFi is the economic layer between an agent and that work.

## What is in this repository

| Component | Path |
|-----------|------|
| Hackathon docs | \`docs/hackathon/\` |
| API contracts | \`contracts/\` (skill, OpenAPI, MCP card) |
| Agent SDK | \`packages/agent-client/\` |
| MCP server source | \`packages/earnfi-mcp-server/\` |
| Live HTTP examples | \`examples/hackathon/\` |
| Demo console (hosted) | \`public/hackathon-agent.html\` |

## Quick start (verified locally)

\`\`\`bash
npm install
npm run build:sdk
npm test
npm run test:live
node examples/hackathon/01-capabilities.mjs
node examples/hackathon/02-near-quote.mjs
\`\`\`

**Expected:** tests pass; \`02-near-quote\` prints HTTP **402** with a \`near:mainnet\` accept (requires NEAR rail enabled on production).

## Full NEAR payment + funded job

See \`docs/hackathon/DEMO.md\` — use \`@earn-fi/agent-client\` with \`paymentNetwork: 'near:mainnet'\` and a NEAR USDC wallet. Record tx hash and \`job_id\` in \`docs/hackathon/TRANSACTIONS.md\`.

## Architecture

[\`docs/hackathon/ARCHITECTURE.md\`](docs/hackathon/ARCHITECTURE.md) · [Work & Money](docs/hackathon/WORK-MONEY-LAYER.md) · [Payments / Solvador](docs/hackathon/PAYMENTS.md) · [Hosted runtime](docs/hackathon/HOSTED-RUNTIME.md)

## Regenerate export

From the private EarnFi monorepo: \`node scripts/hackathon/export-public-repo.mjs --out ../earnfi-near-hackathon --init-git\`
`;
    fs.writeFileSync(path.join(outDir, 'README.md'), body, 'utf8');
}

function writePlatformExportReadme(outDir, manifest) {
    const body = `# ${manifest.repoName}

**EarnFi — The Work & Money Layer for AI Agents**  
NEAR Hackathon 2026 — **sanitized platform source** export.

Agents **commission human work**, pay via **x402** (NEAR USDC through **Solvador**), and retrieve **submissions and receipts**. Production runs at [app.earnfi.fun](https://app.earnfi.fun).

## What this repository contains

| Layer | Contents |
|-------|----------|
| **PHP application source** | Agent API, Work API services, x402 payment/settlement (incl. NEAR/Solvador), work-money domain services |
| **SPA source (selected)** | Marketplace, wallet connect, NEAR x402 signer, agent API explorer |
| **Agent SDK & MCP** | \`packages/agent-client\`, \`packages/earnfi-mcp-server\` |
| **Contracts & docs** | \`contracts/\`, \`docs/hackathon/\` |

## What is intentionally excluded

Plugin bootstrap (\`growl-fun.php\`), production default settings, admin REST, withdrawal ops, SQL/schema dumps, credentials, and full production bundles.

## Hosted runtime (important)

This code is written for EarnFi’s **hosted PHP runtime** (WordPress-backed integration in production). It is **not** a complete self-hostable copy of EarnFi. Configuration and private persistence live on the production server.

## Verify locally (SDK + live API)

\`\`\`bash
npm install
npm run build:sdk
npm test
npm run test:live
node examples/hackathon/02-near-quote.mjs
\`\`\`

NEAR pay-in walkthrough: \`docs/hackathon/DEMO.md\`. Architecture: \`docs/hackathon/ARCHITECTURE.md\`.

## Regenerate

\`node scripts/hackathon/export-public-repo.mjs --profile platform --out ../earnfi-near-hackathon --force\`
`;
    fs.writeFileSync(path.join(outDir, 'README.md'), body, 'utf8');
}

function writeRootPackageJson(outDir) {
    const pkg = {
        name: 'earnfi-near-hackathon',
        private: true,
        description: 'EarnFi NEAR Hackathon — SDK, MCP, contracts, live API examples',
        workspaces: ['packages/agent-client', 'packages/earnfi-mcp-server'],
        scripts: {
            'build:sdk': 'npm run build -w @earn-fi/agent-client',
            'build:mcp': 'npm run build -w @earnfi/mcp-server',
            test: 'npm test -w @earn-fi/agent-client',
            'test:live': 'npm run test:smoke -w @earn-fi/agent-client',
        },
        engines: { node: '>=20' },
        license: 'MIT',
    };
    fs.writeFileSync(path.join(outDir, 'package.json'), JSON.stringify(pkg, null, 2) + '\n', 'utf8');
}

function writeEnvExample(outDir, profile) {
    const extra =
        profile === 'platform'
            ? `
# Hosted runtime only (illustrative — set on server, not in this repo):
# EARNFI_SOLVADOR_X402_API_KEY=
# EARNFI_FASTNEAR_X402_API_KEY=
`
            : '';
    const body = `# Copy to .env for local examples (never commit real secrets)
EARNFI_AGENT_API_BASE=https://app.earnfi.fun/api/ai-agent/v1
# Optional — mainnet paid job demo only:
# EARNFI_AGENT_TOKEN=
# NEAR wallet via @fastnear/wallet when using agent-client paymentNetwork=near:mainnet
${extra}`;
    fs.writeFileSync(path.join(outDir, '.env.example'), body, 'utf8');
}

function copyContractFiles(outDir, manifest) {
    for (const item of manifest.contractCopies ?? []) {
        const src = path.join(REPO_ROOT, item.from);
        if (!fs.existsSync(src)) {
            console.warn(`Skip missing contract: ${item.from}`);
            continue;
        }
        const dest = path.join(outDir, item.to);
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        let text = fs.readFileSync(src, 'utf8');
        text = sanitizeContent(text, manifest);
        fs.writeFileSync(dest, text, 'utf8');
    }
}

function writeArchitectureDoc(outDir) {
    const archPath = path.join(outDir, 'docs/hackathon/ARCHITECTURE.md');
    if (fs.existsSync(archPath)) {
        return;
    }
    fs.mkdirSync(path.dirname(archPath), { recursive: true });
    const body = `# Architecture (judge view)

## Flow

\`\`\`mermaid
sequenceDiagram
    participant Agent as Autonomous agent
    participant SDK as @earn-fi/agent-client
    participant API as ai-agent/v1
    participant Fac as x402 facilitator (NEAR/Solana/Base)
    participant Human as Human worker

    Agent->>API: POST /jobs/manual (402 + accepts[])
    Agent->>SDK: sign selected accept (NEAR delegate action)
    SDK->>API: PAYMENT-SIGNATURE + retry
    API->>Fac: settle pay-in
    API-->>Agent: job_id + poll URLs
    Human->>API: complete task (existing EarnFi app)
    Agent->>API: poll completion + evidence
\`\`\`

## Layers

1. **Agent API** (\`includes/ai-agent/\`) — registration, jobs, human actions, marketplace orders/deals, public profile, x402 probes on v1 only.
2. **Payments** (\`includes/payments/\`) — multi-accept quotes, network filter header, NEAR scheme adapter, FastNEAR facilitator, settlement pipeline.
3. **SDK + MCP** — single-package multichain signing for agents; MCP tools mirror v1 routes.
4. **Product UI** — full marketplace and profile UX on production; this repo ships API reference (\`agent-api-app.js\`) and hackathon HTML console.

## Honest scope

Pre–19 Oct 2026: Solana-first work marketplace and Agent API. Hackathon period: **NEAR (and Base) as additional x402 pay-in rails** on the same v1 surface — see \`EXISTING-PRODUCT.md\`.
`;
    fs.writeFileSync(archPath, body, 'utf8');
}

const EXPORT_GIT_NAME = 'EarnFi Hackathon Export';
const EXPORT_GIT_EMAIL = 'hackathon-export@earnfi.fun';

function runGit(args, cwd, { forCommit = false } = {}) {
    const prefix = forCommit
        ? ['-c', `user.name=${EXPORT_GIT_NAME}`, '-c', `user.email=${EXPORT_GIT_EMAIL}`]
        : [];
    const result = spawnSync('git', [...prefix, ...args], {
        cwd,
        encoding: 'utf8',
        shell: false,
    });
    if (forCommit && result.status !== 0 && result.stderr) {
        console.warn(result.stderr.trim());
    }
    return result;
}

function repoHasCommits(outDir) {
    const r = runGit(['rev-parse', 'HEAD'], outDir);
    return r.status === 0;
}

function initTopicCommits(outDir, manifest) {
    const check = runGit(['--version'], outDir);
    if (check.status !== 0) {
        console.warn('git not available; skipping --init-git');
        return;
    }
    const gitDir = path.join(outDir, '.git');
    if (fs.existsSync(gitDir) && repoHasCommits(outDir)) {
        console.warn('Output already has git commits; skipping --init-git');
        return;
    }
    if (!fs.existsSync(gitDir)) {
        runGit(['init'], outDir);
        runGit(['checkout', '-b', 'main'], outDir);
    }

    const allFiles = walkFiles(outDir, outDir).map((r) => normalizeRel(r));
    const committed = new Set();

    for (const topic of manifest.topicCommits ?? []) {
        const toAdd = [];
        for (const p of topic.paths) {
            const prefix = normalizeRel(p);
            for (const f of allFiles) {
                if (f === prefix || f.startsWith(prefix + '/')) toAdd.push(f);
            }
        }
        if (toAdd.length === 0) continue;
        for (const f of toAdd) {
            if (committed.has(f)) continue;
            runGit(['add', '--', f], outDir);
            committed.add(f);
        }
        runGit(['commit', '-m', topic.message, '--allow-empty'], outDir, { forCommit: true });
    }

    const remaining = allFiles.filter((f) => !committed.has(f));
    if (remaining.length) {
        runGit(['add', '--', ...remaining], outDir);
        runGit(['commit', '-m', 'chore: remaining export files'], outDir, { forCommit: true });
    }
}

function main() {
    const args = parseArgs(process.argv);
    if (args.help || !args.out) {
        console.log(
            `Usage: node scripts/hackathon/export-public-repo.mjs --out <dir> [--profile platform|bplus] [--init-git] [--force] [--dry-run]`
        );
        process.exit(args.help ? 0 : 1);
    }

    const manifestPath = manifestPathForProfile(args.profile);
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    console.log(`Profile: ${args.profile} (${path.basename(manifestPath)})`);
    const outDir = path.resolve(args.out);

    if (args.gitOnly) {
        if (!fs.existsSync(outDir)) {
            console.error(`Output directory not found: ${outDir}`);
            process.exit(1);
        }
        initTopicCommits(outDir, manifest);
        console.log('Git topic commits applied.');
        process.exit(0);
    }

    const files = collectIncludedFiles(manifest);

    console.log(`Export: ${files.length} files -> ${outDir}`);
    if (args.dryRun) {
        files.slice(0, 40).forEach((f) => console.log('  ', f));
        if (files.length > 40) console.log(`  ... and ${files.length - 40} more`);
        process.exit(0);
    }

    if (fs.existsSync(outDir)) {
        if (!args.force) {
            console.error(`Output directory already exists: ${outDir}`);
            console.error('Use --force to replace or choose another --out path.');
            process.exit(1);
        }
        fs.rmSync(outDir, { recursive: true, force: true });
    }
    fs.mkdirSync(outDir, { recursive: true });

    let copied = 0;
    for (const rel of files) {
        const src = path.join(REPO_ROOT, rel);
        const dest = path.join(outDir, rel);
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        if (isTextFile(rel)) {
            let text = fs.readFileSync(src, 'utf8');
            text = sanitizeContent(text, manifest);
            fs.writeFileSync(dest, text, 'utf8');
        } else {
            fs.copyFileSync(src, dest);
        }
        copied++;
    }

    copyContractFiles(outDir, manifest);
    if (manifest.profile === 'platform') {
        writePlatformExportReadme(outDir, manifest);
    } else {
        writeExportReadme(outDir, manifest);
    }
    writeRootPackageJson(outDir);
    writeEnvExample(outDir, manifest.profile === 'platform' ? 'platform' : 'bplus');
    writeArchitectureDoc(outDir);

    const stamp = path.join(outDir, 'EXPORT-STAMP.json');
    fs.writeFileSync(
        stamp,
        JSON.stringify(
            {
                generatedAt: new Date().toISOString(),
                sourceRoot: 'earnfi-private-monorepo',
                fileCount: copied,
                manifest: path.basename(manifestPath),
            },
            null,
            2
        ),
        'utf8'
    );

    console.log(`Copied ${copied} files.`);
    if (args.initGit) initTopicCommits(outDir, manifest);
    console.log('Done. Push to GitHub and link in the hackathon form.');
}

main();
