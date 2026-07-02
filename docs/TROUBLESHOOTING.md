# Troubleshooting

Common problems and how to fix them.

Who this is for: anyone stuck on a local setup or workflow issue.

| Problem | Likely cause | Fix | Where to look |
|---|---|---|---|
| App will not start at all | A dependency was never installed, or the `.env` file is missing | Run `composer install` and `npm install`, and copy `.env.example` to `.env` | [`docs/DEVELOPMENT.md`](DEVELOPMENT.md) |
| "Port already in use" | Another process is already using port 8000 or 5173 | Stop the other process, or set a different port before starting | Terminal output from `dev.sh` or `artisan serve` |
| Database needs a reset | Local data is stale, corrupted, or from an old schema | Run `php artisan migrate:fresh --seed`, which safely rebuilds a local SQLite database | [`docs/SEEDING.md`](SEEDING.md) |
| Seed data looks wrong or incomplete | The seeder did not finish, or ran against the wrong mode | Run `php artisan wyncrest:seed:verify` to see exactly what failed | [`docs/SEEDING.md`](SEEDING.md) |
| Login fails with correct-looking credentials | Wrong environment, or the seeded demo account was never created | Confirm you are in development mode and re-run the seeder | [`docs/SEEDING.md`](SEEDING.md) |
| API returns 401 Unauthorized | No login token was sent, or it expired | Log in again to get a fresh token | [`docs/AUTHORIZATION.md`](AUTHORIZATION.md) |
| API returns 403 Forbidden | The logged-in account does not have the right role or capability for this action | Confirm the account's role, or ask a super admin to grant the needed capability | [`docs/AUTHORIZATION.md`](AUTHORIZATION.md) |
| Frontend build fails | A type error, a lint error, or a missing dependency | Run `npm run lint` and `npm run build` to see the exact error | [`docs/TESTING.md`](TESTING.md) |
| Styles or theme look wrong | A stale build cache, or the wrong theme or accent selected | Restart the dev server, and check the theme picker in account settings | [`docs/UI_SYSTEM.md`](UI_SYSTEM.md) |
| A git commit included files you did not mean to stage | Broad staging commands were used instead of exact files | Unstage the extra files, then stage only the intended ones | [`CONTRIBUTING.md`](../CONTRIBUTING.md) |
| Mermaid diagrams in a doc do not render on GitHub | A syntax mistake in the diagram, such as a missing arrow or unmatched bracket | Check the diagram against a working one in this repo, or preview it locally before pushing | Any doc with a diagram |
| The GitHub repository was renamed, but git still pushes to the old URL | The local remote address was never updated | Update the remote address to the new repository URL | Git configuration for this project |
