import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

/** Resets the e2e accounts and offer (`php artisan e2e:prepare`) and stores what the specs need. */
export default function globalSetup(): void {
    const artisan = process.env.E2E_ARTISAN ?? 'docker compose exec -T laravel.test php artisan';
    const out = execSync(`${artisan} e2e:prepare`, { encoding: 'utf8' });
    const line = out.trim().split(/\r?\n/).reverse().find((l) => l.startsWith('{'));
    if (!line) throw new Error('e2e:prepare printed no fixtures:\n' + out);
    mkdirSync('storage/e2e-results', { recursive: true });
    writeFileSync('storage/e2e-results/fixtures.json', line);
}
