import { copyFile, mkdir } from 'node:fs/promises';
await mkdir('controlevida/assets/vendor', { recursive: true });
await copyFile('node_modules/lucide/dist/umd/lucide.min.js', 'controlevida/assets/vendor/lucide.min.js');
await copyFile('node_modules/lucide/LICENSE', 'controlevida/assets/vendor/LUCIDE-LICENSE');
