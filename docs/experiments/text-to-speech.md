# Text to Speech

## Summary

The Text to Speech experiment generates an audio version of a post's content so visitors can listen to the post instead of reading it. Generation is triggered from a "Text to Speech" panel in the block editor sidebar, runs in the background via WP-Cron (so it continues even if the editor is closed), and stores the result as an audio attachment on the post. When enabled per post, an audio player is rendered above the content on the singular front-end view. The experiment also registers two reusable WordPress Abilities — `ai/speech-generation` and `ai/speech-import` — that mirror the image generation ability pair.

## Overview

### For End Users

When enabled, a "Text to Speech" panel appears in the document sidebar of the block editor:

- **Generate Audio** starts background generation from the post's saved content. The button becomes **Regenerate Audio** once audio exists; regenerating deletes the current audio and creates a new version.
- Progress is shown while generation runs ("Generating audio… (2 of 5)"). You can close the editor — generation continues on the server.
- Once generated, an inline preview player appears, along with a **Display audio player on the front end** toggle (on by default; persisted when the post is saved).
- On the front end, a native audio player is rendered above the post content on the singular view.

**Key Features:**

- One-click audio generation from a post's title and content
- Background processing — generation survives page refreshes and closed tabs
- Long content is split into chunks (to respect provider request limits) and combined into a single MP3
- Per-post front-end display toggle
- Explicit regeneration control — audio is only replaced when you ask for it

## Architecture & Implementation

- `register()` wires: `wp_abilities_api_init` (abilities), `rest_api_init` (job trigger/status routes), `enqueue_block_editor_assets` / `enqueue_block_assets` (assets), the `wpai_tts_process_chunk` cron hook (one content chunk per event), and a `the_content` filter (front-end player, guarded by `is_singular()` / `in_the_loop()` / `is_main_query()` so player markup never leaks into REST responses or AI context building).
- The post title is prepended to the body so the audio announces it first, then the combined text is normalized (`normalize_content()` after `the_content`), split into ≤ 4,000-character sentence-boundary chunks, generated chunk-by-chunk as `audio/mpeg` (`Speech_Generator`, the single place the AI client is called), appended to a temp file in the uploads directory (ID3 tags stripped at joins), and finally imported via `media_handle_sideload()` as an attachment of the post (`wpai_generated` meta = 1). The previous attachment is deleted only after the new one exists.
- Job state lives in post meta. Only the display toggle is exposed to REST; the editor reads everything else through the status endpoint, so a stale editor save can never clobber job state.

### Post meta

| Key | Purpose |
| --- | --- |
| `wpai_tts_audio_id` | Generated audio attachment ID |
| `wpai_tts_display_audio` | Front-end display toggle (REST-exposed, default true) |
| `wpai_tts_status` | `pending` / `processing` / `complete` / `error` |
| `wpai_tts_error` | Last error message |
| `wpai_tts_updated` | Last activity timestamp (stuck-job detection) |
| `wpai_tts_job` | Transient job blob (chunks, progress, temp file); removed on completion |

Generated audio attachments are flagged with `wpai_generated` = 1.

### Settings

- **Voice** (`wpai_feature_text-to-speech_field_voice`): optional voice identifier passed to the provider (`as_output_speech_voice()`); empty uses the provider default.
- The standard per-feature developer provider/model override is honored.

### REST Endpoints

Start (or restart) background generation for a post:

    curl -X POST --user admin:password \
      https://example.com/wp-json/ai/v1/text-to-speech/123

Poll status:

    curl --user admin:password \
      https://example.com/wp-json/ai/v1/text-to-speech/123

Both return `{ "status", "done", "total", "error", "audio_id", "audio_url", "display_audio" }`.

### Abilities (REST examples)

Generate speech synchronously (from text, or pass `post_id` instead):

    curl -X POST --user admin:password \
      https://example.com/wp-json/wp-abilities/v1/abilities/ai/speech-generation/run \
      -H 'Content-Type: application/json' \
      -d '{"input": {"text": "Hello world."}}'

Import base64 audio into the media library:

    curl -X POST --user admin:password \
      https://example.com/wp-json/wp-abilities/v1/abilities/ai/speech-import/run \
      -H 'Content-Type: application/json' \
      -d '{"input": {"data": "<base64>", "mime_type": "audio/mpeg", "title": "My audio", "post_id": 123}}'

Note: `ai/speech-generation` runs synchronously in a single request — long content can be slow. The editor's background flow (REST endpoints above) is the recommended path for whole posts.

### Filters

| Filter | Purpose |
| --- | --- |
| `wpai_tts_max_chunk_length` | Maximum characters per chunk (default 4000) |
| `wpai_tts_pre_generate_chunk` | Short-circuit chunk generation (return `{data: base64, mime_type?: string}` or `WP_Error`) |
| `wpai_tts_audio_filename` | Base filename of audio imported by the background job (default `post-audio-{ID}`) |
| `wpai_generated_audio_filename` | Base filename of audio imported via `ai/speech-import` |
| `wpai_tts_player_markup` | Front-end player markup |
| `wpai_has_text_to_speech_support` | Override TTS capability detection |
| `wpai_preferred_speech_models` | Provider/model preference list for TTS |

## Limitations

- Requires a connector with a working text to speech model.
- Chunk joins are plain MP3 concatenation: not guaranteed gapless, and multi-chunk jobs require MP3 output.
- Generation reads the post's **saved** content; the editor blocks the button while there are unsaved changes.
- WP-Cron scheduling depends on site traffic; the editor's status polling keeps it moving while the editor is open. On very low-traffic sites with the editor closed, generation may pause until the next request arrives (or a real cron runner is configured).
