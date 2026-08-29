# Copilot Instructions

## Project overview

This repository contains a Laravel 12 flash-sale engine deployed with Docker Compose. The stack includes PHP 8.2+, MySQL 8, Redis, Nginx, and Vite. The single Laravel application lives under `app/`.

## Repository layout

- `app/`: Laravel application, routes, controllers, models, migrations, tests, and Composer dependencies
- `app/Dockerfile`: PHP application image definition
- `docker-compose.yml`: Local app, database, Redis, and Nginx services
- `nginx/`: Reverse-proxy configuration

## Development workflow

- Run the full stack with `docker compose up -d`.
- Laravel commands should run from `app/`.
- Install PHP dependencies with `composer install`.
- Install frontend dependencies with `npm install`.
- Build frontend assets with `npm run build`.
- Run tests with `composer test` or `php artisan test`.
- Format PHP with `vendor/bin/pint`.
- Check the health endpoint with `curl http://localhost/health`.

## Change guidelines

- Follow Laravel conventions and existing project patterns.
- Keep controllers thin and place reusable business logic in appropriate application services.
- Add or update migrations for database schema changes.
- Add tests for changed behavior, especially inventory, concurrency, checkout, and queue flows.
- Keep `/health` available as the monitoring endpoint; place application API routes under the established API routing configuration.
- Do not commit `.env` files, credentials, API keys, or other secrets.
- Treat `app/vendor/`, compiled assets, caches, and logs as generated files; do not modify or commit them unless required.
- Keep the repository consolidated under `app/`; do not recreate an `app/laravel/` duplicate.
