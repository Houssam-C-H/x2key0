# X2KEY

Multi-vendor marketplace for digital game goods. Spec: [docs/X2KEY_SPEC.md](docs/X2KEY_SPEC.md) · Status: [docs/PROGRESS.md](docs/PROGRESS.md) · Decisions: [docs/DECISIONS.md](docs/DECISIONS.md).

## Stack
Laravel 13 · Inertia 3 + React 19 + TypeScript (SSR) · Tailwind 4 · Filament 5 · PostgreSQL · Redis + Horizon · Reverb · Meilisearch · Pest · Larastan (max).

## Run with Sail (recommended)
```sh
composer install && npm ci
cp .env.example .env && php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
npm run dev
```

## Checks
```sh
composer test   # Pest
composer stan   # Larastan level max
composer lint   # Pint
npm run typecheck && npm run build
```
