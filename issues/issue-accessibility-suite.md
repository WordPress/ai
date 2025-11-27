# Accessibility Suite

## Overview

An AI-powered accessibility assistant for the Gutenberg editor that helps authors create WCAG-compliant content through real-time structure analysis and content improvement suggestions.

## What problem does this address?

Accessibility issues hurt users and expose sites to legal risk:

**Heading Structure Problems**
- Skipping levels (H1 → H3) breaks screen reader navigation
- Multiple H1s confuse document structure
- Using headings for styling rather than semantics
- WCAG 2.1 violation (1.3.1 Info and Relationships)

**Link Text Problems**
- "Click here" and "Read more" are meaningless out of context
- Screen reader users navigate by links - need descriptive text
- WCAG 2.1 violation (2.4.4 Link Purpose)

Common mistakes are easy to make and hard to catch without tooling.

## What is your proposed solution?

A unified "Accessibility Assistant" sidebar with multiple analysis tools, presented in a tabbed interface for easy navigation.

---

## Component 1: Heading Structure Analysis

### Features
- **Structure Visualization**: Show document outline in sidebar
- **Issue Detection**: Flag heading hierarchy violations
- **Fix Suggestions**: Recommend correct heading levels
- **Auto-Fix Option**: Apply all suggestions with one click
- **Visual Indicators**: Highlight problematic headings in editor

### Detection Rules
- Multiple H1 elements (should be one)
- Skipped heading levels (H2 → H4)
- Empty headings
- Headings used for styling (very short, no semantic meaning)
- Too-deep nesting (H5, H6 often indicate structure problems)

### Document Outline View

```
+-- Document Outline ------------------+
|                                      |
| ✓ H1: My Post Title                  |
|   ✓ H2: Introduction                 |
|   ⚠ H4: First Point [Skip: H3]       |
|     ↳ Suggest: Change to H3          |
|   ✓ H2: Details                      |
|     ✓ H3: Implementation             |
|     ✓ H3: Examples                   |
|                                      |
| [Fix All Issues]                     |
+--------------------------------------+
```

### User Flow

1. Author opens post with heading issues
2. Sidebar shows document outline with warnings
3. Author clicks warning to jump to issue
4. Suggestion shown: "Change H4 to H3"
5. One-click fix or manual adjustment

---

## Component 2: Link Text Improvement

### Features
- **Vague Link Detection**: Find problematic link text patterns
- **Context-Aware Suggestions**: Generate descriptive alternatives using AI
- **Inline Warnings**: Highlight issues while editing
- **Bulk Review**: Show all vague links in sidebar

### Detection Patterns
- Generic phrases: "click here", "read more", "learn more", "here", "this"
- URL as text: "https://example.com"
- Single words: "link", "article", "page"
- Ambiguous references: "this post", "that guide"

### Example Transformations

| Original | AI Suggestion |
|----------|---------------|
| "Click here to download" | "Download the plugin ZIP file" |
| "Read more" | "Read the full accessibility guide" |
| "https://wordpress.org" | "WordPress.org official site" |
| "Learn more about this" | "Learn more about the Abilities API" |

### Link Review Panel

```
+-- Link Accessibility ----------------+
|                                      |
| Found 3 issues                       |
|                                      |
| ⚠ "Click here" (paragraph 2)        |
|   Suggest: "View the release notes"  |
|   [Apply] [Dismiss] [Go to]          |
|                                      |
| ⚠ "Read more" (paragraph 5)         |
|   Suggest: "Read the full guide"     |
|   [Apply] [Dismiss] [Go to]          |
|                                      |
| ⚠ "https://example.com" (para 7)    |
|   Suggest: "Example documentation"   |
|   [Apply] [Dismiss] [Go to]          |
|                                      |
| [Fix All]                            |
+--------------------------------------+
```

### User Flow

1. Author adds link with text "click here"
2. Warning indicator appears on the link
3. Sidebar shows the issue with AI-generated suggestion
4. Author clicks "Apply" to accept suggestion
5. Link text updated, preserving URL

---

## Combined UI: Accessibility Sidebar

```
+--Sidebar (Accessibility Assistant)---+
| [Structure] [Links] [Summary]        |
|--------------------------------------|
|                                      |
| Structure Tab:                       |
| Document outline with heading tree   |
|                                      |
| Links Tab:                           |
| List of link issues with suggestions |
|                                      |
| Summary Tab:                         |
| +--------------------------------+   |
| | Accessibility Score: 85/100    |   |
| |                                |   |
| | ✓ Heading structure: 1 issue   |   |
| | ⚠ Link text: 3 issues          |   |
| | ✓ Images: All have alt text    |   |
| +--------------------------------+   |
|                                      |
| [Run Full Audit]                     |
+--------------------------------------+
```

---

## Integration Points

- **Editor Sidebar**: "Accessibility" panel (PluginSidebar)
- **Pre-publish Checks**: Block publishing if severe issues (optional)
- **Block Toolbar**: Quick level adjustment buttons for headings
- **Inline Indicators**: Visual markers on problematic elements
- **Post Status**: Accessibility score in publish panel

---

## Technical Considerations

### Heading Analysis
- Parse block content for heading blocks
- Real-time analysis as headings change
- Consider post title as implicit H1
- Handle headings in reusable blocks

### Link Analysis
- Need surrounding context to generate good AI suggestions
- Preserve link URL while changing text
- Handle links in navigation/menus differently
- Consider link purpose (download, external, anchor)

### Performance
- Debounce analysis on content changes
- Cache suggestions until content changes
- Lazy-load AI suggestions (show detection immediately, load suggestions on demand)

---

## Data Model

```typescript
interface AccessibilityIssue {
  id: string;
  type: 'heading-skip' | 'heading-multiple-h1' | 'heading-empty' | 'link-vague' | 'link-url-text';
  severity: 'error' | 'warning' | 'suggestion';
  blockClientId: string;
  message: string;
  suggestion?: string;
  autoFixable: boolean;
}

interface AccessibilityAudit {
  score: number;
  issues: AccessibilityIssue[];
  headingStructure: HeadingNode[];
  timestamp: Date;
}
```

---

## Settings

| Setting | Options | Default |
|---------|---------|---------|
| Enable accessibility checks | On/Off | On |
| Check heading structure | On/Off | On |
| Check link text | On/Off | On |
| Pre-publish warning | Off/Warning/Block | Warning |
| Show inline indicators | On/Off | On |
| Consider title as H1 | On/Off | On |

---

## WCAG Alignment

This feature directly supports WCAG 2.1 compliance:

| Guideline | Criterion | Feature |
|-----------|-----------|---------|
| 1.3.1 | Info and Relationships | Heading structure analysis |
| 2.4.4 | Link Purpose (In Context) | Link text improvement |
| 2.4.6 | Headings and Labels | Heading structure analysis |
| 2.4.9 | Link Purpose (Link Only) | Link text improvement |

---

## Future Expansion

This suite can be extended with additional accessibility checks:
- Alt text analysis (aligns with upstream issue #44)
- Color contrast checking
- Form label verification
- ARIA attribute validation
- Reading order analysis

---

## Supersedes

This consolidated feature supersedes:
- `issue-accessibility-heading-analysis.md`
- `issue-accessibility-link-text.md`

## Labels

`enhancement`, `ai`, `accessibility`, `editor`, `wcag`
