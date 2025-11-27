# Embeddings Platform

## Overview

A foundational AI infrastructure for WordPress that enables semantic understanding of site content through vector embeddings. This platform powers multiple consumer features: semantic search, related posts, and smart 404 recovery.

## What problem does this address?

WordPress's current content discovery relies on keyword matching:
- Search: "automobile" won't find posts about "cars"
- Related posts: Category/tag-based relations miss semantic connections
- 404 pages: Dead ends with no intelligent recovery

Users expect semantic understanding - finding content by meaning, not just keywords.

## What is your proposed solution?

Build a unified embeddings infrastructure with three consumer interfaces.

---

## Core Infrastructure

### Embedding Generation
- Generate vector embeddings for posts on publish/update
- Support configurable embedding models (OpenAI, local models, etc.)
- Batch processing for existing content
- Incremental updates on content changes

### Embedding Storage
Options to evaluate:
- **Post meta**: Simple, works everywhere, may not scale
- **Custom table**: Better query performance, still MySQL
- **Vector DB plugin**: Best performance, additional dependency

### Similarity Search
- Cosine similarity for finding related content
- Configurable similarity thresholds
- Caching layer for frequent queries
- REST API endpoints for headless use

---

## Consumer 1: Semantic Search

### Features
- **Semantic Matching**: Find content by meaning, not keywords
- **Typo Tolerance**: Handle misspellings gracefully
- **Natural Language Queries**: Support questions as search input
- **Relevance Ranking**: AI-determined result ordering
- **Hybrid Mode**: Combine semantic + keyword for best results

### How It Works
1. Content indexed with vector embeddings on publish
2. Search query converted to embedding
3. Cosine similarity finds semantically related content
4. Results ranked by relevance score

### Example Improvements

| Query | Keyword Search | Semantic Search |
|-------|---------------|-----------------|
| "car maintenance" | Posts with "car maintenance" | + Posts about "vehicle service", "auto repair" |
| "WP REST API" | Exact matches only | + "WordPress JSON endpoints", "headless WordPress" |
| "why is my site slow" | No results | Performance, caching, optimization posts |

---

## Consumer 2: Related Posts

### Features
- **Semantic Similarity**: Find related posts based on meaning, not just keywords
- **Related Posts Block**: Display AI-suggested related content
- **Editorial Suggestions**: Show related posts while writing (for internal linking)
- **REST API**: `GET /wp/v2/posts/{id}/related`

### Integration Points
- New "Related Posts" block for front-end display
- Editor sidebar: "Related Content" panel showing similar posts
- Settings: Number of suggestions, post types to include

### User Flow

**Front-end (Automatic)**
1. Related Posts block added to single post template
2. Block automatically fetches AI-determined related posts
3. Displays with configurable layout (list, grid, etc.)

**Back-end (Editorial)**
1. Author writing new post
2. Sidebar shows "Related Posts" as content is written
3. Author can click to insert internal link
4. Helps improve internal linking and content discovery

---

## Consumer 3: Smart 404 Recovery

### Features
- **URL Analysis**: Parse the requested URL for intent signals
- **Content Suggestions**: Show relevant posts based on URL/referrer
- **Search Integration**: Pre-filled search with extracted keywords
- **Redirect Suggestions**: For admins, suggest permanent redirects
- **Analytics**: Track 404 patterns to identify broken links

### How It Works
1. User hits `/blog/wordpress-6-features` (doesn't exist)
2. System extracts: "wordpress", "6", "features"
3. Converts to embedding, finds similar content
4. Displays helpful 404 page with suggestions

### Admin Dashboard
- Monitor frequent 404 URLs
- AI-suggested redirects: "/old-url → /new-url"
- One-click redirect creation

### 404 Page Template
```
+------------------------------------------+
|  Oops! Page Not Found                    |
|                                          |
|  We couldn't find "/blog/wp-features"    |
|                                          |
|  Were you looking for:                   |
|  +------------------------------------+  |
|  | → What's New in WordPress 6.9      |  |
|  | → Essential WordPress Features     |  |
|  | → WordPress Development Guide      |  |
|  +------------------------------------+  |
|                                          |
|  Or try searching:                       |
|  [ wordpress features        ] [Search]  |
+------------------------------------------+
```

---

## Technical Architecture

### Data Flow
```
Post Save/Update
       ↓
Extract text content (strip blocks, shortcodes)
       ↓
Generate embedding via AI provider
       ↓
Store embedding (meta/table/vector DB)
       ↓
Available for similarity queries
```

### API Endpoints
```
POST /wp/v2/embeddings/generate     # Generate embedding for post
GET  /wp/v2/posts/{id}/related      # Get related posts
GET  /wp/v2/search?semantic=true    # Semantic search
POST /wp/v2/embeddings/similar      # Find similar to arbitrary text
```

### Database Schema (if custom table)
```sql
CREATE TABLE wp_ai_embeddings (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    post_id BIGINT NOT NULL,
    embedding BLOB NOT NULL,          -- or JSON for portability
    model VARCHAR(100) NOT NULL,
    dimensions INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY post_model (post_id, model),
    KEY post_id (post_id)
);
```

---

## Performance Considerations

- **Index Size**: ~1.5KB per post for 1536-dimension embeddings
- **Query Speed**: Custom table with proper indexing, or vector DB for large sites
- **Caching**: Cache similarity results, invalidate on content change
- **Batch Processing**: Background jobs for initial indexing of existing content
- **Lazy Generation**: Generate embeddings on first query if missing

---

## Settings

| Setting | Options | Default |
|---------|---------|---------|
| Enable embeddings | On/Off | On |
| Embedding model | OpenAI/Local/Custom | OpenAI |
| Post types to index | Checkboxes | Posts, Pages |
| Auto-generate on publish | On/Off | On |
| Related posts count | 1-10 | 5 |
| Similarity threshold | 0-100% | 70% |
| Smart 404 | On/Off | On |
| Semantic search | On/Off | On |

---

## Open Questions

1. **Storage Strategy**: Post meta vs. custom table vs. vector DB?
2. **Model Selection**: Which embedding models to support out of box?
3. **Multilingual**: How to handle sites with multiple languages?
4. **Large Sites**: Performance at 10k, 100k, 1M+ posts?
5. **Privacy**: On-premise embedding generation option?

---

## Upstream Alignment

- Issue #37: MCP usage across features
- Issue #21: Layered tool pattern for abilities
- Roadmap: Foundation for multiple planned features

## Labels

`enhancement`, `ai`, `infrastructure`, `search`, `embeddings`
