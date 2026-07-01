# Lessons to Apply — From Study Materials

Concrete lessons extracted from the six study archives and how they apply to Nexus.

---

## From design.md (Google's DESIGN.md format)

**What it is:** A specification format where design tokens live in YAML front matter and design rationale lives in the markdown body. Combines machine-readable values with human-readable reasoning.

**Lessons applied:**

1. **Create a DESIGN.md for Nexus** — Done. Lives at `Claude_Project_Knowledge/DESIGN.md`. The format separates approved structural tokens (spacing, radius) from proposed visual directions (colors, fonts) and clearly labels what needs user approval before being locked in.

2. **Design tokens over arbitrary values** — Reference token names, not raw hex. This keeps consistency across sessions. When the palette is confirmed, update it in one place and it propagates.

3. **Section order matters for agents** — The DESIGN.md spec defines a specific section order (Overview → Colors → Typography → Layout → Elevation → Shapes → Components → Do's and Don'ts). The Nexus DESIGN.md follows this and adds approval status markers.

4. **Typography is not just font size** — When a font is confirmed, it should be defined with fontFamily, fontSize, fontWeight, lineHeight, and letterSpacing. The Nexus DESIGN.md has these fields ready in the typography section, commented out as candidates until the user approves a pairing.

5. **Components need token references** — A button's `backgroundColor` should reference `{colors.primary}`, not hardcode a hex value. This means once the palette is approved, components automatically pick up the right colors.

**Correction applied (audited June 2026):**
The initial version of DESIGN.md hard-coded proposed hex values (navy, gold) as if they were final. This was wrong. The file was revised to separate approved structural rules from proposed visual directions.

**Then (June 2026):** The user approved the Obsidian Pearl direction from a reference image. DESIGN.md was fully rewritten with confirmed tokens. The approved palette is obsidian `#1B1C2A` + pearl `#F0F0EA` + mint `#22C49A`. Approved fonts: Space Grotesk (headings) + Hanken Grotesque (body). These are now locked — not proposals.

---

## From everything-claude-code

**What it is:** A comprehensive collection of Claude Code configurations, skills, and cursor rules for TypeScript, Go, Python, and Swift development.

**Lessons applied:**

1. **Composition over inheritance in React** — Build small components that compose. Do not build god components with 15 props. Applied to frontend_implementation_rules.md.

2. **Repository → Service → Controller pattern** — The backend-patterns skill reinforces Nexus's existing layered architecture. Never skip a layer.

3. **Descriptive variable names** — `marketSearchQuery` not `q`. `isUserAuthenticated` not `flag`. Applied to coding standards.

4. **Immutability is critical** — Always use spread operators for state updates, never mutate directly. Especially important for Nexus's financial records.

5. **TypeScript strict mode everywhere** — No `any` for API payloads. All types defined. Applied to frontend rules.

6. **Error handling must be comprehensive** — Catch errors explicitly, log them, and surface a user-friendly message. Never swallow errors silently.

**Not applied from this archive:**
- Golang rules — Nexus uses PHP/Laravel, not Go
- Python rules — Not used in Nexus
- Swift rules — Not used in Nexus
- Frontend slides / investor materials skills — Not relevant

---

## From agency-agents (The Agency)

**What it is:** A collection of AI agent personas with specialized roles (UI Designer, UX Researcher, Brand Guardian, paid media, sales, project management, etc.).

**Lessons applied:**

1. **The UI Designer agent's "Design System First Approach"** — Establish component foundations before creating individual screens. Design for scalability. This validates the approach of building a DESIGN.md first for Nexus before building more screens.

2. **Accessibility from the foundation** — The UI Designer agent builds "WCAG AA minimum compliance" into every design. Nexus should check contrast ratios, especially for text on navy backgrounds.

3. **Brand Guardian mindset** — Apply brand rules consistently. If the sidebar is navy, it should always be navy — no one-off exceptions.

4. **Performance-conscious design** — "Consider loading states and progressive enhancement in all designs." Nexus must have visible loading states.

5. **Design token system values:**
   The agent's CSS token template is a good reference structure. We adapted it for our Tailwind v4 + design.md approach.

**Not applied:**
- Sales agents — Not relevant to Nexus development
- Paid media agents — Not relevant
- Spatial computing agents (VisionOS, XR) — Not relevant
- Most of the specialized agents — Nexus does not need a Jira Workflow Steward

---

## From ui-ux-pro-max

**What it is:** A Claude Code skill pack for visual design work — brand identity, design tokens, logos, banners, presentations, social media images.

**Lessons applied:**

1. **Brand consistency checklist** — Before shipping any new screen, check it against the brand rules. Applied to ui_ux_quality_bar.md.

2. **Token architecture: primitive → semantic → component** — Design token hierarchy:
   - Primitive: `#1B2A4A` (the raw hex)
   - Semantic: `colors.primary` (what it means)
   - Component: `components.button-primary.backgroundColor` (where it's used)
   This is how our DESIGN.md is structured.

3. **Voice framework approach** — Brand voice should be defined not just as "what we say" but as "we are / we are not" contrasts. Nexus is trustworthy, not bureaucratic. Warm, not casual. Professional, not cold.

**Not applied:**
- Logo generation scripts (Python + Gemini) — Nexus has its logo
- CIP (Corporate Identity Program) deliverables — Not relevant at this stage
- Slides/presentation generation — Not relevant
- Social media image generation — Not the focus right now
- Banner design system — Not needed

---

## From ruflo (Claude Flow v3.5)

**What it is:** A multi-agent orchestration platform for Claude Code. 60+ specialized agents, swarm topologies, shared memory, dual Claude/Codex coordination.

**Lessons applied:**

1. **Behavioral rules that are worth adopting:**
   - "Do what has been asked; nothing more, nothing less"
   - "ALWAYS prefer editing an existing file to creating a new one"
   - "ALWAYS read a file before editing it"
   - "NEVER save working files, text/mds, or tests to the root folder"
   These are now in claude_behavior_rules.md.

2. **Concurrency principle** — "All related operations should be concurrent in a single message." Applied: when Claude needs to read multiple files or run multiple checks, do them in parallel.

3. **3-Tier Model Routing** — Useful mental model:
   - Simple, mechanical edits: fast, low-effort (skip heavy reasoning)
   - Medium complexity: standard reasoning
   - Architecture/security/complex tasks: deep reasoning, more careful approach
   
4. **Anti-drift through clear role boundaries** — Ruflo uses specialized agent roles to prevent drift. For Nexus, this means: frontend work stays frontend, backend work stays backend, design decisions are made deliberately not incidentally.

5. **Swarm agent CLAUDE.md conventions** — How to structure rules so they are machine-followable:
   - Use tables for decision routing
   - Use checkboxes for checklists
   - Use concrete examples (good/bad) not vague guidance

**Not applied:**
- The full swarm orchestration system — Too complex for Nexus's current needs. Nexus does not need 8-agent swarms.
- MCP tools for swarm coordination — Not set up for Nexus
- Dual Claude/Codex mode — Nexus works with Claude only
- WASM/Rust kernel details — Not relevant

---

## From milvus

**What it is:** Milvus is a high-performance vector database written in Go and C++. Used for AI applications with semantic/similarity search at scale.

**Lessons applied:**

Nothing directly applicable to Nexus's current scope.

If Nexus adds semantic search for property listings (e.g., "find me a 2-bedroom near Accra with parking") in a future phase, Milvus would be worth revisiting. The architecture shows how to set up vector embeddings + HNSW search for property descriptions.

**Not applied (and why):**
- Go/C++ architecture details — Not Nexus's stack
- Kubernetes deployment patterns — Too early for Nexus
- Vector search patterns — Not implemented in Nexus yet

---

## Cross-Archive Meta-Lessons

Things that showed up consistently across multiple archives:

1. **Read before editing** — Emphasized in ruflo CLAUDE.md, implied by the design-md format, critical for Nexus backend.

2. **Design tokens are machine-readable memory** — design.md and ui-ux-pro-max both converge on this: tokens preserve design decisions between sessions. Nexus now has this via DESIGN.md.

3. **Empty states matter** — Referenced by the UI Designer agent and implied throughout agency-agents design work. Nexus must have helpful, warm empty states — not blank panels.

4. **Composition and separation of concerns** — Everything-claude-code and ruflo both emphasize this. Small components, thin controllers, dedicated services.

5. **Honest reporting** — Implied by the ruflo behavioral rules and explicit in this project's reporting_style.md. Never hide what did not work.
