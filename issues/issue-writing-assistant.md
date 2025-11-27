# Writing Assistant

## Overview

A comprehensive AI writing assistant for the Gutenberg editor that provides real-time feedback and acceleration through two complementary interfaces: a suggestions stream sidebar and inline ghost text completions.

## What problem does this address?

Writers benefit from continuous, contextual feedback while drafting content. Currently:
- AI assistance is typically triggered manually or provided after the fact
- No real-time readability, SEO, or structure feedback during writing
- No inline text completion like modern code editors offer
- Multiple separate tools for different types of writing help

## What is your proposed solution?

A unified "AI Writing Assistant" experiment with two main components:
1. **Suggestions Stream** - Passive, reviewable feedback in a sidebar
2. **Ghost Text (Type-ahead)** - Active, inline text completion

Both share session context, settings, and AI infrastructure.

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

---

## Component 2: Ghost Text (Type-ahead)

### Core UX Pattern

**Ghost Text Display**
- Dimmed/opaque text appears ahead of cursor
- Visually distinct from authored content (40% opacity, italic)
- Does not interfere with normal typing
- Disappears if user types something different

**Keyboard Interactions**

| Key | Action |
|-----|--------|
| `Tab` | Accept entire suggestion |
| `Ctrl/Cmd + →` | Accept next word |
| `Ctrl/Cmd + Shift + →` | Accept next sentence |
| `Escape` | Dismiss suggestion |
| Any typing | Ignores suggestion, continues with user input |

### Completion Modes

| Mode | Description |
|------|-------------|
| **Word** | Complete current word only |
| **Sentence** | Complete to end of sentence |
| **Paragraph** | Complete entire thought/paragraph |
| **Smart** | AI decides based on context |

### Trigger Behavior

**When to Show Suggestions**
- After typing pause (configurable: 300ms - 2s)
- At end of sentence (period, question mark)
- After specific triggers (colon, "such as", "for example")
- Manual trigger via keyboard shortcut

**When NOT to Show**
- While user is actively typing
- In headings (unless enabled)
- In code blocks
- When disabled in settings

### Visual Example

```
+--Paragraph Block----------------------------------------+
|                                                         |
| WordPress 6.9 introduces the Abilities API, which|      |
|                                                  ↑      |
|                                               cursor    |
|                                                         |
| [ghost: allows plugins to register AI-powered actions.] |
|         ↑                                               |
|    dimmed/opaque text, not selectable                   |
|                                                         |
+---------------------------------------------------------+

Status bar hint: [Tab] Accept  [Cmd+→] Accept word  [Esc] Dismiss
```

---

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
3. **Ghost Text**: Author pauses, ghost text appears:
   - ` provides a modern editing experience with drag-and-drop functionality.`
4. Author presses Tab to accept, or keeps typing to dismiss

### Ending a Session
1. Author clicks "End Session"
2. Session summary displayed:
   - Duration: 25:00
   - Words written: 847
   - Suggestions received: 23
   - Suggestions applied: 8
   - Ghost text accepted: 12 times
3. Option to export session report

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
| [ghost: seamlessly integrate   | READABILITY | MED | 5 min ago     |
|  AI capabilities into their    | Paragraph 2 has 52 words          |
|  existing plugins.]            | Consider splitting for clarity    |
|                                | [Apply] [Dismiss] [Details]       |
+--------------------------------+------------------------------------+
```

---

## Technical Architecture

### Frontend
- `@wordpress/dataviews` for suggestion list
- Custom WordPress data store for session/suggestion state
- `PluginSidebar` from `@wordpress/edit-post`
- Debounced content change detection
- Custom RichText extension for ghost text overlay

### Backend
- New experiment: `Writing_Assistant`
- Unified ability or multiple abilities per suggestion type
- Efficient prompt design to minimize token usage
- Caching layer for repeated content analysis
- Rate limiting per session

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
  ghostTextAccepted: number;
  settings: SessionSettings;
}
```

### Ghost Text Implementation

**Potential Approaches:**

1. **RichText Format Registration**
   - Register a custom format for ghost text
   - Mark as non-editable, auto-remove on interaction

2. **Absolute Positioned Overlay**
   - Render ghost text in a separate element
   - Position absolutely based on cursor coordinates

3. **Block Editor Filter**
   - Use `editor.BlockEdit` filter
   - Wrap paragraph block with ghost text logic

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

### Ghost Text Settings
| Setting | Options | Default |
|---------|---------|---------|
| Enable ghost text | On/Off | Off |
| Completion mode | Word/Sentence/Paragraph/Smart | Smart |
| Trigger delay | 300ms - 2000ms | 500ms |
| Minimum confidence | 0-100% | 70% |
| Show in headings | On/Off | Off |
| Visual style | Opacity, color | 40%, theme |

---

## Accessibility Considerations

- Ghost text announced by screen readers (aria-label)
- Clear visual distinction from authored content
- Keyboard-only operation (no mouse required)
- Option to disable entirely
- Respect reduced motion preferences

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
