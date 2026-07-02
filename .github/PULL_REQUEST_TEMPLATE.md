## Summary

A short description of what this pull request does.

## What changed

List the files or areas touched, and what changed in each.

## Why it matters

Explain the reason for this change: a bug it fixes, a feature it adds, or a problem it prevents.

## Screenshots

Required for any visible UI change. Attach before and after screenshots, or a short recording. Not needed for backend-only or documentation-only changes.

## Testing checklist

- [ ] Backend tests pass (`php artisan test`)
- [ ] Frontend lint passes (`npm run lint`)
- [ ] Frontend build passes (`npm run build`)
- [ ] Any visible change was opened and used in a real browser, not just checked by lint and build

## Documentation checklist

- [ ] Docs were updated if this change affects setup, behavior, or the API
- [ ] No fenced code blocks were added to markdown docs, except Mermaid diagrams
- [ ] No em dashes were introduced in markdown

## Security checklist

- [ ] Any protected action is enforced on the backend, not just hidden in the interface
- [ ] No new route or action skips role or capability checks
- [ ] No secrets, credentials, or `.env` values are included in this change

## Data truth checklist

- [ ] No fake or placeholder data is presented as if it were real
- [ ] No invented metrics, numbers, or statuses
- [ ] Demo data changes, if any, are limited to development mode only

## Reviewer notes

Anything the reviewer should pay special attention to, or context that is not obvious from the diff.

---

Reminder for the author: stage exact files or hunks only, never a broad "add everything" command, and never a commit with file paths passed directly on the commit command.
