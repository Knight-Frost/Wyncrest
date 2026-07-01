# Agent Workflow for Nexus

When and how to use multi-agent patterns in Nexus development sessions.

---

## The Default: Solo Claude

Most Nexus tasks do not need multiple agents. A single Claude session can:
- Read files, understand context, make changes, run checks
- Fix bugs
- Add single features
- Write tests
- Update documentation

Use solo Claude for anything that fits in one context window and can be verified with a build check.

---

## When to Use the Agent Tool

Use a spawned agent (via the Agent tool) when:

1. **Research tasks** — "Find all places in the codebase where we format money" — an Explore agent is faster than manually searching.

2. **Large codebase exploration** — When you need to understand a large set of files before making a decision, spawn an Explore agent to map it out.

3. **Independent parallel work** — If two pieces of work genuinely do not depend on each other (e.g., fix a frontend bug AND update a backend validation rule), two agents can run in parallel.

4. **Code review** — Use the `code-reviewer` agent type after completing a significant change.

---

## Useful Agent Types (Already Available)

| Agent | When to Use |
|-------|-------------|
| `Explore` | Find files, symbols, patterns. Quick read-only search. |
| `feature-dev:code-explorer` | Deep analysis of how a feature works end-to-end |
| `feature-dev:code-reviewer` | Review a completed implementation for issues |
| `pr-review-toolkit:code-reviewer` | Review code against project guidelines before a PR |

---

## What NOT to Do with Agents

- Do not spawn 8 agents in parallel for a simple bug fix
- Do not use agents for tasks that require sequential, dependent operations
- Do not use agents to avoid thinking — use them to extend capability
- Do not spawn an agent without a clear, specific task
- Never use swarm orchestration (the ruflo pattern) for Nexus — it is too heavy for this project

---

## Useful Concurrency Patterns (Within a Single Claude Message)

Even without spawning agents, Claude can parallelize tool calls in one message:

```
// In a single message, Claude can:
Read(file_a) + Read(file_b) + Read(file_c)  // parallel reads
Edit(file_a) + Edit(file_b)                  // parallel edits (if independent)
Bash(command_1) + Bash(command_2)            // parallel shell commands
```

This is the practical version of ruflo's "all related operations in one message" rule. Use it for efficiency without needing a full orchestration framework.

---

## Workflow for a Standard Nexus Feature

For a medium-complexity feature (e.g., adding a new section to the tenant dashboard):

1. **Read** — Read the relevant page file, component files, types, API endpoints
2. **Plan** — Decide what needs to change (do not start coding yet)
3. **Implement** — Make changes in order of dependency (types first, then API, then component, then page)
4. **Check** — Run `tsc --noEmit`, `eslint`, `npm run build`
5. **Report** — Use the format from `reporting_style.md`

No agents needed for this. It is a solo task.

---

## Workflow for a Large Refactor

If asked to refactor something that touches 10+ files:

1. **Spawn an Explore agent** — Map out all files that reference the thing being refactored
2. **Review the map** — Decide the order of changes
3. **Execute changes** — Solo Claude, working through the list
4. **Run full check** — TypeScript + ESLint + build + tests
5. **Report** — Honest report of what changed, what was checked, what risks exist

---

## When to Use the Workflow Tool

The Workflow tool (multi-agent orchestration) is available but should rarely be needed for Nexus. Consider it only for:
- A comprehensive audit of the entire codebase (security review, accessibility review)
- A large migration that touches 30+ files independently
- User explicitly asks for "ultracode" or "use a workflow"

For anything else, solo Claude or the Agent tool is sufficient.
