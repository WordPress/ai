# Alt Text Generation

## Summary

The Alt Text Generation experiment adds an AI-powered "Generate Alt Text" experience across the Image block inspector, the media modal, and the Media Library attachment edit screen. When enabled, editors can generate or regenerate alt text from any of these surfaces via the shared `ai/alt-text-generation` ability. The ability uses AI vision models to analyze images and produce concise, accessible alt text. Editors can review the suggestion before applying it.

## Overview

### For End Users

When enabled, the Alt Text Generation experiment adds "Generate/Regenerate Alt Text" controls wherever images are edited:

- **Block editor:** In the sidebar when an Image block is selected, an "AI Alternative Text" panel appears with a button to generate or regenerate alt text. After generation, a textarea shows the suggestion with "Apply" and "Dismiss" options.
- **Media modal:** When inserting or editing an image via the media library modal (block editor, classic editor, or site editor), a Generate/Regenerate button appears next to the Alt Text field. Generated text is written into the field and core saves it when the modal is closed.
- **Attachment edit screen:** When editing an individual attachment (`Media → Library → Edit`), an "AI Alt Text" meta box or field provides the same Generate/Regenerate button.

**Key Features:**

- Generate or regenerate alt text from the Image block inspector, media modal, or attachment edit screen
- Optional context can be passed (e.g., surrounding post content) to improve relevance
- Supports both attachment IDs (media library images) and image URLs (including external and data URIs)
- Output is trimmed and truncated to 125 characters to align with accessibility guidance
- Single shared ability (`ai/alt-text-generation`) usable from the UI or directly via REST API

### For Developers

The experiment consists of three main parts:

1. **Experiment Class** (`WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation`): Handles registration, asset enqueuing, block editor and media UI integration, attachment meta box, and media modal field
2. **Alt Text Generation Ability** (`WordPress\AI\Abilities\Image\Alt_Text_Generation`): Validates input, resolves image references (attachment ID or URL) to a data URI, calls the AI client with a vision model and system instruction, and returns `{ alt_text: '...' }`
3. **Frontend:** React components for the block editor (`AltTextControls`), plus a DOM-based script (`media.ts`) for the media sidebar and attachment edit form that uses `runAbility` (REST when `wp.abilities.executeAbility` is unavailable)

The ability can be called directly via REST API for automation, bulk processing, or custom integrations.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation::register()` wires everything when the experiment is enabled:
  - `wp_abilities_api_init` → `register_abilities()` registers the `ai/alt-text-generation` ability
  - `enqueue_block_editor_assets` → `enqueue_editor_assets()` loads the React bundle (`experiments/alt-text-generation`) and localizes `window.aiAltTextGenerationData`; also enqueues the media script when needed
  - `wp_enqueue_media` → `enqueue_media_frame_assets()` runs `maybe_enqueue_media_script()` so the media modal gets the DOM-based integration
  - `admin_enqueue_scripts` → `maybe_enqueue_media_library_assets()` enqueues the media script on `upload.php`, `media-new.php`, and when the current screen is the attachment edit screen
  - `add_meta_boxes_attachment` → `setup_attachment_meta_box()` adds an "AI Alt Text" meta box for image attachments
  - `attachment_fields_to_edit` → `add_button_to_media_modal()` adds an "AI Alt Text" field with Generate/Regenerate button to the media modal
- `src/experiments/alt-text-generation/index.tsx` uses `addFilter( 'editor.BlockEdit', 'ai/alt-text-generation', ... )` to inject `<AltTextControls />` into every `core/image` block when the experiment is enabled
- `src/experiments/alt-text-generation/media.ts` finds `.ai-alt-text-media-actions` and the associated textarea (e.g. `#attachment-details-two-column-alt-text`, `#attachment-details-alt-text`, or `#attachment_alt`), wires the Generate button to `runAbility( 'ai/alt-text-generation', { attachment_id } )`, and updates the textarea value and button label on success
- Ability implementation: `includes/Abilities/Image/Alt_Text_Generation.php` (extends `Abstract_Ability`) handles input sanitization, permission checks, image reference resolution (attachment or URL → data URI), and calls `wp_ai_client_prompt()->with_file()->generate_text()` using the system instruction at `includes/Abilities/Image/alt-text-system-instruction.php`

### Assets & Data Flow

1. **PHP side:**
   - `enqueue_editor_assets()` loads the script handle for `experiments/alt-text-generation` (`src/experiments/alt-text-generation/index.tsx`) and localizes `window.aiAltTextGenerationData` with:
     - `enabled`: Whether the experiment is enabled
   - `maybe_enqueue_media_script()` loads `experiments/alt-text-generation-media` (`src/experiments/alt-text-generation/media.ts`) and localizes `window.aiAltTextGenerationMediaData` with `enabled`. This runs at most once per request (when the block editor loads, when the media modal is enqueued, or on upload/media/attachment screens).

2. **Block editor (React):**
   - The `editor.BlockEdit` filter wraps the Image block with a component that renders `<AltTextControls />` when the experiment is enabled and the block is `core/image`.
   - `AltTextControls` uses `runAbility( 'ai/alt-text-generation', params )` from `src/utils/run-ability.ts`. Params include `attachment_id` or `image_url` and optionally `context`. The helper uses `wp.abilities.executeAbility` when available, otherwise `apiFetch` to `POST /wp-abilities/v1/abilities/ai/alt-text-generation/run` with `{ input: params }`.
   - On success, the component shows a textarea with the generated alt text and Apply/Dismiss buttons; Apply calls `setAttributes( { alt: generatedAlt } )`.

3. **Media modal & attachment edit (DOM):**
   - The media script waits for `.ai-alt-text-media-actions` and the corresponding alt textarea (injected by the PHP meta box or `attachment_fields_to_edit`). It attaches a click handler to the Generate button, reads `data-attachment-id`, and calls `runAbility( 'ai/alt-text-generation', { attachment_id } )`. On success it sets the textarea value and dispatches `input`/`change` so core persists the value.

4. **Ability execution flow:**
   - **Resolve image:** If `attachment_id` is set, load the attachment file or image URL and convert to a data URI. If `image_url` is set, accept data URIs as-is, map local upload URLs to the filesystem when possible, or download the URL to a temp file and convert to a data URI.
   - **Generate:** Build a short prompt (e.g. "Generate alt text for this image." plus optional "Context: …"). Call the AI client with the system instruction from `alt-text-system-instruction.php`, the image as a file reference, and preferred vision models. Trim and strip surrounding quotes; truncate to 125 characters.
   - **Return:** `array( 'alt_text' => sanitize_text_field( $result ) )`.

### Input Schemas

#### Alt Text Generation Ability

```php
array(
    'type'       => 'object',
    'properties' => array(
        'attachment_id' => array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'description'       => 'The attachment ID of the image to generate alt text for.',
        ),
        'image_url'     => array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_image_reference_input' ),
            'description'       => 'URL or data URI of the image to generate alt text for. Used if attachment_id is not provided.',
        ),
        'context'       => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'description'       => 'Optional context about the image or surrounding content to improve alt text relevance.',
        ),
    ),
)
```

At least one of `attachment_id` or `image_url` must be provided.

### Output Schemas

#### Alt Text Generation Ability Output

The ability returns an object with the generated alt text:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'alt_text' => array(
            'type'        => 'string',
            'description' => 'Generated alt text for the image.',
        ),
    ),
)
```

### Permissions

- **With `attachment_id`:** User must be able to edit the attachment (`current_user_can( 'edit_post', $attachment_id )`). If the attachment is not found, returns an error.
- **With `image_url` only:** User must have `upload_files` capability.

## Using the Abilities via REST API

The alt text generation ability can be called directly via REST API for automation, bulk processing, or custom integrations.

### Endpoints

```text
POST /wp-json/wp-abilities/v1/abilities/ai/alt-text-generation/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Generate Alt Text by Attachment ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/alt-text-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "attachment_id": 123
    }
  }'
```

**Response:**

```json
{
  "alt_text": "A red bicycle leaning against a wooden fence in a sunny park"
}
```

#### Example 2: Generate Alt Text by Image URL with Context

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/alt-text-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "image_url": "https://yoursite.com/wp-content/uploads/2025/01/hero-image.jpg",
      "context": "This image appears in the hero section of our homepage, above the main headline."
    }
  }'
```

**Response:**

```json
{
  "alt_text": "Hero image of a team collaborating in a modern office"
}
```

#### Example 3: Using WordPress API Fetch (in Gutenberg/Admin)

```javascript
import apiFetch from '@wordpress/api-fetch';

async function generateAltText(attachmentId, imageUrl, context) {
  const input = {};
  if (attachmentId) input.attachment_id = attachmentId;
  else if (imageUrl) input.image_url = imageUrl;
  else throw new Error('attachment_id or image_url required');

  if (context) input.context = context;

  const result = await apiFetch({
    path: '/wp-abilities/v1/abilities/ai/alt-text-generation/run',
    method: 'POST',
    data: { input },
  });

  return result.alt_text;
}

// Usage
generateAltText(123).then((alt) => console.log('Generated alt:', alt));
```

### Error Responses

The ability may return the following error codes:

- `no_image_provided`: Neither `attachment_id` nor `image_url` was provided
- `invalid_attachment`: The given attachment ID was not found or is not an attachment
- `not_an_image`: The attachment is not an image
- `image_url_not_found`: Could not retrieve image URL from attachment
- `file_read_error`: Could not read the downloaded or local image file
- `no_results`: The AI client did not return any alt text
- `attachment_not_found`: (Permission check) Attachment not found when checking capabilities
- `insufficient_capabilities`: User cannot edit the given attachment, or (for URL-only requests) user does not have `upload_files`

Example error response:

```json
{
  "code": "no_image_provided",
  "message": "Either attachment_id or image_url must be provided.",
  "data": {
    "status": 400
  }
}
```

## Extending the Experiment

### Customizing the Alt Text System Instruction

The system instruction that guides alt text generation can be customized by modifying:

```text
includes/Abilities/Image/alt-text-system-instruction.php
```

This instruction defines how the AI should generate alt text (e.g., concise, descriptive, under 125 characters, no "Image of…" prefix, plain text only). You can change tone, length guidance, or rules for decorative images.

### Customizing Maximum Alt Text Length

The ability truncates generated alt text to 125 characters (see `MAX_ALT_TEXT_LENGTH` in `Alt_Text_Generation.php`). To allow longer descriptions, change the constant and consider updating the system instruction to match.

### Adding Custom UI Elements

- **Block editor:** Edit `src/experiments/alt-text-generation/components/AltTextControls.tsx` to change labels, layout, or add context input. The block filter is in `src/experiments/alt-text-generation/index.tsx`.
- **Media modal / attachment edit:** The PHP experiment adds the button via `add_button_to_media_modal()` and `setup_attachment_meta_box()`; the behavior is implemented in `src/experiments/alt-text-generation/media.ts`. Adjust the selectors or class names in both PHP and `media.ts` if you change the markup.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Enable the global experiments toggle, then enable **Alt Text Generation**

2. **Block editor:**
   - Open the block editor for a post, insert or select an Image block (uploaded image or external URL)
   - In the sidebar, open the "AI Alternative Text" panel
   - Click **Generate Alt Text** (or **Regenerate Alt Text** if alt is already set). Confirm a spinner and then a textarea with generated text appear
   - Click **Apply** and verify the block’s alt attribute and sidebar Alt Text field update
   - Test an error path (e.g., remove the image URL) and confirm an error notice is shown

3. **Media modal:**
   - Open the media modal (Insert Media, block editor image picker, etc.), select an image
   - In the sidebar, find the Alt Text field and the **Generate Alt Text** (or **Regenerate**) button
   - Generate, confirm the textarea updates, then close the modal and verify the alt text is saved

4. **Attachment edit screen:**
   - Go to `Media → Library`, open an image, then edit the attachment
   - Locate the "AI Alt Text" meta box or field and the Generate/Regenerate button
   - Generate, confirm the Alternative Text field updates, then update the attachment and verify the value is saved

5. **REST API:**
   - Use curl or Postman to call `POST /wp-json/wp-abilities/v1/abilities/ai/alt-text-generation/run` with `input.attachment_id` or `input.image_url`
   - Verify authentication, success response shape, and error codes for invalid or unauthorized requests

### Automated Testing

Unit tests are located in:

- `tests/Integration/Includes/Abilities/Alt_Text_GenerationTest.php`
- `tests/Integration/Includes/Experiments/Alt_Text_Generation/Alt_Text_GenerationTest.php`

Run tests with:

```bash
npm run test:php
```

## Notes & Considerations

### Requirements

- The experiment requires valid AI credentials and vision-capable models (configured via `get_preferred_vision_models()`).
- Users need `edit_post` for the specific attachment when using `attachment_id`, or `upload_files` when using only `image_url`.
- The experiment is only active when both the global experiments flag (`ai_experiments_enabled`) and the experiment option (`ai_experiment_alt-text-generation_enabled`) are enabled. Use the filter `ai_experiments_experiment_alt-text-generation_enabled` to override.

### Performance

- Each request sends the image (as a data URI) to the AI provider. Large images are not resized before sending; consider attachment size and provider limits.
- The UI shows a loading state while the ability runs. Timeouts follow the default AI client behavior.

### Accessibility

- Generated alt text is trimmed and truncated to 125 characters to align with common accessibility guidance for concise alt text.
- The system instruction directs the model to avoid "Image of…" prefixes, to describe content objectively, and to return an empty string for decorative images.

### Limitations

- One image per request; no batch API in this experiment.
- Output is plain text only; no structured fields or language selection in the default ability.
- Media modal and attachment edit UI depend on DOM selectors (e.g. `#attachment_alt`, `.ai-alt-text-media-actions`); custom themes or plugins that change these may require adjustments.

### Security Considerations

- Input is sanitized (e.g. `absint`, `esc_url_raw`, `sanitize_textarea_field`; data URIs allowed for `image_url`).
- Permission checks run before processing; attachment edit permission is required when using `attachment_id`.
- Remote URLs are fetched server-side; ensure your environment allows outbound HTTP for external image URLs if used.
