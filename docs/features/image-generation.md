# Image Generation

## Summary

The Image Generation feature adds AI-powered image generation to the WordPress post editor in two ways: **featured images** (from the featured image panel) and **inline images** (from supported blocks). It provides a "Generate featured image" button in the featured image panel and a "Generate Image" buttons on Image, Cover, Media & Text, and Gallery blocks. The feature registers three WordPress Abilities (`ai/image-generation`, `ai/image-import`, `ai/image-prompt-generation`) that can be used both through the admin UI and directly via REST API requests.

## Overview

### For End Users

When enabled, the Image Generation feature adds:

- **Featured image panel:** A "Generate featured image" button that creates AI images from post content. The image is imported into the media library and set as the featured image. Images are marked with an "AI Generated Featured Image" label.
- **Block buttons:** A "Generate Image" inline and toolbar button on Image, Cover, Media & Text, and Gallery blocks. Clicking it opens a modal where you describe the image, generate it, preview it, optionally refine it (edit with follow-up prompts using the current image as reference), and insert it into the block.

**Key Features:**

- One-click featured image generation from post content
- Inline image generation from supported blocks (Image, Cover, Media & Text, Gallery)
- Modal flow for inline generation: describe → generate → preview → keep, refine, or start over
- Image refinement: use the current generated image as a reference for follow-up prompts (models that support edits use it as context)
- Step-by-step progress messages during generation (e.g. "Generating image prompt", "Generating image", "Generating alt text", "Importing image")
- Automatically imports generated images into the media library
- Sets generated images as featured images or inserts them into blocks
- Uses AI to create an image generation prompt from post context (featured image flow)
- Optional AI-generated alt text when the Alt Text Generation experiment is enabled
- Works with any post type that supports featured images
- Visual indicator for AI-generated featured images

### For Developers

The feature consists of four main components:

1. **Feature Class** (`WordPress\AI\Features\Image_Generation\Image_Generation`): Handles registration, asset enqueuing, featured image and inline block editor UI integration, and post meta registration
2. **Generate Image Prompt Ability** (`WordPress\AI\Abilities\Image\Generate_Image_Prompt`): Generates optimized image generation prompts from post content and context
3. **Generate Image Ability** (`WordPress\AI\Abilities\Image\Generate_Image`): Generates base64-encoded images from prompts (and optionally from a reference image for refining) using AI models
4. **Import Image Ability** (`WordPress\AI\Abilities\Image\Import_Base64_Image`): Imports base64-encoded images into the WordPress media library

All three abilities can be called directly via REST API, making them useful for automation, bulk processing, or custom integrations.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Features\Image_Generation\Image_Generation::register()` wires everything once the feature is enabled:
  - `register_post_meta()` → registers `ai_generated` post meta for attachment post type
  - `wp_abilities_api_init` → registers the `ai/image-generation`, `ai/image-import`, and `ai/image-prompt-generation` abilities
  - `admin_enqueue_scripts` → `enqueue_assets()` loads assets on `post.php` and `post-new.php` screens for post types that support featured images
  - `enqueue_block_editor_assets` → `enqueue_inline_assets()` loads the same assets in the block editor for inline image generation

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_shared_assets()` (called from `enqueue_assets()` and `enqueue_inline_assets()`) loads `features/image-generation` (`src/features/image-generation/index.ts`) and localizes `window.aiImageGenerationData` with:
     - `enabled`: Whether the feature is enabled
     - `altTextEnabled`: Whether the alt text generation experiment is enabled

2. **React Side (Featured Image):**
   - `featured-image.tsx` hooks into the featured image panel using the `editor.PostFeaturedImage` filter
   - `GenerateFeaturedImage` component renders a button and progress UI that:
     - Gets current post ID and content from the editor store
     - Tracks `progressMessage` state and passes an `onProgress` callback to `generateImage()` and `uploadImage()`
     - Calls `generateImage( postId, content, { onProgress } )`, which:
       - Gets post context (title, type) via `getContext()` (uses `ai/get-post-details` ability)
       - Formats context using `formatContext()`
       - Invokes `onProgress( 'Generating image prompt' )`, then calls `generatePrompt()` to create an image generation prompt from content and context
       - Invokes `onProgress( 'Generating image' )`, then calls the `ai/image-generation` ability with the generated prompt
       - Returns generated image data (base64 data, prompt, provider/model metadata)
     - Calls `uploadImage( imageData, { onProgress } )`, which:
       - If the Alt Text Generation experiment is enabled (`aiImageGenerationData.altTextEnabled`): invokes `onProgress( 'Generating alt text' )`, then calls `generateAltText()` and uses the result as `alt_text`; otherwise uses the prompt as fallback alt text
       - Invokes `onProgress( 'Importing image' )`, then calls the `ai/image-import` ability with base64 data, metadata, and `ai_generated` meta
       - Returns attachment data (id, url, title)
     - Updates the editor store to set the imported image as featured image
     - Shows a loading state on the button and a progress message (with spinner) under the button while generating; clears both on success or error
     - Handles error notifications via the notices store
   - `AILabel` component displays a label for AI-generated images by checking the `ai_generated` meta

3. **React Side (Inline Image Generation):**
   - `inline.tsx` registers two filters for supported blocks (`core/image`, `core/cover`, `core/media-text`, `core/gallery`):
     - `editor.BlockEdit` with `withGenerateImageToolbarButton` (`ai/image-generation-inline-toolbar`): adds a "Generate Image" toolbar button in block controls
     - `editor.MediaUpload` with `withGenerateImageInlineButton` (`ai/image-generation-inline-button`): adds an inline "Generate Image" button in the MediaUpload placeholder area (uses `updateBlockAttributes` from the block editor store since MediaUpload does not receive `setAttributes`)
   - When either button is clicked, `GenerateImageInlineModal` opens with an idle state (prompt input). The user submits a prompt and the modal:
     - Calls `runAbility( 'ai/image-generation', { prompt } )` (or `{ prompt, reference }` when refining)
     - Shows preview with "Keep", "Refine", and "Start Over" actions
     - "Refine" switches to refinment state: user enters a follow-up prompt; the current image is passed as `reference` so models supporting edits can use it as context
     - "Keep" calls `uploadImage()` (with optional alt text generation) and `insertIntoBlock()` to insert the imported image into the block
   - `insertIntoBlock()` sets block attributes based on block type: `core/image` (id, url, alt), `core/cover` (id, url, alt, dimRatio: 50, isDark: false, sizeSlug: 'full'), `core/media-text` (mediaId, mediaUrl, mediaType), `core/gallery` (appends a new inner `core/image` block)

4. **Ability Execution Flow:**
   - **Image Prompt Generation** (via `ai/image-prompt-generation`):
     - Accepts `content` (string), `context` (string or post ID), and optional `style` (string) as input
     - If `context` is numeric, treats it as a post ID and fetches post context using `get_post_context()`
     - Normalizes content using `normalize_content()` helper
     - Uses AI with a dedicated system instruction to generate an optimized image generation prompt
     - Returns a plain text prompt string suitable for image generation models
   - **Image Generation** (via `ai/image-generation`):
     - Accepts `prompt` (string) as required input and optional `reference` (base64-encoded string) for image editing
     - If `reference` is provided, uses it as a reference image for editing
     - Uses AI image generation models (via `get_preferred_image_models()`)
     - Sets request timeout to 90 seconds for longer generation times
     - Returns an object `{ image: { data, provider_metadata, model_metadata } }` where `data` is the base64-encoded image
   - **Image Import** (via `ai/image-import`):
     - Accepts base64 image data and metadata (filename, title, description, alt_text, mime_type, meta)
     - Decodes base64 data and creates temporary file
     - Uses WordPress `media_handle_sideload()` to import into media library
     - Sets attachment metadata and custom meta (like `ai_generated`)
     - Returns attachment data (id, url, filename, title, description, alt_text)

### Input Schemas

#### Image Prompt Generation Ability

```php
array(
    'type'       => 'object',
    'properties' => array(
        'content' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'The content to use as inspiration for the generated image.',
        ),
        'context' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Any additional context to help generate the prompt. This can either be a string of additional context or can be a post ID that will then be used to get context from that post (if it exists).',
        ),
        'style'   => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Any additional style instructions to apply to the generated image.',
        ),
    ),
    'required'   => array( 'content' ),
)
```

#### Image Generation Ability

```php
array(
    'type'       => 'object',
    'properties' => array(
        'prompt'    => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Prompt used to generate an image.',
        ),
        'reference' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Optional base64-encoded image to use as a reference for editing.',
        ),
    ),
    'required'   => array( 'prompt' ),
)
```

#### Image Import Ability

```php
array(
    'type'       => 'object',
    'properties' => array(
        'data'        => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'The base64 encoded image data to import into the media library.',
        ),
        'filename'    => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'The filename of the image.',
        ),
        'title'       => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'The title of the image.',
        ),
        'description' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'The description of the image.',
        ),
        'alt_text'    => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'The alt text of the image.',
        ),
        'mime_type'   => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'The MIME type of the image.',
        ),
        'meta'        => array(
            'type'        => 'array',
            'description' => 'Optional meta data to save with the image.',
            'items'       => array(
                'type'                 => 'object',
                'properties'           => array(
                    'key'   => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'description'       => 'The key of the meta data.',
                    ),
                    'value' => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'description'       => 'The value of the meta data.',
                    ),
                ),
                'required'             => array( 'key', 'value' ),
                'additionalProperties' => false,
            ),
        ),
    ),
    'required'   => array( 'data' ),
)
```

### Output Schemas

#### Image Prompt Generation Ability Output

The ability returns a plain text string containing the generated image prompt:

```php
array(
    'type'        => 'string',
    'description' => 'The image generation prompt.',
)
```

#### Image Generation Ability Output

The ability returns an object containing the generated image data and metadata:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'image' => array(
            'type'        => 'object',
            'description' => 'Generated image data.',
            'properties'  => array(
                'data'              => array(
                    'type'        => 'string',
                    'description' => 'The base64 encoded image data.',
                ),
                'provider_metadata' => array(
                    'type'        => 'object',
                    'description' => 'Information about the provider (id, name, type).',
                ),
                'model_metadata'    => array(
                    'type'        => 'object',
                    'description' => 'Information about the model (id, name).',
                ),
            ),
        ),
    ),
)
```

#### Image Import Ability Output

The ability returns an object with image data:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'image' => array(
            'type'        => 'object',
            'description' => 'Imported image data.',
            'properties'  => array(
                'id'          => array(
                    'type'        => 'integer',
                    'description' => 'Attachment ID.',
                ),
                'url'         => array(
                    'type'        => 'string',
                    'description' => 'Attachment URL.',
                ),
                'filename'    => array(
                    'type'        => 'string',
                    'description' => 'Attachment filename.',
                ),
                'title'       => array(
                    'type'        => 'string',
                    'description' => 'Attachment title.',
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'Attachment description.',
                ),
                'alt_text'    => array(
                    'type'        => 'string',
                    'description' => 'Attachment alt text.',
                ),
            ),
        ),
    ),
)
```

### Permissions

All abilities check permissions:

- **Image Prompt Generation:** Requires user to be logged in (`is_user_logged_in()`)
- **Image Generation:** Requires `current_user_can( 'upload_files' )`
- **Image Import:** Requires `current_user_can( 'upload_files' )`

## Using the Abilities via REST API

All three abilities can be called directly via REST API, making them useful for automation, bulk processing, or custom integrations.

### Endpoints

```text
POST /wp-json/wp-abilities/v1/abilities/ai/image-prompt-generation/run
POST /wp-json/wp-abilities/v1/abilities/ai/image-generation/run
POST /wp-json/wp-abilities/v1/abilities/ai/image-import/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Generate Image Prompt from Content

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/image-prompt-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This article discusses the benefits of renewable energy and solar power installations.",
      "context": "Title: Renewable Energy Solutions\nType: post",
      "style": "Editorial style, professional photography"
    }
  }'
```

**Response:**

```json
"A professional editorial photograph of a modern solar panel installation in a sunny landscape, showcasing renewable energy technology with clean, bright lighting and a professional composition"
```

#### Example 2: Generate Image from Prompt

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/image-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "prompt": "A serene mountain landscape at sunset with a lake in the foreground, photorealistic style"
    }
  }'
```

**Response:**

```json
{
  "image": {
    "data": "iVBORw0KGgoAAAANSUhEUgAA...",
    "provider_metadata": { "id": "google", "name": "Google", "type": "..." },
    "model_metadata": { "id": "imagen-4.0-generate-001", "name": "Imagen 4" }
  }
}
```

The `image.data` field contains the base64-encoded image. Use this when chaining with the import ability.

#### Example 3: Generate Image Prompt from Post ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/image-prompt-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "context": "123"
    }
  }'
```

This will automatically fetch the content from post ID 123 and generate an image prompt.

#### Example 4: Import Base64 Image into Media Library

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/image-import/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "data": "iVBORw0KGgoAAAANSUhEUgAA...",
      "filename": "mountain-landscape",
      "title": "Mountain Landscape",
      "description": "A beautiful mountain landscape at sunset",
      "alt_text": "Mountain landscape with lake at sunset",
      "mime_type": "image/png",
      "meta": [
        {
          "key": "ai_generated",
          "value": "1"
        }
      ]
    }
  }'
```

**Response:**

```json
{
  "image": {
    "id": 123,
    "url": "https://yoursite.com/wp-content/uploads/2025/01/mountain-landscape.png",
    "filename": "mountain-landscape.png",
    "title": "Mountain Landscape",
    "description": "A beautiful mountain landscape at sunset",
    "alt_text": "Mountain landscape with lake at sunset"
  }
}
```

#### Example 5: Complete Flow - Generate Prompt, Generate Image, and Import

```bash
# Step 1: Generate the image prompt
PROMPT=$(curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/image-prompt-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This article discusses modern office design trends and workspace productivity.",
      "context": "Title: Modern Office Design\nType: post"
    }
  }')

# Step 2: Generate the image using the prompt
# Response format: { "image": { "data": "<base64>", "provider_metadata": {...}, "model_metadata": {...} } }
GENERATED_IMAGE=$(curl -s -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/image-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d "{
    \"input\": {
      \"prompt\": \"$PROMPT\"
    }
  }" | python3 -c "import sys, json; print(json.load(sys.stdin)['image']['data'])")

# Step 3: Import the image
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/image-import/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d "{
    \"input\": {
      \"data\": \"$GENERATED_IMAGE\",
      \"filename\": \"office-workspace\",
      \"title\": \"Modern Office Workspace\",
      \"description\": \"AI generated image of a modern office workspace\",
      \"alt_text\": \"Modern office workspace with plants\",
      \"mime_type\": \"image/png\",
      \"meta\": [
        {
          \"key\": \"ai_generated\",
          \"value\": \"1\"
        }
      ]
    }
  }"
```

#### Example 6: Using JavaScript (Fetch API)

```javascript
async function generateAndImportImage(content, context, filename, title) {
  // Step 1: Generate image prompt
  const promptResponse = await fetch(
    '/wp-json/wp-abilities/v1/abilities/ai/image-prompt-generation/run',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce,
      },
      credentials: 'include',
      body: JSON.stringify({
        input: { content, context },
      }),
    }
  );

  if (!promptResponse.ok) {
    const error = await promptResponse.json();
    throw new Error(error.message || 'Failed to generate prompt');
  }

  const prompt = await promptResponse.text();

  // Step 2: Generate image
  const generateResponse = await fetch(
    '/wp-json/wp-abilities/v1/abilities/ai/image-generation/run',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce,
      },
      credentials: 'include',
      body: JSON.stringify({
        input: { prompt },
      }),
    }
  );

  if (!generateResponse.ok) {
    const error = await generateResponse.json();
    throw new Error(error.message || 'Failed to generate image');
  }

  const { image } = await generateResponse.json();
  const base64Image = image?.data ?? '';

  // Step 3: Import image
  const importResponse = await fetch(
    '/wp-json/wp-abilities/v1/abilities/ai/image-import/run',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce,
      },
      credentials: 'include',
      body: JSON.stringify({
        input: {
          data: base64Image,
          filename: filename,
          title: title,
          description: 'AI generated image',
          alt_text: title,
          mime_type: 'image/png',
          meta: [
            {
              key: 'ai_generated',
              value: '1',
            },
          ],
        },
      }),
    }
  );

  if (!importResponse.ok) {
    const error = await importResponse.json();
    throw new Error(error.message || 'Failed to import image');
  }

  const result = await importResponse.json();
  return result.image;
}

// Usage
generateAndImportImage(
  'This article discusses futuristic urban planning and smart cities.',
  'Title: Future Cities\nType: post',
  'futuristic-city',
  'Futuristic Cityscape'
)
  .then(image => console.log('Imported image:', image))
  .catch(error => console.error('Error:', error));
```

#### Example 7: Using WordPress API Fetch (in Gutenberg/Admin)

```javascript
import apiFetch from '@wordpress/api-fetch';

async function generateAndImportImage(content, context, filename, title) {
  try {
    // Step 1: Generate image prompt
    const prompt = await apiFetch({
      path: '/wp-abilities/v1/abilities/ai/image-prompt-generation/run',
      method: 'POST',
      data: {
        input: { content, context },
      },
    });

    // Step 2: Generate image (response: { image: { data, provider_metadata, model_metadata } })
    const { image: generatedImage } = await apiFetch({
      path: '/wp-abilities/v1/abilities/ai/image-generation/run',
      method: 'POST',
      data: {
        input: { prompt },
      },
    });
    const base64Image = generatedImage?.data ?? '';

    // Step 3: Import image
    const result = await apiFetch({
      path: '/wp-abilities/v1/abilities/ai/image-import/run',
      method: 'POST',
      data: {
        input: {
          data: base64Image,
          filename: filename,
          title: title,
          description: 'AI generated image',
          alt_text: title,
          mime_type: 'image/png',
          meta: [
            {
              key: 'ai_generated',
              value: '1',
            },
          ],
        },
      },
    });

    return result.image;
  } catch (error) {
    console.error('Error generating/importing image:', error);
    throw error;
  }
}
```

### Error Responses

The abilities may return the following error codes:

**Image Prompt Generation:**

- `post_not_found`: The provided post ID does not exist
- `content_not_provided`: No content was provided and no valid post ID was found
- `no_results`: The AI client did not return any results

**Image Generation:**

- `no_results`: The AI client did not return any results
- `no_image_data`: The generated image data is empty
- `insufficient_capabilities`: The current user does not have permission to generate images

**Image Import:**

- `invalid_data`: The provided data is not a valid base64 encoded string or is not a valid image
- `no_base64_data`: No base64 data found in the provided input
- `invalid_base64`: Failed to decode base64 image data
- `write_failed`: Failed to write image data to temporary file
- `attachment_not_found`: Failed to retrieve attachment data after import
- `insufficient_capabilities`: The current user does not have permission to import images

Example error response:

```json
{
  "code": "invalid_data",
  "message": "The data is not a valid base64 encoded string.",
  "data": {
    "status": 400
  }
}
```

## Extending the Feature

### Customizing the Image Prompt Generation System Instruction

The system instruction that guides image prompt generation can be customized by modifying:

```php
includes/Abilities/Image/image-prompt-system-instruction.php
```

This instruction tells the AI how to create image generation prompts from post content and context. You can modify it to change the style, tone, or requirements for generated prompts. The system instruction is specifically designed for image generation prompts and differs from generic prompt generation.

### Filtering Preferred Image Models

You can filter which AI image models are used for image generation using the `wpai_preferred_image_models` filter:

```php
add_filter( 'wpai_preferred_image_models', function( $models ) {
    // Prefer specific image models
    return array(
        array( 'openai', 'gpt-image-1' ),
        array( 'google', 'imagen-4.0-generate-001' ),
    );
} );
```

### Customizing Image Import Metadata

You can customize what metadata is saved when importing images by modifying the `uploadImage` function in:

```typescript
src/features/image-generation/functions/upload-image.ts
```

`uploadImage( imageData, options? )` accepts generated image data and an optional `options` object with `onProgress?: ( message: string ) => void` for progress callbacks. When the Alt Text Generation experiment is enabled, it generates alt text via `generateAltText()` before importing; otherwise it uses the image prompt as alt text.

You can also filter the input before calling the import ability via REST API.

### Customizing Post Context

The feature uses `getContext()` to fetch post details (title, type). You can extend this to include additional context by modifying:

```typescript
src/features/image-generation/functions/get-context.ts
```

The context is formatted using `formatContext()` which converts key-value pairs into a string format. You can customize this formatting by modifying:

```typescript
src/features/image-generation/functions/format-context.ts
```

### Adding Custom UI Elements

You can extend the React components to add custom UI elements:

1. **Modify the featured image button and progress UI:**
   - Edit `src/features/image-generation/components/GenerateFeaturedImage.tsx`
   - The component renders a button and, while generating, a progress container (`.ai-featured-image__progress`) that displays the current step and a spinner; progress is driven by the `onProgress` callbacks passed to `generateImage()` and `uploadImage()`

2. **Modify the inline generation modal:**
   - Edit `src/features/image-generation/components/GenerateImageInlineModal.tsx`
   - The modal supports idle (prompt input), generating, preview (keep/refine/start over) states
   - Customize the flow, UI copy, or add new actions

3. **Add or change supported blocks for inline generation:**
   - Edit `inline.tsx` and modify the `TARGET_BLOCKS` array (`core/image`, `core/cover`, `core/media-text`, `core/gallery`)
   - To support a new block type, update `insertIntoBlock()` in `src/features/image-generation/functions/insert-into-block.ts` with the correct attribute mapping

4. **Customize the AI label:**
   - Edit `src/features/image-generation/components/AILabel.tsx`

5. **Add custom functions:**
   - Create new functions in `src/features/image-generation/functions/`
   - Import and use them in the components

6. **Customize the featured image panel:**
   - The feature uses the `editor.PostFeaturedImage` filter to inject into the featured image panel
   - You can modify `src/features/image-generation/featured-image.tsx` to add additional UI

### Customizing Image Processing

The image import process uses WordPress's `media_handle_sideload()` function. You can hook into WordPress media upload filters to customize the import process:

```php
// Customize attachment data before saving
add_filter( 'wp_insert_attachment_data', function( $data, $postarr ) {
    // Customize attachment data
    return $data;
}, 10, 2 );

// Customize attachment metadata
add_filter( 'wp_generate_attachment_metadata', function( $metadata, $attachment_id ) {
    // Customize metadata
    return $metadata;
}, 10, 2 );
```

## Testing

### Manual Testing

1. **Enable the feature:**
   - Go to `Settings → AI`
   - Toggle **Image Generation** to enabled
   - Ensure you have valid AI credentials configured

2. **Test featured image generation:**
   - Create or edit a post with content
   - Scroll to the featured image panel
   - Click the "Generate featured image" button
   - Verify progress messages appear in order: "Generating image prompt", "Generating image", then "Generating alt text" (if Alt Text experiment is enabled), then "Importing image"
   - Verify the image is generated, imported, and set as featured image
   - Verify the "AI Generated Featured Image" label appears
   - Click "Generate new featured image" to test regeneration

3. **Test inline image generation:**
   - Add an Image, Cover, Media & Text, or Gallery block
   - Select the block and click the "Generate Image" toolbar or inline button
   - Enter a prompt (e.g. "A sunset over mountains") and click Generate
   - Verify the preview appears with "Keep", "Refine", and "Start Over"
   - Click "Refine", add a follow-up prompt (e.g. "Add clouds"), and Generate; verify the image updates
   - Click "Keep" and verify the image is imported and inserted into the block
   - Test with each supported block type to ensure correct attribute mapping

4. **Test with different post types:**
   - The feature only loads for post types that support featured images (`post_type_supports( $post_type, 'thumbnail' )`)
   - Test with posts, pages, and custom post types that have featured image support

5. **Test REST API:**
   - Use curl or Postman to test both endpoints
   - Verify authentication works
   - Test image generation with different prompts
   - Test image import with different metadata
   - Test the complete flow (generate then import)
   - Verify error handling for invalid inputs

### Automated Testing

Unit tests are located in:

- `tests/Integration/Includes/Abilities/Image_GenerationTest.php`
- `tests/Integration/Includes/Features/Image_Generation/Image_GenerationTest.php`

Run tests with:

```bash
npm run test:php
```

## Notes & Considerations

### Requirements

- The feature requires valid AI credentials to be configured
- The feature only works for post types that support featured images (`post_type_supports( $post_type, 'thumbnail' )`)
- Users must have `upload_files` capability
- The feature requires image generation models to be available (configured via `get_preferred_image_models()`)

### Performance

- Image generation is an AI operation and may take 30-90 seconds (timeout is set to 90 seconds)
- The UI shows a loading state on the button and step-by-step progress messages below it ("Generating image prompt" → "Generating image" → "Generating alt text" (if enabled) → "Importing image") so users know which step is running
- Base64 image data can be large; ensure adequate memory and request timeout settings
- Consider implementing caching for frequently accessed images if generating images in bulk

### Image Processing

- Images are generated as base64-encoded strings
- The import process decodes base64, creates a temporary file, and uses WordPress media functions to import
- Temporary files are automatically cleaned up after import
- File extension is determined from MIME type using `wp_get_default_extension_for_mime_type()`

### AI Model Selection

- The ability uses `get_preferred_image_models()` to determine which AI image models to use
- Models are tried in order until one succeeds
- Default models include Google's Gemini (e.g. gemini-3.1-flash-image-preview, gemini-2.5-flash-image), Imagen, and OpenAI's GPT-image models
- All default models support image generation; the Google models also support image editing (when a reference image is provided)
- Request timeout is set to 90 seconds to accommodate longer image generation times

### Prompt Generation

- The feature uses a three-step process:
  1. First, it gets post context (title, type) using the `ai/get-post-details` ability
  2. Then, it generates an optimized image generation prompt from post content and context using the `ai/image-prompt-generation` ability
  3. Finally, it uses that prompt to generate the actual image
- The image prompt generation uses a dedicated system instruction (`image-prompt-system-instruction.php`) that is specifically designed for creating image generation prompts
- The system instruction ensures the generated prompt:
  - Is self-contained and can be passed directly to image generation models
  - Incorporates content and context faithfully
  - Describes the subject, setting, and visual style clearly
  - Avoids text, captions, logos, or branding unless specified
  - Reflects the content's theme without being overly literal
- The generated prompt is designed to be suitable for image generation models and reflects the article's core topic and tone

### Image Metadata

- Imported images are marked with `ai_generated` post meta (set to `1`)
- This meta is registered for the `attachment` post type and is available in REST API
- The `AILabel` component checks this meta to display the AI-generated label
- Additional custom meta can be passed via the `meta` parameter in the import ability

### Limitations

- Images are generated in real-time and not cached
- The ability does not support batch processing (one image per request)
- Generated images are suggestions and should be reviewed before publishing
- The feature requires JavaScript to be enabled in the admin
- Image generation may fail if AI models are unavailable or rate-limited
- Base64 image data can be very large; ensure adequate server resources

### Security Considerations

- Base64 image data is validated before import
- File types are validated using MIME type checking
- Temporary files are properly cleaned up after import
- User permissions are checked before allowing image generation or import
- All input is sanitized using WordPress sanitization functions
