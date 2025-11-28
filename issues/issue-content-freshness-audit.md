# Content Freshness Audit

## Overview

An AI-powered dashboard that continuously scans published content for stale references, outdated statistics, broken external links, and other signals that a post needs maintenance. Editors receive prioritized review queues instead of manually auditing hundreds of posts.

## What problem does this address?

- Posts accumulate references to old years (“2022 study”), obsolete product versions, or time-sensitive language (“recently released”).
- External links rot over time, silently hurting UX and SEO.
- Large sites cannot realistically re-read every post on a fixed cadence.

## Proposed solution

1. **Automated scanning service** – Crawl selected post types on a schedule, using AI to detect dated language, version numbers, and fragile statements.
2. **Priority scoring** – Combine age, detected issues, traffic, and business tags to rank which posts need attention first.
3. **Actionable findings** – Surface specific sentences/lines to update, including reasons (e.g., “Link returns 404”).
4. **Review workflow** – Allow editors to mark an issue as resolved, snooze it, or jump straight to editing the post.

## Detection patterns

| Pattern | Example | Flag |
|---------|---------|------|
| Year references | “2021 survey results” | Potentially stale |
| Relative time | “Recently released”, “Last month” | Needs context |
| Version numbers | “WordPress 5.8” | Check against latest |
| Statistics | “45% of sites … (2020)” | Suggest verification |
| Seasonal content | “This summer we’re launching…” | Out-of-season |
| External links | 4xx/5xx responses | Link rot |

## Dashboard concept

```
Content Freshness Audit
Filters: [All] [High priority] [Date range]  Post types: [Posts ✓][Pages ✓]

Priority │ Post                 │ Issues │ Age
🔴 High  │ WP 5 Guide           │ 4      │ 2y
🟡 Med   │ SEO Tips             │ 2      │ 1y
🟢 Low   │ About Us             │ 1      │ 6m

Selected: WP 5 Guide
Issues found:
• Line 12 – “WordPress 5.9” (latest is 6.9)
• Line 45 – “2022 survey data”
• Line 78 – Link returns 404
• Line 92 – “recently released”
[Edit post] [Mark reviewed]
```

## Scheduling & notifications

- Configurable cadence (daily / weekly / monthly scans).
- Incremental processing (only rescans changed or high-priority posts).
- Optional email digest summarizing newly flagged content.
- Manual “Scan now” action for urgent audits.

## Settings

| Setting | Options | Default |
|---------|---------|---------|
| Enable freshness audit | On/Off | On |
| Post types to scan | Checkboxes | Posts |
| Minimum age before scanning | 3m / 6m / 1y / 2y | 1 year |
| External link checking | On/Off | On |
| Email digest | Off / Weekly / Monthly | Weekly |
| Evergreen tag to skip | Tag selector | `evergreen` |

## Bulk actions

- Run a full site scan (with progress meter and cancellation).
- Bulk-mark reviewed items when a team finishes updates.
- Export CSV/PDF reports for stakeholders.

## Technical architecture

- Use WP Cron or Action Scheduler to queue scans in batches (e.g., 50 posts per job).
- Store findings as structured post meta so the review UI can query efficiently.
- Integrate with AI providers for language analysis; fall back to heuristics for links/version checks.
- Log scan history for auditing and to avoid duplicate notifications.

## Open questions

1. Should we integrate directly with SEO plugins (Yoast/Rank Math) for shared signals?
2. How aggressive should notifications be—only when high severity issues are found?
3. Can we auto-fix trivial items (e.g., updating current year references) or should everything remain manual?

## Labels

`enhancement`, `ai`, `content`, `audit`, `seo`
