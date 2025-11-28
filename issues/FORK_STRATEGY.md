# Fork Contribution Strategy

This document is the canonical “map” for feature planning on this fork. Update it whenever issues in `issues/` are added, renamed, merged, or deleted.

## Repository setup

| Remote | Repository | Purpose |
|--------|------------|---------|
| `origin` | `Jameswlepage/ai` | Primary fork where day-to-day development happens. |
| `upstream` | `WordPress/ai` | Canonical project; submit PRs here. |

## Branch strategy

- **Integration branch (`develop`)** – Kitchen-sink branch where every experiment lands for internal testing. Never PR this branch upstream.
- **Feature branches** – Branch from `upstream/develop` (or a parent feature branch) per experiment. Keep the scope aligned with the matching Markdown spec inside `issues/`.
- **Upstream PRs** – Cherry-pick or branch from the relevant feature branch when opening against `WordPress/ai`.

---

## Backlog structure (Updated)

Only files listed below should exist under `issues/` right now. Completed specs get deleted (git history remains).

### Recently completed

| Issue | Notes |
|-------|-------|
| `issue-ai-request-logging.md` (removed) | Full logging shipped; spec kept in history only. |
| `issue-comment-moderation.md` (removed) | Experiment + abilities live under `includes/Experiments/Comment_Moderation`. |
| `issue-post-table-bulk-ai.md` (removed) | Experiment + docs live under `includes/Experiments/Post_Table_Bulk`. |
| Writing Assistant (ghost text) | Inline type-ahead completions delivered; only sidebar suggestions remain (still tracked in `issue-writing-assistant.md`). |

### Platform foundations

| File | Summary | Tier |
|------|---------|------|
| `issue-embeddings-platform.md` | Vector index powering semantic search, related posts, and smart 404 suggestions. | Tier 0 |
| `issue-ai-admin-console.md` | Unified Abilities Explorer + MCP server tooling built on DataViews. | Tier 1 |
| `issue-experiment-settings-framework.md` | Schema-driven experiment settings with a React UI, REST endpoints, and reusable controls. | Tier 1 |

### Editorial assistants

| File | Summary |
|------|---------|
| `issue-writing-assistant.md` | Suggestions stream sidebar (ghost text already shipped). |
| `issue-accessibility-suite.md` | Heading/link analysis with auto-fix suggestions. |
| `issue-content-repurposing.md` | Generate platform-specific social copy from posts. |
| `issue-content-freshness-audit.md` | Detect stale references, broken links, and prioritize review queues. |
| `issue-faq-generation.md` | Extract Q&A pairs, insert FAQ blocks, and emit schema. |

### Site operations & insights

| File | Summary |
|------|---------|
| `issue-media-smart-cropping.md` | AI focal point detection for better thumbnails and featured images. |
| `issue-media-transcription.md` | Audio/video transcription pipeline with captions + transcript UI. |
| `issue-media-text-to-speech.md` | Text-to-speech generation plus optional audio feed. |
| `issue-form-response-summary.md` | Summaries, themes, and sentiment for form submissions. |

---

## Dependency overview

```
upstream/develop
│
├── Foundations
│   ├── Embeddings Platform (Tier 0)
│   ├── Writing Assistant (Tier 0 UX)
│   ├── AI Admin Console (Explorer + MCP)
│   └── Experiment Settings Framework
│
├── Editorial Accelerators
│   ├── Accessibility Suite
│   ├── Content Repurposing
│   ├── Content Freshness Audit
│   └── FAQ Generation
│
└── Site Operations & Insights
    ├── Media Smart Cropping
    ├── Media Transcription
    ├── Media Text-to-Speech
    └── Form Response Summary
```

Foundations (embeddings + writing assistant) unblock most downstream UI work. The AI Admin Console and Experiment Settings Framework ensure a consistent admin surface for activating/configuring every experiment.

---

## Implementation sequence

1. **Quick wins / editorial polish**
   - Content Repurposing
   - Accessibility Suite
2. **Foundations**
   - Embeddings Platform (incremental: indexing → search → related posts → smart 404)
   - Writing Assistant (suggestions stream; ghost text already shipped)
   - AI Admin Console + Experiment Settings Framework in parallel to modernize admin tooling.
3. **Operational tooling**
   - Media smart-cropping / transcription / TTS (ship independently).
   - Content Freshness Audit + FAQ Generation dashboards.
   - Form Response Summary (heavier integrations).

---

## Alignment with upstream roadmap

| Local issue | Upstream alignment |
|-------------|-------------------|
| Embeddings Platform | Issues #21 & #37 (Abilities scaling + MCP usage). |
| AI Admin Console | Issues #62 & #63 (Abilities Explorer) plus MCP adapter work. |
| Experiment Settings Framework | Issues #32, #33, #90–#103 (settings modernization). |
| Writing Assistant | Roadmap: Content Assistant. |
| Accessibility Suite | Issue #44 (alt text) + WCAG compliance goals. |
| Media features | Roadmap: Media enhancements & accessibility. |
| Content Freshness / FAQ Generation | Workflow automation & SEO initiatives. |

---

## Open questions

1. **Embeddings storage** – Post meta vs. custom tables vs. external vector DB remains undecided.
2. **MCP exposure** – Do we need multiple MCP namespaces, or is a single default server enough?
3. **Settings schema** – How opinionated should the shared React components be versus letting experiments roll their own UI?
4. **Media processing costs** – Need a budgeting/alerting model before bulk transcription or TTS launches.

---

## File maintenance rules

- Keep feature specs in `issues/issue-*.md` only. When a feature ships, delete the file and add a note in the “Recently completed” table above.
- If a feature spans distinct deliverables (e.g., Media AI), split it into separate Markdown files so scopes stay reviewable.
- When adding a new spec, immediately update the tables in this document so others can discover it.

---

## Next steps

1. [ ] Use this document when triaging or reporting status.
2. [ ] Update the backlog tables whenever issues are added/removed.
3. [ ] Keep branch naming aligned with the file names listed here.
