# Text to Speech

## Summary

The Text to Speech experiment generates an audio version of a post's content so visitors can listen to the post instead of reading it. Generation is triggered from a "Text to Speech" panel in the block editor sidebar, runs in the background via WP-Cron (so it continues even if the editor is closed), and stores the result as an audio attachment on the post. When enabled per post, an audio player is rendered above the content on the singular front-end view. The experiment also registers a reusable `ai/speech-generation` WordPress Ability.

## At a Glance

- **Type:** Experiment (may change or be removed in any release; try it on a staging site first)
- **Runs without a user action:** No. Generation only starts when you click Generate Audio or Regenerate Audio. Once started, it keeps running in the background through WP-Cron, even if you close the editor
- **When it runs:** When you click Generate Audio or Regenerate Audio in the Text to Speech panel
- **Sent to your AI provider:** The post title and the saved post content as plain text, plus the voice chosen in the Voice setting
- **Requests and data:** About one request per 4,000 characters of title and content. Chunks break at sentence boundaries and are capped by `wpai_tts_max_chunk_length`, so a 10,000-character post makes three or more requests. The audio is saved to the Media Library as an MP3 attached to the post
- **Good to know:** The front-end audio player is on by default, so visitors can hear the audio as soon as it's generated on a published post. Turn off **Display audio player on the front end** in the panel to hide it. Regenerating replaces the current audio file.

## Overview

### For End Users

When enabled, a "Text to Speech" panel appears in the document sidebar of the block editor:

- **Generate Audio** starts background generation from the post's saved content. The button becomes **Regenerate Audio** once audio exists; regenerating deletes the current audio and creates a new version.
- Progress is shown while generation runs ("Generating audio… (2 of 5)"). You can close the editor — generation continues on the server.
- Once generated, an inline preview player appears, along with a **Display audio player on the front end** toggle (on by default; persisted when the post is saved).
- **Delete Audio** removes the generated audio and clears all of the post's text to speech state, returning the post to its pre-generation state. It asks for confirmation first, since the action cannot be undone.
- On the front end, a native audio player is rendered above the post content on the singular view.

**Key Features:**

- One-click audio generation from a post's title and content
- Background processing — generation survives page refreshes and closed tabs
- Long content is split into chunks (to respect provider request limits) and combined into a single MP3
- Per-post front-end display toggle
- Explicit regeneration control — audio is only replaced when you ask for it
- Explicit deletion — remove the audio and its state without regenerating

## Architecture & Implementation

- `register()` wires: `wp_abilities_api_init` (abilities), `rest_api_init` (job trigger/status/delete routes), `enqueue_block_editor_assets` (editor script and panel styles), `wp_enqueue_scripts` (player styles, only on singular views that render the player), the `wpai_tts_process_chunk` cron hook (one content chunk per event), and a `the_content` filter (front-end player, guarded by `is_singular()` / `in_the_loop()` / `is_main_query()` so player markup never leaks into REST responses or AI context building).
- The post title is prepended to the body so the audio announces it first, then the combined text is normalized (`normalize_content()` after `the_content`), split into ≤ 4,000-character sentence-boundary chunks, generated chunk-by-chunk as `audio/mpeg` (`Speech_Generator`, the single place the AI client is called), appended to a temp file in the uploads directory (ID3 tags stripped at joins), and finally imported via `media_handle_sideload()` as an attachment of the post (`wpai_generated` meta = 1). The previous attachment is deleted only after the new one exists.
- Job state lives in post meta. Only the display toggle is exposed to REST; the editor reads everything else through the status endpoint. The job-state keys are underscore-prefixed (protected), so they never appear in the Custom Fields meta box and a stale editor save can never clobber them.
- While a post has a password, its audio attachment has `private` status, so core's REST media endpoints and attachment-ID redirects never reveal the file to visitors (core otherwise lets anyone read attachments of a published, password-protected post). The status is set on import and kept in sync on `post_updated` when the password is added or removed. The front-end player is likewise only rendered once the visitor has entered the password. On a post that is not public (draft, scheduled, pending, or private), the player is only rendered for users who can edit the post, so they can check it before publishing.
- Each chunk event holds a per-post `Background\Lock` (`tts_job_{ID}`), so two cron runners can never process the same job at once; an event that finds the lock taken reschedules itself 30 seconds later. Each job also carries a random token, and a chunk whose job was expired, restarted, or deleted while its provider call was in flight is discarded instead of being written into the new job. Events are scheduled through `Background\Scheduler::schedule_at()`.

### Post meta

| Key | Purpose |
| --- | --- |
| `_wpai_tts_audio_id` | Generated audio attachment ID |
| `wpai_tts_display_audio` | Front-end display toggle (REST-exposed, default true) |
| `_wpai_tts_status` | `pending` / `processing` / `complete` / `error` |
| `_wpai_tts_error` | Last error message |
| `_wpai_tts_updated` | Last activity timestamp (stuck-job detection) |
| `_wpai_tts_job` | Transient job blob (chunks, progress, temp file); removed on completion |

Generated audio attachments are flagged with `wpai_generated` = 1.

### Settings

- **Voice** (`wpai_feature_text-to-speech_field_voice`): optional voice identifier passed to the provider (`as_output_speech_voice()`).
  - A provider can declare the voices it supports as `supportedValues` on `SupportedOption( OptionEnum::outputSpeechVoice() )`. When it does, `Voice_Resolver` reads them off the resolved model's metadata and the field renders as a select; a previously saved voice that is no longer offered stays selectable but is labeled as such.
  - When the setting is empty, the default is resolved once per job — the first declared voice, then the `wpai_tts_default_voice` filter — and frozen into the job so every chunk of a multi-chunk post uses the same voice.
- The standard per-feature developer provider/model override is honored.

### REST Endpoints

Start (or restart) background generation for a post:

    curl -X POST --user admin:password \
      https://example.com/wp-json/ai/v1/text-to-speech/123

Poll status:

    curl --user admin:password \
      https://example.com/wp-json/ai/v1/text-to-speech/123

Delete the generated audio and clear all text to speech state for a post:

    curl -X DELETE --user admin:password \
      https://example.com/wp-json/ai/v1/text-to-speech/123

All three return `{ "status", "done", "total", "error", "audio_id", "audio_url", "display_audio" }`; after a delete the payload reports the idle, no-audio state.

### Abilities (REST examples)

Generate speech synchronously (from text, or pass `post_id` instead):

    curl -X POST --user admin:password \
      https://example.com/wp-json/wp-abilities/v1/abilities/ai/speech-generation/run \
      -H 'Content-Type: application/json' \
      -d '{"input": {"text": "Hello world."}}'

Note: `ai/speech-generation` runs synchronously in a single request — long content can be slow. The editor's background flow (REST endpoints above) is the recommended path for whole posts.

### Filters

| Filter | Purpose |
| --- | --- |
| `wpai_tts_max_chunk_length` | Maximum characters per chunk (default 4000) |
| `wpai_tts_pre_generate_chunk` | Short-circuit chunk generation (return `{data: base64, mime_type?: string}` or `WP_Error`; `mime_type` must be an `audio/*` type). The fourth argument is the MIME type required: `audio/mpeg` when the post is generated in several chunks, otherwise empty |
| `wpai_tts_audio_filename` | Base filename of audio imported by the background job (default `post-audio-{ID}-{random}`, so the URL cannot be guessed from the post ID) |
| `wpai_tts_player_markup` | Front-end player markup |
| `wpai_has_text_to_speech_support` | Override TTS capability detection |
| `wpai_preferred_speech_models` | Provider/model preference list for TTS |
| `wpai_tts_supported_voices` | Voices offered for the Voice setting. Use this for connectors whose metadata is not reachable, such as local or in-browser models |
| `wpai_tts_default_voice` | Voice used when the Voice setting is empty. Set this for a provider that requires a voice but does not declare its voices |
| `wpai_tts_pending_timeout` | Seconds a job may wait for WP-Cron to start it before it is failed (default 90). Raise it on sites whose system cron runs less than once a minute |
| `wpai_tts_processing_timeout` | Seconds a running job may go without progress before it is failed (default: provider request timeout + WP-Cron lock timeout + 60, about 240). With WP-Cron disabled, each chunk waits for the next system cron run, so raise it above that interval |

## Limitations

- Requires a connector with a working text to speech model.
- Chunk joins are plain MP3 concatenation: not guaranteed gapless, and multi-chunk jobs require MP3 output. When a post needs more than one chunk, MP3 is requested explicitly and the job is refused when it starts, before any provider request, if no connected model declares MP3 output (for example, a provider that only returns WAV). Single-chunk posts use the provider's default audio format.
- Generation reads the post's **saved** content; the editor blocks the button while there are unsaved changes.
- WP-Cron scheduling depends on site traffic; the editor's status polling keeps it moving while the editor is open. On very low-traffic sites with the editor closed, generation may pause until the next request arrives (or a real cron runner is configured).
- Generation requires WP-Cron (or a system cron) to run. Stuck jobs are cancelled when their status is next read: a job still `pending` after 90 seconds (`wpai_tts_pending_timeout`) is failed with a message that WP-Cron isn't running, and a `processing` job is failed once it has made no progress for the provider request timeout (default 120 seconds, via `wpai_default_request_timeout`) plus the one-minute WP-Cron lock plus 60 seconds of slack (`wpai_tts_processing_timeout`). With WP-Cron disabled and a system cron that runs every few minutes, raise both timeouts above the cron interval.
