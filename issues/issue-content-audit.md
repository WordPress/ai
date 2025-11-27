# Content Audit Tools

## Overview

AI-powered tools for auditing and enhancing existing content at scale, helping site owners maintain content quality and SEO performance across large content libraries.

## What problem does this address?

Websites accumulate content debt over time:

**Freshness Issues**
- Statistics and data become stale
- External links break
- "This year" references age poorly
- Technical content becomes inaccurate
- Manually auditing hundreds of posts is impractical

**Missing Enhancements**
- Posts lack FAQ sections (missing SEO opportunity)
- No structured data for rich snippets
- Content could be repurposed but isn't
- Optimization opportunities go unnoticed

## What is your proposed solution?

A "Content Audit Tools" dashboard with AI-powered analysis and bulk transformation capabilities.

---

## Component 1: Content Freshness Audit

### Features
- **Staleness Detection**: Identify posts likely containing outdated information
- **Date Reference Scanning**: Find "2023", "last year", "recently" type phrases
- **Fact Checking Flags**: Flag statistics that may need verification
- **Update Recommendations**: Suggest what specifically needs updating
- **Priority Scoring**: Rank posts by update urgency
- **Broken Link Detection**: Find 4xx/5xx errors in external links

### Detection Patterns

| Pattern | Example | Flag |
|---------|---------|------|
| Year references | "2023 statistics" | Outdated year |
| Relative time | "recently", "just released" | May be stale |
| Version numbers | "WordPress 5.x" | Check current version |
| Dated statistics | "45% of users (2022)" | Needs verification |
| Seasonal content | "This summer..." | Out of season |
| Broken links | External URL returns 404 | Link rot |

### Dashboard View

```
+--Content Freshness Audit-------------+
|                                      |
| Filter: [All] [High Priority] [Date] |
| Post Types: [Posts ✓] [Pages ✓]      |
|                                      |
| +--------------------------------+   |
| | Priority | Post | Issues | Age |   |
| |----------|------|--------|-----|   |
| | 🔴 High  | WP 5 Guide | 4 | 2y |   |
| | 🟡 Med   | SEO Tips   | 2 | 1y |   |
| | 🟢 Low   | About Us   | 1 | 6m |   |
| +--------------------------------+   |
|                                      |
| Selected: WP 5 Guide                 |
| +--------------------------------+   |
| | Issues Found:                  |   |
| | • Line 12: "WordPress 5.9"     |   |
| |   Current version is 6.9       |   |
| | • Line 45: "2022 survey data"  |   |
| |   May need updated stats       |   |
| | • Line 78: Link returns 404    |   |
| | • Line 92: "recently released" |   |
| |   Posted 2 years ago           |   |
| |                                |   |
| | [Edit Post] [Mark Reviewed]    |   |
| +--------------------------------+   |
|                                      |
+--------------------------------------+
```

### User Flow

1. Site owner opens "Content Freshness" dashboard
2. Sees list of posts flagged for review, sorted by priority
3. Clicks post to see specific issues with line numbers
4. Makes updates or marks as "still accurate"
5. Post removed from review queue

### Scheduling
- Configurable scan frequency (weekly/monthly)
- Incremental analysis (only re-scan changed content)
- Email digest of new issues found

---

## Component 2: FAQ Generation

### Features
- **Question Extraction**: Identify implicit questions content answers
- **Answer Summarization**: Create concise answers from content
- **FAQ Block Generation**: Create ready-to-use FAQ block
- **Schema Markup**: Automatic FAQ schema (JSON-LD)
- **Bulk Generation**: Add FAQs to existing posts

### How It Works
1. AI analyzes post content
2. Identifies topics that could be phrased as questions
3. Generates Q&A pairs from the content
4. Outputs as FAQ block with schema markup

### Example Transformation

**Source Content:**
> WordPress supports multiple user roles including Administrator, Editor, Author, Contributor, and Subscriber. Administrators have full access to all settings. Editors can manage and publish posts from all users. Authors can only manage their own posts.

**Generated FAQ:**
```
Q: What user roles does WordPress support?
A: WordPress includes five default roles: Administrator, Editor,
   Author, Contributor, and Subscriber.

Q: What can WordPress Administrators do?
A: Administrators have full access to all WordPress settings
   and capabilities.

Q: What's the difference between Editor and Author roles?
A: Editors can manage and publish posts from all users, while
   Authors can only manage their own posts.
```

### Dashboard View

```
+--FAQ Generator-----------------------+
|                                      |
| Posts without FAQs: 156              |
| Posts with FAQs: 23                  |
|                                      |
| Select posts to generate FAQs:       |
| +--------------------------------+   |
| | [✓] WordPress User Roles Guide |   |
| | [✓] Getting Started with Blocks|   |
| | [ ] About Our Company          |   |
| | [✓] Plugin Development 101     |   |
| +--------------------------------+   |
|                                      |
| [Generate FAQs for Selected]         |
|                                      |
| +-- Review Queue -----------------+  |
| | WP User Roles Guide             |  |
| | Generated 3 Q&A pairs           |  |
| | [Preview] [Edit] [Approve]      |  |
| +--------------------------------+   |
|                                      |
+--------------------------------------+
```

### User Flow

**Single Post**
1. Author finishes writing guide
2. Clicks "Generate FAQ" in sidebar
3. AI produces Q&A pairs
4. Author reviews, edits, inserts FAQ block

**Bulk Processing**
1. Admin opens "Generate FAQs" tool
2. Selects posts to process
3. FAQs generated in batch
4. Review queue for approval before publishing changes

### Technical Details
- FAQ block compatibility (core FAQ block or custom)
- Schema output in head or inline JSON-LD
- Maximum questions per post (Google guidelines: ~10)
- Duplicate detection (don't repeat site-wide FAQs)
- Answer length optimization (featured snippet friendly: 40-60 words)

---

## Combined Dashboard

```
+--Content Audit Tools-----------------+
| [Freshness] [FAQs] [Bulk Actions]    |
|--------------------------------------|
|                                      |
| Quick Stats:                         |
| +----------+ +----------+ +--------+ |
| | 23       | | 156      | | 12     | |
| | Need     | | Missing  | | Broken | |
| | Review   | | FAQs     | | Links  | |
| +----------+ +----------+ +--------+ |
|                                      |
| Recent Activity:                     |
| • 5 posts marked as reviewed today   |
| • 3 FAQs generated and approved      |
| • Next scheduled scan: Tomorrow 3am  |
|                                      |
+--------------------------------------+
```

---

## Bulk Actions

### Available Actions
- **Bulk Freshness Scan**: Analyze all posts for staleness
- **Bulk FAQ Generation**: Generate FAQs for multiple posts
- **Bulk Mark Reviewed**: Clear review flags
- **Export Report**: CSV/PDF of audit findings

### Progress Tracking

```
+--Bulk Operation Progress-------------+
|                                      |
| Generating FAQs...                   |
| ████████████░░░░░░░░ 60%            |
|                                      |
| Processed: 45 / 75 posts             |
| FAQs Generated: 127                  |
| Skipped (too short): 3               |
| Errors: 0                            |
|                                      |
| Currently: "Advanced Block Patterns" |
|                                      |
| [Pause] [Cancel]                     |
+--------------------------------------+
```

---

## Settings

### Freshness Audit
| Setting | Options | Default |
|---------|---------|---------|
| Enable freshness audit | On/Off | On |
| Scan frequency | Daily/Weekly/Monthly | Weekly |
| Post types to scan | Checkboxes | Posts |
| Flag posts older than | 3m/6m/1y/2y | 1 year |
| Check external links | On/Off | On |
| Email digest | Off/Weekly/Monthly | Weekly |
| Evergreen tag | Tag to exclude | "evergreen" |

### FAQ Generation
| Setting | Options | Default |
|---------|---------|---------|
| Enable FAQ generation | On/Off | On |
| Max questions per post | 3-10 | 5 |
| Minimum post length | Words | 500 |
| Include schema markup | On/Off | On |
| Auto-suggest on publish | On/Off | Off |
| FAQ block style | Core/Custom | Core |

---

## Technical Architecture

### Scanning System
- WP Cron for scheduled scans
- Background processing for bulk operations
- Incremental scanning (track last_scanned date)
- Priority queue based on post age and traffic

### Storage
- Audit results: Custom post meta
- Review status: Post meta flag
- Generated FAQs: Draft content until approved
- Scan history: Custom table or options

### Performance
- Batch processing (50 posts at a time)
- Rate limiting for AI API calls
- Caching of audit results
- Lazy loading in dashboard

---

## Open Questions

1. **Integration**: Should this integrate with SEO plugins (Yoast, RankMath)?
2. **Notifications**: How aggressive should staleness notifications be?
3. **Automation**: Auto-update obvious things (year references)?
4. **Analytics**: Integrate with traffic data to prioritize high-value posts?

---

## Supersedes

This consolidated feature supersedes:
- `issue-content-freshness.md`
- `issue-faq-generation.md`

## Labels

`enhancement`, `ai`, `content`, `audit`, `seo`, `admin`
