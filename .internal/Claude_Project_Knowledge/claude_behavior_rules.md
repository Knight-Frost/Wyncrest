# Claude Behavior Rules for Nexus

How Claude should act when working on this project. These override generic defaults.

---

## Before Touching Any File

1. **Read it first.** Always read the current implementation before editing. Do not assume what it contains.
2. **Check if something already exists.** Do not build something that already exists. Search first.
3. **Understand the context.** Read `CLAUDE.md` and the relevant knowledge files before starting any task.
4. **Know what tests cover it.** If the area has tests, know what they cover before changing behavior.

---

## What To Do

- **Do the task. Nothing more, nothing less.** Do not add unrequested features, refactors, or "improvements."
- **Prefer editing existing files over creating new ones.** New files should be the exception, not the default.
- **Make the minimal change that achieves the goal.** Do not clean up surrounding code while fixing a bug.
- **Run verification after making changes.** For frontend: `tsc --noEmit` + `eslint` + `npm run build`. For backend: `php artisan test`.
- **Report the actual result.** If the build fails, say so. If something is incomplete, say so.
- **Ask when genuinely unclear.** If the instruction is ambiguous and the choice matters, ask once and proceed.

---

## What Not To Do

- Do not claim completion without verifying. "I've made the changes" is not completion. Running the build is.
- Do not overwrite user work carelessly. If a file has unsaved or uncommitted changes, be careful.
- Do not create unnecessary branches. Work on `main` unless the user explicitly approves a branch.
- Do not push to GitHub unless the user explicitly asks for it.
- Do not install new npm or composer packages without asking first.
- Do not redesign things that were not asked to be redesigned.
- Do not touch the backend when asked to fix a frontend issue, and vice versa.
- Do not create unnecessary documentation files (*.md, README.md) unless explicitly asked.
- Do not save working files or notes to the repository root.

---

## Honesty Rules

- If something looks ugly, say so directly.
- If implementation differs from the design vision, explain the gap clearly.
- If a feature is incomplete, list what remains.
- If a test fails, report it exactly — do not hide it.
- Do not hide behind technical language. Explain in plain English.
- Do not write "done" when things are partially done.

---

## What "Done" Actually Means

A task is done when:
1. The code change is made correctly
2. The build passes (no TypeScript errors, no ESLint errors, no Laravel test failures)
3. The feature works as expected in the browser (for frontend work)
4. No regressions were introduced

A task is NOT done when:
- The code is written but untested
- The build passes but the feature looks broken or ugly
- There are console errors in the browser
- The task is half-complete

---

## Design Decisions and the Approval Boundary

When working on any UI, read `nexus_design_memory.md` and `DESIGN.md` first.

### What is Approved — Apply These Immediately
- GH₵ for all money values — always
- Spacious layouts: 24px+ card padding, 40px+ section spacing
- State-aware tenant dashboard (no lease / pending / active lease)
- Empty states that guide the user, not just show nothing
- Role-specific dashboard experiences (tenant vs landlord vs admin)
- A heading font with personality — not Inter alone
- Large, personalized hero sections

### What is NOT Approved — Do Not Treat as Final
- The exact color palette (navy + gold is a proposed direction, not confirmed)
- The specific font pairing (Sora is a candidate, not the decision)
- Specific hex values for colors
- The exact sidebar color
- Shadow system specifics

### What to Do When Visual Choices Are Needed
1. Avoid hard-rejected styles (bronze, brown, dusty gold, beige-heavy, washed-out, generic SaaS blue, Inter-only)
2. Apply the approved structural direction (spacious, high contrast, personalized, GH₵)
3. If picking specific colors or fonts, state your choice clearly in the report
4. If making a significant visual change, ask for visual confirmation before locking it in
5. Never write "I applied the Nexus design system" as if the palette is final — it is not

When something does not match the design direction, say so explicitly rather than silently building whatever is fastest or easiest.

---

## File Safety Rules

- Never modify or delete files inside `Claude_Study_Guide/`
- Never commit secrets, `.env` files, or credentials
- Never commit `vendor/`, `node_modules/`, `frontend/dist/`, `*.sqlite`, or build artifacts
- Always prefer `git add [specific files]` over `git add -A` to avoid accidentally committing noise

---

## Reporting

After completing a task, use the structure from `reporting_style.md`. Every time.

Short, honest, in plain English. No technical walls of text. No em dashes. No fake confidence.

---

## Memory Rules

- The project memory lives in `Claude_Project_Knowledge/` (in the repo)
- The CLAUDE.md is also part of project memory
- Do not rely on chat history alone — it gets lost between sessions
- If a new lesson is learned that should survive future sessions, add it to the appropriate knowledge file
- If a design decision changes, update `nexus_design_memory.md` and `DESIGN.md`

---

## The One Rule That Overrides Everything Else

If the user gives you an explicit direct instruction in the conversation, follow that instruction. These knowledge files are defaults and guides. They do not override a direct human instruction. When in conflict, the human wins. Then update the knowledge file to reflect the new direction.
