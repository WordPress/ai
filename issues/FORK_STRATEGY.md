# Fork Contribution Strategy

This document outlines the approach for developing experimental AI features on this fork and contributing them back to the upstream WordPress/ai repository.

## Repository Setup

| Remote | Repository | Purpose |
|--------|------------|---------|
| `origin` | `Jameswlepage/ai` | Your fork - development happens here |
| `upstream` | `WordPress/ai` | Original project - PR target |

## Branch Strategy

### Integration Branch
- **`develop`** - Your "kitchen sink" branch where all features merge together
- Used for integration testing the full experience
- Not intended for direct PR to upstream

### Feature Branches
- Branch from `upstream/develop` for independent features
- Branch from parent feature branch for dependent features
- PR to upstream when ready

---

## Feature Consolidation

After analyzing the 18 proposed features, several should be consolidated due to overlapping infrastructure, UX patterns, or functionality.

### Consolidation 1: Embeddings Platform
**Merge into: `feature/embeddings-platform`**

| Original Issues | Rationale |
|-----------------|-----------|
| Semantic Search | Core embedding infrastructure |
| Related Posts | Consumes embeddings for similarity |
| Smart 404 | Consumes embeddings for content matching |

**Shared Infrastructure:**
- Vector embedding generation (on post save/update)
- Embedding storage (post meta, custom table, or vector DB)
- Similarity search functions
- Batch processing for existing content
- Index maintenance on CRUD

**Recommendation:** Build as a single cohesive feature with three consumer interfaces:
1. Search replacement/enhancement
2. Related Posts block + REST endpoint
3. 404 template integration

---

### Consolidation 2: Editor Writing Assistant
**Merge into: `feature/writing-assistant`**

| Original Issues | Rationale |
|-----------------|-----------|
| Suggestions Stream | Already supersedes Readability + Tone |
| Type-ahead Text (Ghost Text) | Same "real-time writing help" UX |
| Readability Analysis | Explicitly superseded |
| Tone Adjustment | Explicitly superseded |

**Shared Infrastructure:**
- Gutenberg sidebar (`PluginSidebar`)
- Session management (start/stop, timer, stats)
- Debounced content change detection
- AI streaming responses
- WordPress data store for state

**Recommendation:** Single "AI Writing Assistant" experiment with:
1. **Suggestions Stream** - sidebar with filterable suggestion feed
2. **Ghost Text** - inline type-ahead completions
3. Both share session context, settings, and AI infrastructure

**UX Consideration:** These are complementary, not competing:
- Suggestions Stream = passive, reviewable feedback
- Ghost Text = active, inline acceleration
- Users may want both, one, or neither

---

### Consolidation 3: Accessibility Suite
**Merge into: `feature/accessibility-suite`**

| Original Issues | Rationale |
|-----------------|-----------|
| Heading Structure Analysis | Document structure a11y |
| Link Text Improvement | Link a11y |

**Shared Infrastructure:**
- Editor sidebar panel ("Accessibility")
- Issue detection + inline highlighting
- Fix suggestions with one-click apply
- Pre-publish checks integration

**Recommendation:** Single "Accessibility Assistant" with tabbed interface:
- **Structure Tab**: Heading hierarchy, document outline
- **Links Tab**: Vague link detection, rewrite suggestions
- **Summary Tab**: Overall a11y score, issues count

**Future Expansion:** Alt text (already upstream issue #44), color contrast, etc.

---

### Consolidation 4: Media AI Suite
**Merge into: `feature/media-ai`**

| Original Issues | Rationale |
|-----------------|-----------|
| Audio/Video Transcription | Media library AI |
| Text-to-Speech | Media library AI |
| Smart Image Cropping | Media library AI |

**Shared Infrastructure:**
- Media Library UI enhancements
- Async processing with progress indication
- File attachment/meta storage
- Bulk processing for existing media

**Recommendation:** Single "Media AI" experiment with toggleable features:
1. **Transcription**: Generate transcripts for audio/video
2. **Text-to-Speech**: Generate audio from post content
3. **Smart Cropping**: AI focal point detection

**Note:** These are more loosely coupled than other consolidations. Could remain separate if preferred, but share admin UI patterns.

---

### Consolidation 5: Content Audit Tools
**Merge into: `feature/content-audit`**

| Original Issues | Rationale |
|-----------------|-----------|
| Content Freshness Audit | Bulk content analysis |
| FAQ Generation | Bulk content transformation |

**Shared Infrastructure:**
- Admin dashboard for bulk operations
- Post scanning/queuing system
- Review queue workflow
- Scheduled/background processing

**Recommendation:** "Content Tools" dashboard with:
1. **Freshness Audit**: Find outdated content, suggest updates
2. **FAQ Generator**: Generate FAQ blocks from existing posts

**Weaker Consolidation:** These share admin patterns but are functionally distinct. Could remain separate.

---

### Standalone Features (No Consolidation Needed)

These are sufficiently distinct to remain independent:

| Feature | Rationale |
|---------|-----------|
| **Post Table Bulk AI** | List table enhancement, tightly scoped |
| **Comment Moderation** | Distinct admin area, unique workflow |
| **Content Repurposing (Social)** | Post sidebar, distinct output format |
| **Form Response Summary** | Integrates with external plugins, unique data source |

---

## Final Feature List (Consolidated)

After consolidation, **18 issues become 9 features**:

| # | Feature | Type | Complexity |
|---|---------|------|------------|
| 1 | **Embeddings Platform** | Tier 0 (Foundation) | High |
| 2 | **Writing Assistant** | Tier 0 (Foundation) | High |
| 3 | **Accessibility Suite** | Tier 1 (Independent) | Medium |
| 4 | **Media AI Suite** | Tier 1 (Independent) | Medium |
| 5 | **Content Audit Tools** | Tier 1 (Independent) | Medium |
| 6 | **Post Table Bulk AI** | Tier 1 (Independent) | Low |
| 7 | **Comment Moderation** | Tier 1 (Independent) | Medium |
| 8 | **Content Repurposing** | Tier 1 (Independent) | Low |
| 9 | **Form Response Summary** | Tier 1 (Independent) | Medium |

---

## Dependency Graph

```
upstream/develop
│
├─── feature/embeddings-platform (Tier 0) ◄── HIGH PRIORITY
│    │
│    └─── Provides: Search, Related Posts, Smart 404
│
├─── feature/writing-assistant (Tier 0) ◄── HIGH PRIORITY
│    │
│    └─── Provides: Suggestions Stream, Ghost Text, Readability, Tone
│
├─── feature/accessibility-suite (Tier 1)
│    └─── Provides: Heading Analysis, Link Text
│
├─── feature/media-ai (Tier 1)
│    └─── Provides: Transcription, TTS, Smart Cropping
│
├─── feature/content-audit (Tier 1)
│    └─── Provides: Freshness Audit, FAQ Generation
│
├─── feature/post-table-bulk-ai (Tier 1)
│
├─── feature/comment-moderation (Tier 1)
│
├─── feature/content-repurposing (Tier 1)
│
└─── feature/form-response-summary (Tier 1)
```

---

## Implementation Sequence

### Phase 1: Quick Wins (Tier 1 Independents)
Start with lower-complexity independent features to:
- Establish contribution patterns with upstream
- Build familiarity with the codebase
- Deliver value quickly

**Recommended order:**
1. `feature/post-table-bulk-ai` - Extends existing Title Generation
2. `feature/content-repurposing` - Simple sidebar addition
3. `feature/accessibility-suite` - High value, moderate complexity

### Phase 2: Foundations (Tier 0)
Build the foundational infrastructure that enables advanced features:

1. `feature/embeddings-platform`
   - Start with embedding generation + storage
   - Add semantic search
   - Add related posts
   - Add smart 404

2. `feature/writing-assistant`
   - Start with suggestions stream sidebar
   - Add session management
   - Add ghost text / type-ahead

### Phase 3: Remaining Features
Complete the feature set:

1. `feature/media-ai` - Transcription, TTS, Smart Cropping
2. `feature/content-audit` - Freshness, FAQ Generation
3. `feature/comment-moderation`
4. `feature/form-response-summary`

---

## PR Strategy

### For Independent Features
```bash
# Create branch from upstream
git fetch upstream
git checkout -b feature/post-table-bulk-ai upstream/develop

# Develop the feature
# ... commits ...

# Push to your fork
git push origin feature/post-table-bulk-ai

# Open PR: origin/feature/post-table-bulk-ai → upstream/develop
```

### For Consolidated Features
Each consolidated feature may result in multiple PRs:

**Example: Embeddings Platform**
1. PR #1: Core embedding infrastructure (generation, storage, indexing)
2. PR #2: Semantic search (depends on #1)
3. PR #3: Related posts block (depends on #1)
4. PR #4: Smart 404 integration (depends on #1)

This allows upstream to review/merge incrementally.

### Integration Testing
```bash
# Merge all features into your develop for testing
git checkout develop
git merge feature/embeddings-platform
git merge feature/writing-assistant
git merge feature/accessibility-suite
# ... etc

# Test the integrated experience
# Your develop branch is your "preview" of the full vision
```

---

## Alignment with Upstream Roadmap

These features align with the WordPress/ai roadmap and open issues:

| Your Feature | Upstream Alignment |
|--------------|-------------------|
| Embeddings Platform | Issue #37 (MCP usage), #21 (abilities scaling) |
| Writing Assistant | Roadmap: Content Assistant |
| Accessibility Suite | Issue #44 (Alt Text), WCAG compliance goals |
| Media AI | Roadmap: Media Enhancement, Alt Text Generation |
| Content Audit | Roadmap: Workflow Automation |
| Post Table Bulk AI | Extends existing Title Generation experiment |

---

## Open Questions

1. **Embeddings Storage**: Local (post meta/custom table) vs. external vector DB?
   - Impacts scalability and hosting requirements
   - Should discuss with upstream before implementation

2. **MCP Integration**: Should new features use the layered tool pattern (#21)?
   - Would require implementing the adapter layer first (#37)
   - May be prerequisite for some features

3. **UI Consistency**: Should consolidated features share a unified settings page?
   - Or remain as separate experiments with own toggles?

4. **Breaking into PRs**: For large consolidated features, what's the right granularity?
   - Too small = overhead
   - Too large = hard to review

---

## Files to Update

When creating consolidated issues, archive or update these temp files:

### To Archive (superseded by consolidations)
- `temp/issue-semantic-search.md` → part of Embeddings Platform
- `temp/issue-related-posts.md` → part of Embeddings Platform
- `temp/issue-smart-404.md` → part of Embeddings Platform
- `temp/issue-suggestions-stream.md` → part of Writing Assistant
- `temp/issue-type-ahead-text.md` → part of Writing Assistant
- `temp/issue-readability-analysis.md` → superseded by Writing Assistant
- `temp/issue-tone-adjustment.md` → superseded by Writing Assistant
- `temp/issue-accessibility-heading-analysis.md` → part of Accessibility Suite
- `temp/issue-accessibility-link-text.md` → part of Accessibility Suite
- `temp/issue-audio-video-transcription.md` → part of Media AI Suite
- `temp/issue-text-to-speech.md` → part of Media AI Suite
- `temp/issue-smart-image-cropping.md` → part of Media AI Suite
- `temp/issue-content-freshness.md` → part of Content Audit Tools
- `temp/issue-faq-generation.md` → part of Content Audit Tools

### To Keep (standalone features)
- `temp/issue-post-table-bulk-ai.md`
- `temp/issue-comment-moderation.md`
- `temp/issue-content-repurposing.md`
- `temp/issue-form-response-summary.md`

---

## Next Steps

1. [ ] Review this strategy document
2. [ ] Decide on consolidation approach (accept/modify)
3. [ ] Create consolidated issue documents for each merged feature
4. [ ] Create feature branches
5. [ ] Begin Phase 1 implementation
