import { copyFile, mkdir } from 'node:fs/promises';
// Run from controlevida/ (npm run vendor).
await mkdir('assets/vendor', { recursive: true });
await copyFile('node_modules/lucide/dist/umd/lucide.min.js', 'assets/vendor/lucide.min.js');
await copyFile('node_modules/lucide/LICENSE', 'assets/vendor/LUCIDE-LICENSE');
