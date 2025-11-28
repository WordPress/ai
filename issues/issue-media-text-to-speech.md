# Text-to-Speech Publishing

## Overview

Convert long-form posts and pages into natural-sounding audio that can be embedded at the top of the content, added to RSS feeds, or distributed as a lightweight podcast.

## What problem does this address?

- Readers increasingly want audio versions of articles for accessibility or multitasking.
- Producing narrated audio manually requires time, voice talent, and post-production.
- Sites with accessibility mandates need parity between textual and audio experiences.

## Proposed solution

1. **Audio generation service** – Transform post content (or selected excerpts) into MP3/OGG files via provider APIs (OpenAI, ElevenLabs, AWS Polly, etc.).
2. **Voice + style presets** – Offer a curated list of voices with tone/style settings (professional, conversational, energetic…).
3. **Automatic attachment + block** – Store the generated audio as a media attachment, associate it with the post, and optionally inject an audio player block at a configurable location.
4. **Podcast feed** – Expose an optional RSS feed that aggregates the generated audio files for syndication.

## User flow

1. Author publishes/updates a post.
2. Sidebar control (or post-publish panel) offers “Generate audio”.
3. If auto-generation is enabled, a background job creates the audio file.
4. Once complete, an inline notification confirms success and the audio player appears on the post (or the author can insert it manually).

### Content handling

| Content type | Behavior |
|--------------|----------|
| Headings | Add subtle pauses/emphasis. |
| Lists | Insert numbering cues. |
| Quotes | Optional tone change or “quote” indicator. |
| Code blocks | Skip, summarize, or spell out (configurable). |
| Images | Read alt text if available. |
| Links | Read link text only (“Learn more about blocks”). |

## Integration points

- **Editor sidebar** – Controls for voice selection, tone, auto-generation toggle.
- **Frontend** – Core audio block (or custom player) placed at top/bottom via template or block pattern.
- **Media Library** – Generated audio stored like any other attachment with metadata referencing the source post.
- **RSS** – New feed endpoint (e.g., `/feed/audio`) that lists generated files for podcast apps.

## Settings

| Setting | Options | Default |
|---------|---------|---------|
| Enable text-to-speech | On/Off | Off |
| Auto-generate on publish | On/Off | Off |
| Default voice preset | List of provider voices | System default |
| Insert player at | Top / Bottom / Manual | Top |
| Include in RSS feed | On/Off | Off |
| Maximum length processed | Word count threshold | 3,000 words |

## Technical considerations

- Need to handle regeneration when content changes (diff detection vs. manual trigger).
- Store provider + settings metadata alongside the generated file for reproducibility.
- Manage API costs by batching requests, showing estimates, or warning when long articles exceed quotas.
- Provide hooks/filters so hosts can inject their own provider credentials.

## Open questions

1. Should we support multiple voices per site (per post override vs. global default)?
2. How do we handle multilingual sites—auto-detect language or let authors choose?
3. Do we cache intermediate SSML so we can re-send to providers without reprocessing content?

## Labels

`enhancement`, `ai`, `media`, `tts`, `accessibility`
