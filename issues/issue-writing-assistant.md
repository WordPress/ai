# Writing Assistant (Suggestions Stream)

## Overview

This issue now tracks the **Suggestions Stream sidebar only**. The inline ghost text/type-ahead implementation shipped already, so the remaining work is to deliver the session-based sidebar that surfaces actionable feedback (readability, SEO, internal links, etc.) while authors write.

> **Status:** Ghost text completions are live. Sidebar UX + backend plumbing described below still needs implementation.

## What problem does this address?

Writers benefit from continuous, contextual feedback while drafting content. Currently:
- AI assistance is typically triggered manually or provided after the fact
- No real-time readability, SEO, or structure feedback during writing
- No inline text completion like modern code editors offer
- Multiple separate tools for different types of writing help

## What is your proposed solution?

Deliver a **session-aware sidebar** that observes editor activity and emits structured suggestions. The sidebar includes a Stream tab (live feed) and a Settings tab (per-session controls). It should sit alongside the existing ghost text tools so authors can use both simultaneously.

---

## Component 1: Suggestions Stream

### Core Architecture

**Sidebar with Tabbed Interface**
- **Stream Tab**: Live feed of suggestions in a DataView/DataTable
- **Settings Tab**: Configuration for suggestion types, triggers, and session preferences

**DataView/DataTable for Suggestions**
Using WordPress's DataViews component enables:
- Filtering by suggestion type (readability, SEO, internal links, etc.)
- Sorting by priority, timestamp, or relevance
- Bulk actions (dismiss all, apply all of type)
- Search within suggestions
- Different view modes (table, list, grid)

### Session-Based Writing

**Session Controls**
- Start/Stop session button
- Session timer (supports Pomodoro-style timed sessions)
- Session statistics (words written, suggestions received, suggestions applied)

**Event-Driven Triggers**
Suggestions generated based on configurable events:
- Word delta threshold (e.g., every 50 words added)
- New paragraph inserted
- Heading added or modified
- Time interval during active typing
- Manual trigger button
- Content idle timeout (analyze when user pauses)

### Suggestion Types

| Type | Icon | Description |
|------|------|-------------|
| Readability | book | Sentence complexity, passive voice, paragraph length |
| SEO | search | Keyword usage, meta considerations, heading structure |
| Internal Links | link | Suggested posts to link, anchor text recommendations |
| Fact Check | check | Claims that may need verification, suggested sources |
| Structure | document | Content flow, missing sections, outline improvements |
| Tone | theater | Consistency, audience alignment, formality level |
| Grammar | pencil | Spelling, punctuation, style issues |

### Suggestion Card Structure

Each suggestion includes:
- **Type badge** (filterable category)
- **Priority indicator** (high/medium/low)
- **Timestamp**
- **Summary** (one-line description)
- **Details** (expandable full explanation)
- **Context** (which paragraph/block it relates to)
- **Actions**: Apply, Dismiss, View in context, Copy

### Contextual Awareness

The AI understands:
- **Draft stage detection**: Early draft vs. revision vs. polish
- **Content type**: Blog post, documentation, news article
- **Previous suggestions**: Avoid repetition, track patterns
- **User preferences**: Learn from accepted/dismissed suggestions

## Combined User Flow

### Starting a Session
1. Author opens "AI Writing Assistant" sidebar
2. Clicks "Start Session"
3. Optionally sets a timer (25min Pomodoro, custom, or unlimited)
4. Chooses which features to enable (Suggestions, Ghost Text, or both)
5. Begins writing

### During Writing
1. Author writes a paragraph about "WordPress block editor"
2. **Suggestions Stream**: After 50 words, suggestions appear in sidebar:
   - Link: "Link 'block editor' to 'Getting Started Guide'"
   - Readability: "Consider breaking this 45-word sentence"
   - SEO: "Primary keyword not yet used in first paragraph"
3. **Ghost text (already shipped)** can continue working in parallel, but no longer needs additional engineering effort from this issue.

### Ending a Session
1. Author clicks "End Session"
2. Session summary displayed:
   - Duration: 25:00
   - Words written: 847
   - Suggestions received: 23
   - Suggestions applied: 8
3. Option to export session report (and optionally show ghost text stats pulled from the already-shipped feature).

---

## UI Mockup

```
+--Editor------------------------+--Sidebar (AI Writing Assistant)--+
|                                | [Stream] [Settings]               |
| # My Blog Post                 |-----------------------------------|
|                                | Session: 12:34 | Words: 342       |
| WordPress 6.9 introduces the   | [Stop Session]                    |
| Abilities API which allows     |-----------------------------------|
| AI to understand site actions. | Filter: [All Types ▼] [Search...] |
|                                |-----------------------------------|
| This powerful new feature      | LINK | HIGH | 2 min ago           |
| enables developers to...       | Link "Abilities API" to guide     |
|                                | [Apply] [Dismiss] [Details]       |
|                                |-----------------------------------|
|                                | READABILITY | MED | 5 min ago     |
|                                | Paragraph 2 has 52 words          |
|                                | Consider splitting for clarity    |
|                                | [Apply] [Dismiss] [Details]       |
+--------------------------------+------------------------------------+
```

---

## Technical Architecture

### Frontend
- `@wordpress/dataviews` for the suggestion list UI.
- Custom WordPress data store for session/suggestion state, shared with the existing ghost text store where sensible.
- `PluginSidebar` from `@wordpress/edit-post`.
- Debounced content change detection so the sidebar remains performant.

### Backend
- Existing `Writing_Assistant` experiment registers REST endpoints for session lifecycle + suggestion retrieval.
- Either a unified ability or multiple abilities per suggestion type (SEO, readability, etc.).
- Efficient prompt design to minimize token usage.
- Caching layer for repeated content analysis.
- Rate limiting per session/user to protect providers.

### AI Integration
- System instruction aware of all suggestion types
- Structured output for consistent parsing
- Context window management for long documents
- Streaming responses for faster perceived performance

### Data Model

```typescript
interface Suggestion {
  id: string;
  type: 'readability' | 'seo' | 'internal-link' | 'fact-check' | 'structure' | 'tone' | 'grammar';
  priority: 'high' | 'medium' | 'low';
  timestamp: Date;
  summary: string;
  details: string;
  blockClientId?: string;
  action?: {
    type: 'insert-link' | 'replace-text' | 'navigate' | 'custom';
    payload: any;
  };
  status: 'pending' | 'applied' | 'dismissed';
}

interface Session {
  id: string;
  startTime: Date;
  endTime?: Date;
  timerDuration?: number;
  wordsAtStart: number;
  suggestions: Suggestion[];
  ghostTextAccepted?: number; // optional, populated by the shipped feature
  settings: SessionSettings;
}
```

### Integration with existing ghost text

The completed ghost text feature should emit events (accepted/dismissed counts, idle timers). The sidebar can listen to those to enrich session summaries but does not require additional RichText work.

---

## Settings

### Suggestions Stream Settings
| Setting | Options | Default |
|---------|---------|---------|
| Enable suggestions | On/Off | On |
| Suggestion types | Toggle each | All on |
| Trigger sensitivity | Word delta, time interval | 50 words |
| Priority threshold | High only / Medium+ / All | All |
| Auto-dismiss applied | On/Off | On |
| Notifications | Sound/Visual | Visual |

----

## Accessibility considerations

- All sidebar controls should be keyboard navigable.
- Suggestions need clear type/priority labels for screen readers.
- Respect reduced-motion preferences for animations/transitions.

---

## Supersedes

This consolidated feature supersedes:
- `issue-suggestions-stream.md`
- `issue-type-ahead-text.md`
- `issue-readability-analysis.md`
- `issue-tone-adjustment.md`

---

## Upstream Alignment

- Roadmap: Content Assistant
- Aligns with Gutenberg editor enhancement goals

## Labels

`enhancement`, `ai`, `editor`, `sidebar`, `autocomplete`, `writing`
