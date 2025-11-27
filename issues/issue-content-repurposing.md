# Content Repurposing (Social Media Generation)

## What problem does this address?

Content creators write blog posts but then need to manually create:
- Twitter/X threads
- LinkedIn posts
- Facebook updates
- Instagram captions
- Newsletter snippets

This is time-consuming and requires different writing styles for each platform.

## What is your proposed solution?

Generate platform-specific content from existing posts:

### Core Features
- **Social Post Generation**: Create platform-optimized versions of content
- **Thread Generation**: Break long content into tweet-sized threads
- **Hashtag Suggestions**: Relevant hashtags for each platform
- **Character Limit Compliance**: Respect platform limits automatically
- **Multiple Variations**: Generate options to choose from

### Supported Formats
- Twitter/X: Single post or thread with character limits
- LinkedIn: Professional tone, longer format
- Facebook: Engaging, conversational
- Instagram: Caption with hashtags and emoji
- Newsletter: Email-friendly summary

## User Flow

1. Author finishes writing blog post
2. Clicks "Generate Social Posts" in sidebar
3. Selects target platforms
4. AI generates tailored versions for each
5. Author reviews, edits, copies to clipboard
6. (Future: direct publishing integration)

## Technical Considerations

- Store generated content as post meta for reuse
- Track character counts in real-time
- Platform-specific emoji and formatting guidelines
- Consider scheduling integration (future)

## Example Output

**Original Post**: 2000 word article about WordPress 6.9 features

**Twitter Thread**:
```
1/5 WordPress 6.9 just dropped and it's a game-changer. Here's what you need to know about the Abilities API:

2/5 The Abilities API lets AI assistants understand what your site can do. Think of it as a menu of actions AI can take.

3/5 ...
```

**LinkedIn**:
```
Excited to share my thoughts on WordPress 6.9's new AI capabilities.

The Abilities API represents a fundamental shift in how we think about CMS functionality...
```
