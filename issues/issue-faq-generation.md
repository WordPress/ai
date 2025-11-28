# FAQ Generation & Schema

## Overview

Automatically extract question/answer pairs from existing long-form content, generate concise answers, and insert them as FAQ blocks (with JSON-LD schema). This boosts search visibility and gives editors a repeatable way to add helpful Q&A sections.

## What problem does this address?

- Many evergreen guides implicitly answer common questions but never surface them in FAQ format.
- Google and other search engines reward FAQ schema, yet creating Q&A pairs manually is tedious.
- Editors have no bulk workflow to add FAQs across legacy posts.

## Proposed solution

1. **Question extraction** – AI analyzes a post to determine the top themes or implied questions.
2. **Answer summarization** – Create succinct answers (40–60 words) sourced from the post itself to avoid hallucinations.
3. **Block + schema output** – Insert a WordPress FAQ block (core or custom) and output matching structured data.
4. **Bulk generation & review queue** – Allow admins to select multiple posts, generate FAQs, and approve/reject suggestions before publishing.

## Example transformation

**Source content**
> WordPress ships with five roles: Administrator, Editor, Author, Contributor, Subscriber…

**Generated FAQ**
```
Q: What user roles does WordPress include?
A: WordPress includes five default roles—Administrator, Editor, Author, Contributor, and Subscriber—each with progressively fewer capabilities.

Q: What can Administrators do?
A: Administrators have full access to all site settings, user management, and plugin/theme controls.
```

## UI concept

```
FAQ Generator
Posts without FAQs: 156
[✓] WP User Roles Guide
[✓] Getting Started with Blocks
[ ] About Our Company

[Generate FAQs for selected]

Review queue
• WP User Roles Guide – Generated 3 Q&A pairs
  [Preview] [Edit] [Approve] [Reject]
```

## Integration points

- **Editor sidebar panel** – “Generate FAQ” button for single posts.
- **Bulk dashboard** – Admin page listing posts missing FAQs, with filters (post type, category, author).
- **Block insertion** – Insert at a configurable location (bottom of post by default) or copy to clipboard for manual placement.
- **Schema injection** – Output JSON-LD either inline or via `wp_head`.

## Settings

| Setting | Options | Default |
|---------|---------|---------|
| Enable FAQ generation | On/Off | On |
| Max questions per post | 3–10 | 5 |
| Minimum word count | Number | 500 |
| Include schema markup | On/Off | On |
| Auto-suggest on publish | On/Off | Off |
| FAQ block style | Core / Custom | Core |

## Technical considerations

- Deduplicate existing FAQs by matching question text before inserting new ones.
- Provide editing UI for answers before committing them to the block.
- Track generation history so editors can revert to previous versions.
- Rate limit API calls during bulk jobs and show progress with the ability to pause/cancel.

## Open questions

1. Should we support template-based FAQ presets (e.g., “pricing”, “shipping”) for ecommerce sites?
2. How do we ensure multilingual sites get FAQs in the correct language—auto-detect or rely on site language?
3. Do we allow mixing AI answers with manual ones inside the same block?

## Labels

`enhancement`, `ai`, `content`, `faq`, `seo`
