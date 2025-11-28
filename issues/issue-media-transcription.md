# Audio & Video Transcription

## Overview

Generate transcripts and caption files for audio/video uploads directly inside the Media Library. This improves accessibility, SEO, and editorial workflows by making spoken content searchable and editable.

## What problem does this address?

- Deaf/hard-of-hearing audiences cannot access audio content without transcripts.
- Search engines and on-site search cannot index spoken words.
- Manually transcribing podcasts, webinars, or interviews is time-consuming and expensive.

## Proposed solution

1. **Auto-transcription pipeline** – When an audio/video file is uploaded (or when the user requests it), send it through an AI speech-to-text service.
2. **Transcript storage** – Save the raw text plus timestamps as attachment meta so editors can edit it inline.
3. **Caption generation** – Offer downloadable `.srt` / `.vtt` files for use in the core video block and external players.
4. **Block integration** – Surface a “Display transcript” toggle in audio/video blocks and optionally append transcripts to attachment pages.

## Supported formats

- Audio: MP3, WAV, OGG, M4A
- Video: MP4, WebM, MOV

## User flow

### On upload
1. Media item is uploaded.
2. Settings determine whether to auto-transcribe.
3. Background job processes the file (chunking when required).
4. Transcript + caption artifacts attach to the media item; user receives a notice when complete.

### Manual generation
1. Editor opens an audio/video attachment in the Media Library.
2. Clicks “Generate transcript” (and optionally “Generate captions”).
3. Progress indicator shows queue status and estimated time.
4. Results appear in a new “Transcript” panel with edit + copy controls.

## UI outline

```
Attachment details
---------------------------------
| Preview player                |
|                               |
| AI Tools                      |
|  Transcript: Not generated    |
|  [Generate transcript]        |
|  Captions: Not generated      |
|  [Generate VTT]               |
|                               |
| Transcript (editable textarea)|
---------------------------------
```

## Integration points

- **Media Library** – Buttons + status indicators per attachment.
- **Audio/Video blocks** – Add inspector controls to toggle transcript display or select a caption file.
- **Attachment pages** – Surface transcript for accessibility + SEO.
- **REST API** – Provide endpoints so headless sites can fetch transcripts/captions.

## Settings

| Setting | Options | Default |
|---------|---------|---------|
| Enable transcription | On/Off | On |
| Auto-transcribe on upload | On/Off | Off |
| Include timestamps | On/Off | On |
| Speaker detection | On/Off | Off |
| Default language | Dropdown / Auto-detect | Auto |
| Storage | Attachment meta / Custom table | Attachment meta |

## Technical considerations

- Large files require chunked uploads to the transcription provider (Whisper API, etc.).
- Consider queueing via Action Scheduler and displaying progress.
- Provide hooks for sites that already store transcripts elsewhere.
- WCAG alignment: satisfies 1.2.1 and 1.2.2 requirements when paired with captions.

## Bulk processing dashboard

```
Transcription jobs
Media needing transcripts: 45
[Process all] [Select files]
Progress: ██████░░░░ 60%
Currently: interview-ep-03.mp3
Errors: 0  |  Skipped: 2 (shorter than threshold)
```

## Open questions

1. Do we store edited transcripts as revisions so editors can roll back?
2. Should we expose cost estimates before processing large batches?
3. How do we alert users when background jobs fail (admin notice vs. email)?

## Labels

`enhancement`, `ai`, `media`, `transcription`, `accessibility`
