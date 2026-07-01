# Memory and Continuity Workflow

How future Claude sessions should use the Claude_Project_Knowledge system. Simple enough to follow without reading this whole chat.

---

## Starting a New Session on Nexus

Do these steps in order before touching any code:

1. Read `Claude_Project_Knowledge/README.md` — understand the navigation
2. Read `CLAUDE.md` — project rules and architecture
3. Read the file(s) most relevant to today's task:
   - UI/design work → `nexus_design_memory.md` + `DESIGN.md` + `ui_ux_quality_bar.md`
   - Frontend code → `frontend_implementation_rules.md`
   - Backend code → `backend_implementation_rules.md`
   - Writing a report → `reporting_style.md`
   - Unsure what applies → `lessons_to_apply.md`

This takes about 3-5 minutes. It prevents mistakes that would take 30-60 minutes to undo.

---

## What to Read for Different Task Types

| Task | Files to Read |
|------|---------------|
| Any UI change | `nexus_design_memory.md`, `ui_ux_quality_bar.md`, `DESIGN.md` |
| Tenant dashboard work | All three above, focus on the tenant dashboard section in nexus_design_memory.md |
| New frontend component | `frontend_implementation_rules.md` |
| Backend endpoint or service | `backend_implementation_rules.md` |
| Bug fix (frontend) | `frontend_implementation_rules.md` + read the broken component first |
| Bug fix (backend) | `backend_implementation_rules.md` + read the broken controller/service first |
| Completion report | `reporting_style.md` — use the exact format |
| Multi-agent orchestration | `agent_workflow.md` |
| Anything involving the study materials | `study_index.md` + `lessons_to_apply.md` |

---

## When to Update the Knowledge System

Update a knowledge file when:
- A design decision changes (update `nexus_design_memory.md` and `DESIGN.md`)
- A new coding rule is established (update the relevant implementation rules file)
- A new lesson is learned that should survive future sessions (add to `lessons_to_apply.md`)
- A decision is made to NOT do something (add to `lessons_to_ignore.md`)
- The reporting format changes (update `reporting_style.md`)
- The behavior rules change (update `claude_behavior_rules.md`)

Do NOT update the knowledge files for:
- Task-specific notes ("I'm currently working on X")
- Temporary state ("the build is broken because of Y")
- Things already documented in the code or CLAUDE.md

---

## When to Update CLAUDE.md

Update `CLAUDE.md` when:
- Major architecture decisions change
- New tools or dependencies are added
- New areas of the codebase are built
- Status of features changes (§3 status table)
- Known unfinished work changes (§23 punch list)
- New conventions are established that need to be permanent

---

## What NOT to Put in the Knowledge System

- Debugging notes from a specific session
- Git history or recent changes (use `git log` for that)
- Specific PR details (goes in PR description, not knowledge files)
- Temporary workarounds that will be removed soon
- Anything already derivable from reading the code itself

---

## The Layered Memory System

Nexus has three layers of project memory. Know which layer to use:

| Layer | Where | What Goes There |
|-------|-------|-----------------|
| Code | The codebase itself | What the system does |
| CLAUDE.md | Project root | Architecture, status, rules, conventions |
| Claude_Project_Knowledge/ | Project root | Design memory, behavior rules, lessons, workflow guides |

These layers complement each other. The code is the source of truth for behavior. CLAUDE.md is the source of truth for architecture. Claude_Project_Knowledge/ is the source of truth for design direction, Claude behavior, and study-material lessons.

---

## How to Preserve New Lessons

If you discover something worth remembering for future sessions:

1. Identify which file it belongs in (design? behavior? frontend rules? backend rules?)
2. Add a clear, actionable entry — not vague, specific
3. If it is a lesson from a new piece of research, add it to `lessons_to_apply.md` or `lessons_to_ignore.md`
4. If it changes an existing rule, update the existing entry rather than adding a duplicate

Bad: "Claude should be careful about design" (too vague)
Good: "Do not use the gold color (#C9A227) for error or warning states — it reads as 'premium' not 'alert'. Use the semantic error/warning colors instead." (specific, actionable)

---

## How to Know If a Memory Is Stale

Knowledge files can become outdated. Before acting on something from this folder:

1. Check if the file it references still exists
2. Check if the pattern it describes still matches the code
3. Check the git log if you are unsure when something changed

If a memory conflicts with current code, trust the current code. Then update or remove the stale memory entry.

---

## Claude_Study_Guide (Read-Only)

The original study materials live in `Claude_Study_Guide/`. They are **read-only reference material**.

- Never modify the zip files
- Never modify extracted files inside the study guide folder
- If you need to reference extracted content, read from `Claude_Learning_Workspace/` (the safe extraction copy)
- If `Claude_Learning_Workspace/` gets deleted (it is not committed to git), re-extract from the zips
