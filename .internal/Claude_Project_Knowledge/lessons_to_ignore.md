# Lessons to Ignore — What Doesn't Apply to Nexus

This file records what was in the study materials but should not be applied to Nexus, and explains why. Knowing what to ignore is as important as knowing what to use.

---

## From design.md (Google's format)

**Not applicable:**
- The CLI tool itself (`npx @google/design.md lint`) — We use DESIGN.md as a reference document for Claude, not as a linting tool with a CI step. Nexus does not need to install this package.
- Export commands for Tailwind v3 JSON config — Nexus uses Tailwind v4 CSS-first config, not a JS config file.
- GitHub Actions workflow for design lint — Adds complexity without proportional value at this stage.

---

## From everything-claude-code

**Not applicable:**

- **Golang patterns/rules** — Nexus backend is PHP/Laravel. Nothing from the Go-specific files applies.
- **Python patterns/rules** — Not used in Nexus.
- **Swift patterns/rules** — Not used in Nexus (this is not an iOS app).
- **Frontend slides skill** — Presentation generation. Not a Nexus need.
- **Investor materials skill** — Pitch decks. Not a Nexus need.
- **Investor outreach skill** — Not a Nexus need.
- **Market research skill** — Not a Nexus need.
- **Content engine skill** — Blog/content generation. Not a Nexus need.
- **Eval harness** — LLM evaluation framework. Nexus does not have AI model evaluation needs.
- **Strategic compact skill** — Business strategy tool. Not for Nexus development sessions.

**Use with judgment:**
- The TDD skill recommends "mock-first" (London School). Nexus backend tests use `RefreshDatabase` with real database calls, not mocks. This is intentional (as documented in CLAUDE.md) — a past incident showed mocked tests masking real migration failures. Do not introduce mocking patterns for database operations.

---

## From agency-agents (The Agency)

**Not applicable:**

- **Sales agents** (discovery coach, pipeline analyst, proposal strategist, etc.) — Nexus is a product, not a sales agency.
- **Paid media agents** (PPC strategist, programmatic buyer, etc.) — Nexus does not run ad campaigns.
- **Spatial computing agents** (VisionOS engineer, XR interface architect, etc.) — Not a VisionOS or XR product.
- **Recruitment specialist** — Not applicable.
- **Healthcare marketing compliance** — Wrong industry.
- **Accounts payable agent** — Nexus handles this through the Stripe/ledger system, not through this agent.
- **Government digital presales consultant** — Not applicable.
- **Cultural intelligence strategist** — The Ghana-specific context for Nexus is already established in nexus_design_memory.md. We do not need an agent for this.

**Not worth copying verbatim:**
The agent personality files are designed for Claude to embody a character. For Nexus, we do not need Claude to "become" a UI Designer persona — we need Claude to follow specific Nexus design rules. The rules themselves (design tokens, layout principles) are more useful than the persona format.

---

## From ui-ux-pro-max

**Not applicable:**

- **Logo generation scripts** (Python + Gemini AI) — Nexus already has its brand/logo. Do not regenerate it.
- **CIP (Corporate Identity Program) deliverables** — Business cards, letterheads, envelopes. Not needed for product development.
- **Social photos generation** — Instagram/Twitter/LinkedIn image generation. Not a Nexus development task.
- **Banner design for ads** — Not a development task.
- **Slide presentation generation** — Not a Nexus need.
- **AI Gemini image generation scripts** — These require Gemini API access and are for generating marketing assets. Not for product UI.
- **Icon generation (SVG, Gemini)** — Nexus uses existing icon libraries. Custom AI-generated icons would be inconsistent.
- **The `design-system` skill's CSV data files** (slide-backgrounds.csv, slide-charts.csv, etc.) — These are for presentation generation, not product UI.

---

## From ruflo (Claude Flow)

**Not applicable:**

- **Full swarm orchestration** — 8-agent swarms with hierarchical coordinators are overkill for Nexus tasks. Most Nexus work is single-Claude, single-task. Use the Agent tool for specific research tasks but do not set up a full ruflo swarm.
- **MCP tools for swarm coordination** (`mcp__ruv-swarm__*`) — These tools are part of the ruflo framework and are not set up for Nexus.
- **Dual-mode Claude + Codex coordination** — Nexus does not use OpenAI Codex.
- **WASM/Rust policy engine** — Part of ruflo's internal architecture. Not relevant.
- **RuVector/HNSW intelligence layer** — Vector embedding and semantic search infrastructure. Not needed at Nexus's current scale.
- **LoRA/Int8 quantization patterns** — Machine learning model optimization. Not relevant.
- **9 RL algorithms (Q-learning, SARSA, PPO, DQN)** — Reinforcement learning. Not relevant to Nexus.
- **AgentDB controllers** — ruflo's persistent agent memory system. Too heavy for Nexus.
- **`npx claude-flow` CLI commands** — ruflo's CLI. Not installed in Nexus.

**Use with judgment:**
- The concurrency principle ("all related operations in one message") is worth applying to how Claude batches tool calls, but not to the extent ruflo demands (10+ todos in one write, 8 agents in parallel, etc.). Nexus tasks are usually smaller.
- The "anti-drift" concept is valuable — maintain role boundaries, document decisions, do not let implementations drift from specs. But ruflo's specific mechanism (frequent checkpoints via post-task hooks) is too heavy.

---

## From milvus

**Not applicable to current Nexus scope:**

- Vector database architecture (Go/C++)
- Kubernetes deployment (distributed Milvus)
- HNSW index implementation
- Vector embedding patterns
- GPU acceleration for search
- Zilliz Cloud integration

**Potentially applicable in a future phase:**
If Nexus adds natural language property search (e.g., "find affordable 2-bedroom flats near Accra Airport with parking"), the Milvus architecture would be a reference for the backend search layer. Store this note for that future phase.

---

---

## Rejected Visual Directions (June 2026)

Three tenant dashboard directions were presented and all but one were rejected:

| Direction | Status | Why |
|-----------|--------|-----|
| **Obsidian Pearl** | **CHOSEN** | Approved from reference image — obsidian sidebar + pearl surfaces + mint accent |
| Plum Prestige | **REJECTED** | Purple/aubergine/violet — hard rejected by user |
| Midnight Teal | **NOT CHOSEN** | Influenced Obsidian Pearl but wasn't selected as-is |
| Indigo Clarity | **REJECTED** | Indigo/blue as primary — rejected |

Do not implement Plum Prestige or Indigo Clarity elements. Do not add purple, orchid, violet, lavender, indigo, or aubergine anywhere in Nexus.

---

## General Principle

Just because a study material teaches something does not mean Nexus needs it. The test is:
1. Does this solve a real problem Nexus currently has?
2. Does applying it add value greater than the complexity it introduces?
3. Is it compatible with the existing Nexus architecture and conventions?

If the answer to any of these is no, put it in this file and move on.
