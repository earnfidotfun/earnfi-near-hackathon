#!/usr/bin/env node
/**
 * Verify sanitized platform export (allows WP in PHP; bans bootstrap/admin/secrets patterns).
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

const BANNED_PATH = [
    /growl-fun\.php$/i,
    /class-admin-rest-api\.php$/i,
    /class-rest-api\.php$/i,
    /\.sql$/i,
    /wp-config/i,
];
const BANNED_CONTENT = [
    /telegram_bot_token.*encrypt\('[^']{10,}/i,
    /solvador_near_api_key.*encrypt\('[^']{10,}/i,
    /\$security->encrypt\('IwtlHrZL/i,
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
        console.error('Usage: node verify-platform-export.mjs --dir <export-root>');
        process.exit(1);
    }
    const root = path.resolve(dir);
    const files = walk(root, root);
    let failed = 0;
    let phpCount = 0;

    for (const rel of files) {
        const n = rel.replace(/\\/g, '/');
        for (const re of BANNED_PATH) {
            if (re.test(n)) {
                console.error(`BANNED PATH: ${n}`);
                failed++;
            }
        }
        if (/\.php$/i.test(n)) phpCount++;
        if (!/\.(php|js|md|json)$/i.test(n)) continue;
        const text = fs.readFileSync(path.join(root, rel), 'utf8');
        for (const re of BANNED_CONTENT) {
            if (re.test(text)) {
                console.error(`SENSITIVE CONTENT in ${n}`);
                failed++;
            }
        }
    }

    if (failed) {
        console.error(`\n${failed} issue(s).`);
        process.exit(1);
    }
  console.log(`OK — platform export: ${files.length} files, ${phpCount} PHP, bootstrap/admin/SQL absent.`);
}

main();