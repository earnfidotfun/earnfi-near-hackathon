#!/usr/bin/env node
/**
 * Fail if the hackathon export tree contains PHP or common WordPress markers.
 * Usage: node scripts/hackathon/verify-no-wordpress.mjs --dir ../earnfi-near-hackathon
 */
import fs from 'node:fs';
import path from 'node:path';

function parseArgs(argv) {
    let dir = '';
    for (let i = 2; i < argv.length; i++) {
        if (argv[i] === '--dir') dir = argv[++i] ?? '';
    }
    return dir;
}

const BANNED_PATH = [/growl-fun\.php$/i, /\/includes\//i, /wp-config/i, /wp-includes/i];
const BANNED_CONTENT = [
    /\bABSPATH\b/,
    /\bWP_REST_Request\b/,
    /\bget_option\s*\(\s*['"]growl_settings/,
    /\$wpdb\b/,
    /\badd_action\s*\(/,
];

function walk(dir, base, files = []) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
        if (ent.name === 'node_modules' || ent.name === '.git') continue;
        const full = path.join(dir, ent.name);
        if (ent.isDirectory()) walk(full, base, files);
        else files.push(path.relative(base, full));
    }
    return files;
}

function main() {
    const dir = parseArgs(process.argv);
    if (!dir || !fs.existsSync(dir)) {
        console.error('Usage: node verify-no-wordpress.mjs --dir <export-root>');
        process.exit(1);
    }
    const root = path.resolve(dir);
    const files = walk(root, root);
    let failed = 0;

    for (const rel of files) {
        const n = rel.replace(/\\/g, '/');
        for (const re of BANNED_PATH) {
            if (re.test(n)) {
                console.error(`BANNED PATH: ${n}`);
                failed++;
            }
        }
        if (/\.php$/i.test(n)) {
            console.error(`BANNED PHP FILE: ${n}`);
            failed++;
        }
        if (!/\.(js|mjs|cjs|ts|md|json|html|yml|yaml|txt)$/i.test(n)) continue;
        if (
            n === 'scripts/hackathon/verify-no-wordpress.mjs' ||
            n === 'scripts/hackathon/export-manifest.json' ||
            n.endsWith('SUBMISSION-REPO.md')
        ) {
            continue;
        }
        const text = fs.readFileSync(path.join(root, rel), 'utf8');
        for (const re of BANNED_CONTENT) {
            if (re.test(text)) {
                console.error(`BANNED CONTENT in ${n}: ${re}`);
                failed++;
            }
        }
    }

    if (failed) {
        console.error(`\n${failed} issue(s). Submission tree is not WordPress-free.`);
        process.exit(1);
    }
    console.log(`OK — ${files.length} files scanned, no WordPress server traces.`);
}

main();