# Media AI Suite

## Overview

AI-powered enhancements for the WordPress Media Library, providing intelligent processing for images, audio, and video files.

## What problem does this address?

Media management in WordPress involves repetitive, time-consuming tasks:

**Image Cropping**
- Default center-crop cuts off faces and important subjects
- Manual re-cropping needed for each image size
- Featured images often miss the focal point

**Audio/Video Accessibility**
- Deaf/hard-of-hearing users can't access audio content
- Search engines can't index spoken content
- Creating transcripts manually is extremely time-consuming

**Content Consumption**
- Many users prefer or require audio versions of text content
- Visually impaired users benefit from audio
- Growing demand for podcast-style content consumption

## What is your proposed solution?

A "Media AI" experiment with three toggleable features that enhance the Media Library.

---

## Component 1: Smart Image Cropping

### Features
- **Subject Detection**: Identify faces, people, objects of interest
- **Smart Focal Point**: Automatically set crop center on detected subjects
- **Per-Size Optimization**: Different crops for different sizes based on aspect ratio
- **Manual Override**: Allow users to adjust AI suggestion

### How It Works
1. User uploads image with person off-center
2. AI detects face/subject as focal point
3. All generated sizes crop around the focal point
4. User can review in Media Library and adjust if needed

### Visual Example

```
Original Image (person on left side):
+------------------+
|  [face]          |
|    |             |
|   /|\            |
+------------------+

Default center crop (face cut off):    Smart crop (face preserved):
+--------+                              +--------+
|        |                              | [face] |
|        |                              |   |    |
+--------+                              +--------+
```

### Integration Points
- Media Library: Auto-apply on upload (configurable)
- Image Editor: "Smart Crop" button alongside manual crop
- Featured Image: Ensure subject is visible in all registered sizes
- Regenerate Thumbnails: Option to re-process existing images

### Technical Details
- Works with existing `add_image_size()` registrations
- Store focal point as image meta for regeneration
- Process asynchronously for large uploads
- Consider local/edge AI for privacy-sensitive images

---

## Component 2: Audio & Video Transcription

### Features
- **Auto-Transcription**: Generate text transcript from audio/video files
- **Timestamp Markers**: Include timestamps for navigation
- **Speaker Detection**: Identify different speakers (when possible)
- **Caption Generation**: Create SRT/VTT subtitle files for video

### Supported Formats
- Audio: MP3, WAV, OGG, M4A
- Video: MP4, WebM, MOV

### User Flow

**On Upload**
1. User uploads audio/video file
2. Option to auto-transcribe (based on settings)
3. Transcript generated and attached to media item

**Manual Generation**
1. User selects audio/video in Media Library
2. Clicks "Generate Transcript"
3. Progress indicator shows transcription status
4. Transcript appears in new meta field, editable by user

### Integration Points
- Media Library: "Generate Transcript" button on audio/video items
- Audio Block: Option to display transcript below player
- Video Block: Option to add generated captions
- Attachment page: Display transcript as accessible content

### Technical Details
- Large files need chunked processing
- Store transcript as post meta or attachment
- Consider Whisper API (OpenAI) or similar services
- Support for multiple languages

### WCAG Alignment
- 1.2.1 Audio-only and Video-only (Prerecorded)
- 1.2.2 Captions (Prerecorded)

---

## Component 3: Text-to-Speech

### Features
- **Audio Generation**: Convert post content to natural-sounding speech
- **Voice Selection**: Choose from available voices/styles
- **Audio Player**: Embed player at top of posts
- **Podcast Feed**: Optionally create RSS feed of audio versions

### User Flow
1. Author publishes or updates post
2. Option to generate audio (manual or automatic based on settings)
3. AI generates MP3 from post content
4. Audio file attached to post
5. Player appears on front-end (based on theme/block)

### Content Processing

Handle different content types appropriately:
| Content | Handling |
|---------|----------|
| Headings | Pause, emphasis |
| Lists | Enumeration |
| Quotes | Voice change (optional) |
| Code blocks | Skip or describe |
| Images | Read alt text |
| Links | Read link text, not URL |

### Integration Points
- Post editor: "Generate Audio" button in sidebar
- Front-end: Audio player block/shortcode
- Media Library: Store generated audio files
- Settings: Default voice, auto-generation toggle

### Technical Details
- File storage and serving (CDN considerations)
- Regeneration when post content changes
- Cost management for API-based TTS
- Support for multiple languages

---

## Combined UI: Media Library Enhancements

### Attachment Details Modal

```
+--Attachment Details------------------+
|                                      |
| [image preview]                      |
|                                      |
| Title: Team Photo                    |
| Alt Text: [___________________]      |
|                                      |
| +-- AI Tools ----------------------+ |
| |                                  | |
| | Focal Point: [Auto-detected ✓]  | |
| | [Adjust] [Reset to Center]       | |
| |                                  | |
| +----------------------------------+ |
|                                      |
+--------------------------------------+
```

### Audio/Video Attachment

```
+--Attachment Details------------------+
|                                      |
| [audio/video preview]                |
|                                      |
| Title: Interview Episode 5           |
|                                      |
| +-- AI Tools ----------------------+ |
| |                                  | |
| | Transcript: [Not generated]      | |
| | [Generate Transcript]            | |
| |                                  | |
| | Captions: [Not generated]        | |
| | [Generate Captions (VTT)]        | |
| |                                  | |
| +----------------------------------+ |
|                                      |
+--------------------------------------+
```

---

## Bulk Processing

For existing media libraries:

### Admin Dashboard

```
+--Media AI Tools----------------------+
|                                      |
| Smart Cropping                       |
| 234 images without focal points      |
| [Process All] [Select Images]        |
|                                      |
| Transcription                        |
| 45 audio/video files without transcripts |
| [Process All] [Select Files]         |
|                                      |
| Progress: ████████░░ 80%             |
| Processing: interview-ep-3.mp3       |
|                                      |
+--------------------------------------+
```

---

## Settings

### Smart Cropping
| Setting | Options | Default |
|---------|---------|---------|
| Enable smart cropping | On/Off | On |
| Auto-apply on upload | On/Off | On |
| Detection model | Face/Subject/Both | Both |
| Fallback to center | On/Off | On |

### Transcription
| Setting | Options | Default |
|---------|---------|---------|
| Enable transcription | On/Off | On |
| Auto-transcribe on upload | On/Off | Off |
| Include timestamps | On/Off | On |
| Speaker detection | On/Off | Off |
| Default language | Dropdown | Auto-detect |

### Text-to-Speech
| Setting | Options | Default |
|---------|---------|---------|
| Enable TTS | On/Off | Off |
| Auto-generate on publish | On/Off | Off |
| Default voice | Dropdown | System default |
| Include in RSS | On/Off | Off |
| Player position | Top/Bottom/Manual | Top |

---

## Technical Architecture

### Processing Queue
- Background job system for long-running tasks
- Progress tracking and cancellation
- Retry logic for failed items
- Rate limiting for API calls

### Storage
- Transcripts: Post meta on attachment
- Captions: Separate attachment file (VTT/SRT)
- Generated audio: New attachment linked to post
- Focal points: Image meta

### API Integration
- Configurable AI providers
- Fallback options for different providers
- Cost estimation before processing

---

## Open Questions

1. **Local vs. API**: Support local models for privacy-sensitive content?
2. **Cost Management**: How to handle API costs for bulk processing?
3. **Large Files**: Chunking strategy for long audio/video?
4. **Multi-language**: Per-file language detection or global setting?

---

## Supersedes

This consolidated feature supersedes:
- `issue-audio-video-transcription.md`
- `issue-text-to-speech.md`
- `issue-smart-image-cropping.md`

## Labels

`enhancement`, `ai`, `media`, `accessibility`, `transcription`, `tts`
