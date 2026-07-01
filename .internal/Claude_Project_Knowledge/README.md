# Claude Project Knowledge - Nexus

> **Internal AI working context.** This folder documents how Claude should behave
> and think while working on this project. It is not user-facing product
> documentation, is not the public source of truth for Wyncrest's features or
> status (that is `CLAUDE.md` at the repo root and the `docs/` folder), and it
> retains the project's old "Nexus" working name in its own historical prose
> intentionally, since it is a study/decision log, not branding surface. It lives
> under `.internal/` so it does not sit beside the application source as if it
> were part of the product.

**Read this first before doing any Nexus work.**

This folder is the project memory for Nexus. It was built by studying six learning archives in `Claude_Study_Guide/` and combining those lessons with established Nexus design direction.

Do not skip this. Do not assume you remember. Read the relevant files before touching code.

---

## Navigation

### Before Every Session
Read `claude_behavior_rules.md` — it tells you how to act in this project.
Read `nexus_design_memory.md` — it tells you what Nexus should look and feel like.

### Before UI or Design Work
Read `nexus_design_memory.md`
Read `ui_ux_quality_bar.md`
Read `DESIGN.md` (the Nexus design token file)

### Before Frontend Code Changes
Read `frontend_implementation_rules.md`

### Before Backend Code Changes
Read `backend_implementation_rules.md`

### Before Writing a Completion Report
Read `reporting_style.md` — use that exact structure every time.

### To Understand What Was Studied
Read `study_index.md` — it documents every zip file inspected and what lessons came from it.

### For Multi-Agent or Workflow Tasks
Read `agent_workflow.md`

### For Lessons from Study Materials
Read `lessons_to_apply.md` and `lessons_to_ignore.md`

---

## Files in This Folder

| File | What It Is |
|------|------------|
| `README.md` | This file. Start here. |
| `study_index.md` | What was inside each zip, what was learned |
| `DESIGN.md` | Nexus visual identity in design token format |
| `nexus_design_memory.md` | Design direction, visual rules, tenant/landlord/admin UX |
| `ui_ux_quality_bar.md` | What "good UI" means for Nexus specifically |
| `frontend_implementation_rules.md` | TypeScript + React rules for Nexus |
| `backend_implementation_rules.md` | Laravel + PHP rules for Nexus |
| `claude_behavior_rules.md` | How Claude should behave in this project |
| `agent_workflow.md` | Multi-agent patterns worth using |
| `reporting_style.md` | Required format for every completion report |
| `lessons_to_apply.md` | Direct lessons extracted from study materials |
| `lessons_to_ignore.md` | What was in the materials but does not apply |
| `memory_workflow.md` | How future sessions should use this system |

---

## Approval Status at a Glance — Obsidian Pearl Direction Confirmed June 2026

| Category | Status |
|----------|--------|
| GH₵ currency formatting | **APPROVED** — always apply, no exceptions |
| Spacious layouts (24px+ padding, 40px+ sections) | **APPROVED** — always apply |
| Role-specific dashboards (tenant/landlord/admin) | **APPROVED** — always apply |
| State-aware tenant dashboard (3 states) | **APPROVED** — always apply |
| Empty states that guide users | **APPROVED** — always apply |
| Sidebar background: obsidian `#1B1C2A` | **APPROVED** — confirmed Obsidian Pearl |
| Page background: pearl `#F0F0EA` | **APPROVED** — confirmed Obsidian Pearl |
| Accent color: mint `#22C49A` only | **APPROVED** — confirmed Obsidian Pearl |
| Heading font: Space Grotesk | **APPROVED** — confirmed Obsidian Pearl |
| Body font: Hanken Grotesque | **APPROVED** — confirmed Obsidian Pearl |
| Mono font (numbers/IDs): JetBrains Mono | **APPROVED** |
| Purple / violet / orchid / lavender | **HARD REJECTED** — permanently banned |
| Gold / bronze / brown / mustard / dusty warm | **HARD REJECTED** — permanently banned |
| Navy + gold palette (previous proposal) | **REJECTED** — never proposed as final |
| Sora font (previous candidate) | **NOT CHOSEN** — Space Grotesk is confirmed |
| Inter as primary font | **REJECTED** — fallback only |

For future visual changes outside the tenant dashboard (landlord, admin screens): apply the same Obsidian Pearl tokens for color/font, but do not design their layouts until explicitly asked.

---

## Golden Rules (Summary)

1. Nexus is a premium Ghana rental platform. It must not look like a generic SaaS dashboard.
2. The tenant dashboard is a personalized home command center, not a grid of cards.
3. Money always shows as GH₵, formatted correctly.
4. Every design decision should feel spacious, purposeful, and warm.
5. Do not present proposed color/font choices as approved — they are not.
6. Do not fake completion reports. Say what actually happened.
7. Never start coding without reading the relevant knowledge files first.
8. Preserve `Claude_Study_Guide/` as read-only. Never modify it.

---

*This folder is part of the Nexus repository. Keep it updated when new lessons are learned.*
