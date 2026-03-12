#!/usr/bin/env node
/**
 * Simple bundler: concatenates all JS modules into one erp.bundle.js
 * Strips import/export statements, wraps in IIFE.
 * Run: node erp/build.js
 */
const fs = require('fs');
const path = require('path');

const jsDir = path.join(__dirname, 'js');
const outFile = path.join(jsDir, 'erp.bundle.js');

// Order matters — core first, app last
const files = [
    'core.js',
    'dashboard.js',
    'catalog.js',
    'inventory.js',
    'purchasing.js',
    'sales.js',
    'crm.js',
    'finance.js',
    'tasks.js',
    'settings.js',
    'ai.js',
    'app.js',
];

let bundle = '// ── ERP Bundle (auto-generated) ──\n';
bundle += '(function() {\n"use strict";\n\n';

for (const file of files) {
    const filePath = path.join(jsDir, file);
    let code = fs.readFileSync(filePath, 'utf8');

    // Remove import statements
    code = code.replace(/^import\s+\{[^}]+\}\s+from\s+['"][^'"]+['"];?\s*$/gm, '');
    code = code.replace(/^import\s+['"][^'"]+['"];?\s*$/gm, '');

    // Remove export keywords (export function, export async function, export const)
    code = code.replace(/^export\s+(async\s+)?function\s/gm, '$1function ');
    code = code.replace(/^export\s+const\s/gm, 'const ');
    code = code.replace(/^export\s+let\s/gm, 'let ');
    code = code.replace(/^export\s+\{[^}]+\};?\s*$/gm, '');

    bundle += `// ── ${file} ──\n${code}\n\n`;
}

bundle += '})();\n';

fs.writeFileSync(outFile, bundle, 'utf8');
console.log(`Bundle created: ${outFile} (${(bundle.length / 1024).toFixed(1)} KB)`);
