# Contributing

How to work on Wyncrest safely and consistently.

Who this is for: anyone making a change to the codebase or its documentation.

## Project standards

- Keep the backend test suite green. See [`docs/TESTING.md`](docs/TESTING.md).
- Keep the frontend lint and build clean before pushing.
- Give any visible change a real browser review, not just a passing build. See "Why browser review matters" in [`docs/TESTING.md`](docs/TESTING.md).
- Keep commits focused: one logical change per commit, not a mix of unrelated edits.
- Write commit messages that explain why a change was made, not just what changed.

## Git safety rules

Wyncrest has been burned before by broad or careless staging, so these rules are strict.

| Never do this | Why |
|---|---|
| Stage everything at once with a broad "add all" command | It is easy to accidentally include unrelated or unfinished work |
| Commit with file paths listed directly on the commit command | This captures whatever is currently on disk for those files, not what was actually staged, which can silently commit the wrong content |
| Commit all tracked changes in one shot without reviewing them first | The same risk as above, at a larger scale |

The safe pattern is always: stage exactly the intended files or hunks, review the staged diff, and only then commit.

If a single file mixes an intended change with unrelated pending work, split it: stage only the intended hunk, verify the diff shows exactly that, commit, and leave the rest of the file's changes unstaged for later.

## Data and documentation honesty

- Never invent demo data outside development mode. Production setup must stay clean. See [`docs/SEEDING.md`](docs/SEEDING.md).
- Never write documentation that describes a feature as finished when it is not. If something is planned but not built, say so.
- Never fabricate a number, a status, or an example in a doc. If real data is not available, say that instead of making one up.

## Markdown style

- No em dashes anywhere in project documentation. Use a comma, a colon, a period, or a plain sentence break instead.
- No raw source code, JSON, or SQL in documentation. Short command names in tables are fine when genuinely needed.
- Mermaid diagrams are welcome and encouraged for explaining structure and flow.

## Before you push

| Check | Command |
|---|---|
| Backend tests pass | `php artisan test` |
| Frontend lint is clean | `npm run lint` |
| Frontend build succeeds | `npm run build` |
| Any visible change was actually opened and used in a browser | Manual review |
| The staged diff matches exactly what you intended to commit | `git diff --cached` |
