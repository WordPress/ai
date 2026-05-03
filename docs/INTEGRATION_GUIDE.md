# WordPress AI Plugin — Integration Guide

A practical, end‑to‑end guide to using and extending the WordPress AI plugin (`v0.8.0`). It covers end‑user setup, the Connectors API, the Abilities API surface (REST + PHP), the experiment/feature framework, hooks/filters, and asset loading.

> Companion docs in this directory: [`ARCHITECTURE_OVERVIEW.md`](ARCHITECTURE_OVERVIEW.md), [`DEVELOPER_GUIDE.md`](DEVELOPER_GUIDE.md), [`TESTING_REST_API.md`](TESTING_REST_API.md), and per‑experiment specs in [`experiments/`](experiments/).

---

## 1. What it actually is

The plugin is a *Canonical Plugin* and reference implementation that wires together two upstream "AI Building Blocks" into a unified WordPress experience:

- **PHP AI Client SDK** — provides the `wp_ai_client_prompt()` builder, `WP_AI_Client_Prompt_Builder`, and the `wp_get_connectors()` / `wp_register_connector()` Connectors API.
- **Abilities API** — provides `wp_get_ability()`, `wp_get_abilities()`, `WP_Ability`, and the `/wp-json/wp-abilities/v1/...` REST namespace.

The AI plugin itself ships:

- A set of **abilities** (`includes/Abilities/*`) — Excerpt, Title, Summary, Meta Description, Image Generation, Alt Text, Content Classification, Refine/Review Notes, Content Resizing.
- A set of **experiments and features** (`includes/Experiments/*`, `includes/Features/Image_Generation/`) — UI‑facing wrappers around those abilities (editor panels, dashboard widgets, etc.).
- A **Settings page** (`Settings → AI`) for toggling features and configuring providers.
- An **AI Service singleton** (`WordPress\AI\Services\AI_Service`) that pre‑applies the site's preferred models to the SDK prompt builder.

> **Features vs experiments.** Internally there is one registry: features. The only entry registered by `Features\Loader::get_default_features()` is `Image_Generation` (the graduated feature). Default experiments are the classes listed in `Experiments::EXPERIMENT_CLASSES`, added through the same `wpai_default_feature_classes` filter at priority 9. `includes/Experiments/Example_Experiment/` is a reference implementation and is not registered by default. Third-party code can use the same filter, or the `wpai_register_features` action, to add its own feature.

---

## 2. End‑user setup

### 2.1 Prerequisites

Before activation, the plugin enforces four requirements (`includes/Requirements.php::get_requirements()`) and refuses to load with an admin notice if any fail:

- **PHP 7.4** or higher.
- **WordPress 7.0** or higher (the floor declared by `Requirements::MIN_WP_VERSION`; in practice this means a release that supports the AI primitives below).
- **`wp_supports_ai()` returning truthy** — the plugin will not run on a WP build where AI support is disabled.
- **Built front-end assets at `build/build.php`** — source-only checkouts must run `nvm use && npm ci && npm run build` before activating. Test suites can define `WPAI_IS_TEST` to bypass this check.

A provider connector plugin is also required for the plugin to do anything useful — the AI plugin itself ships zero provider credentials.

### 2.2 Install and configure

1. Install the plugin (`ai.php`) plus at least one **provider connector plugin** (OpenAI, Google, Anthropic, etc.).
2. Visit **Connectors** (`options-connectors.php`) and configure each provider you installed.
   - Paste the API key for each provider. Internally these are stored in options whose names are declared by the connector (`authentication.setting_name`).
3. Visit **Settings → AI**:
   - Turn on the global **Enable AI** setting. This is stored in the option `wpai_features_enabled`.
   - Toggle the features/experiments you want. Each feature's toggle is stored in the option `wpai_feature_{id}_enabled`.
4. For host/agency setups: pre‑seed the connector credential options, `wpai_features_enabled`, and the relevant `wpai_feature_{id}_enabled` options on behalf of users (e.g., from `wp-config.php` or `mu-plugins`) so end users don't need to BYO key. Pre-seeding works automatically for connectors whose `authentication.method` is `api_key` (the value is read with `get_option()`); for OAuth-, IAM-, or env-var-based providers, also implement the `wpai_has_ai_credentials` filter so the AI plugin recognizes the site as configured.

The **AI Status** dashboard widget (`includes/Admin/Dashboard/AI_Status_Widget.php`) shows which connectors are configured and which features are on. Its connector check uses `WordPress\AI\has_ai_credentials()` (option presence). The Settings page additionally calls `WordPress\AI\has_valid_ai_credentials()`, which first checks option presence and then runs a real probe via `wp_ai_client_prompt('Test')->is_supported_for_text_generation()`.

---

## 3. The Connectors API (AI providers)

The Connectors API is the integration boundary between the AI plugin and provider plugins. Provider plugins call `wp_register_connector( $slug, $data )` (provided by the PHP AI Client SDK loader). The AI plugin reads them with `wp_get_connectors()`.

### 3.1 Connector data shape

As consumed in `helpers.php` and `AI_Status_Widget.php`:

```php
array(
    'name'           => 'OpenAI',
    'type'           => 'ai_provider',           // The AI plugin filters on this exact value
    'authentication' => array(
        'method'        => 'api_key',            // Currently the only method auto-detected
        'setting_name'  => 'openai_api_key',     // wp_options key where the secret lives
    ),
    'plugin'         => array(                   // Optional — used for "is the provider plugin still active?"
        // Any one of these keys is accepted by AI_Status_Widget::is_connector_plugin_active():
        'file'        => 'openai-connector/openai-connector.php',
        // 'plugin_file' => '...',
        // 'pluginFile'  => '...',
    ),
)
```

### 3.2 Detection logic

`WordPress\AI\has_ai_credentials()` (`includes/helpers.php`) walks `wp_get_connectors()`, keeps only `type === 'ai_provider'` entries with `method === 'api_key'`, and treats the connector as configured when `get_option( $auth['setting_name'] )` is non‑empty.

### 3.3 Connector‑related hooks

| Hook | Type | Purpose |
|---|---|---|
| `wpai_has_ai_credentials` | filter — `bool $has, array $connectors` | Override the default "any key set" answer — useful for connectors that authenticate via OAuth, IAM, or env vars rather than an option. |
| `wpai_pre_has_valid_credentials_check` | filter — `bool\|null` | Short‑circuit the live probe (`is_supported_for_text_generation`). Return `true`/`false` to skip; return `null` to fall through. |
| `wpai_is_{slug}_connector_configured` | filter — `bool $configured, array $connector_data` | Per‑connector configured status, used by the AI Status widget. The dynamic portion is the connector slug. |

### 3.4 Adding your own connector

You don't touch this repo. Write a small companion plugin that calls `wp_register_connector()` on `init`. The AI plugin auto‑discovers it.

---

## 4. The Abilities API surface

Every AI ability in this plugin extends `WordPress\AI\Abstracts\Abstract_Ability`, which itself extends `WP_Ability` (from the Abilities API). Each ability declares input/output schemas, a permission callback, and an execute callback.

### 4.1 Three exposure layers

A registered ability can be reached in up to three ways depending on its `meta` array:

| Layer | How it's enabled | Reachable via |
|---|---|---|
| In-process (PHP) | Always — `wp_register_ability()` | `wp_get_ability( $name )->execute( $input )` from any PHP code |
| REST | `meta()` returns `[ 'show_in_rest' => true ]` | `POST /wp-json/wp-abilities/v1/abilities/{name}/run` |
| MCP tool | `meta()` returns `[ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ]` | Whatever MCP transport the host exposes (separate from the REST namespace above) |

Built-in abilities mix these layers. `ai/get-post-details` (`includes/Abilities/Utilities/Posts.php`) registers all three (`show_in_rest` and `mcp`). `ai/get-post-terms` registers as MCP-only and is **not** reachable via REST. Most feature abilities (`ai/excerpt-generation`, `ai/title-generation`, `ai/content-resizing`, `ai/meta-description`, `ai/content-classification`, `ai/summarization`, etc.) register `show_in_rest` only.

### 4.2 Calling abilities from PHP

```php
$ability = wp_get_ability( 'ai/excerpt-generation' );
if ( $ability ) {
    $result = $ability->execute( array(
        'content' => 'Long article text...',
        'context' => 123,                 // Or a post ID — the ability will pull post context
    ) );
    if ( is_wp_error( $result ) ) { /* handle */ }
}
```

Built‑in ability names follow the pattern `ai/{slug}`:

- `ai/excerpt-generation`
- `ai/title-generation`
- `ai/summarization`
- `ai/meta-description`
- `ai/content-classification`
- `ai/content-resizing`
- `ai/refine-notes`
- `ai/review-notes`
- `ai/image-generation`, `ai/image-prompt-generation`, `ai/image-import`
- `ai/alt-text-generation`
- `ai/get-post-details`, `ai/get-post-terms` (utilities used by the others)

### 4.3 Calling abilities over REST

A registered ability whose `meta()` returns `[ 'show_in_rest' => true ]` is exposed at:

```
POST /wp-json/wp-abilities/v1/abilities/{ability_name}/run
```

Built-in feature abilities are registered by their corresponding feature or experiment, so their REST endpoints exist only after the global AI toggle and that feature's toggle allow the feature to run `register()`. The post utility abilities, `ai/get-post-details` and `ai/get-post-terms`, are registered during plugin initialization outside the feature registry.

The body must be `{ "input": { ... } }` (note the wrapper). Authenticate with an Application Password or cookie + nonce. Example:

```bash
curl -X POST "https://example.test/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{"input":{"content":"..."}}'
```

Common errors (401/405/400/404) are documented in [`TESTING_REST_API.md`](TESTING_REST_API.md).

#### Permissions for built-in abilities

Each ability runs its own `permission_callback` against the supplied input, so the capability the caller needs depends on the ability *and* the input shape. The defaults for built-in abilities:

| Ability | When the input includes a post / attachment ID | When called without an ID |
|---|---|---|
| `ai/excerpt-generation`, `ai/title-generation`, `ai/summarization`, `ai/meta-description` | `edit_post` on that post + post type must have `show_in_rest` | `edit_posts` |
| `ai/refine-notes`, `ai/review-notes` | `edit_post` on that post + post type must have `show_in_rest` | `edit_posts` |
| `ai/content-resizing` | `edit_post` on that post | `edit_posts` |
| `ai/content-classification` | `edit_post` on that post + post type must have `show_in_rest` | `edit_posts` |
| `ai/get-post-details`, `ai/get-post-terms` | `edit_post` on the post (post ID is required input) | n/a |
| `ai/image-prompt-generation` | `edit_post` on that post | `edit_posts` |
| `ai/image-generation`, `ai/image-import` | n/a | `upload_files` |
| `ai/alt-text-generation` | `edit_post` on the attachment | `upload_files` |

The Content Classification editor UI additionally gates its asset enqueue on `current_user_can( 'manage_categories' )` plus the post type being attached to `category` or `post_tag` — so a user with `edit_posts` but not `manage_categories` can call `ai/content-classification` over REST but won't see the in-editor "Suggest Tags / Categories" buttons.

### 4.4 Registering your own ability

Subclass `Abstract_Ability` and register it on `wp_abilities_api_init` with `wp_register_ability()`, usually from your feature's `register()` method. This is the pattern used by the feature classes in `includes/Experiments/*` and `includes/Features/Image_Generation/Image_Generation.php`.

```php
add_action( 'wp_abilities_api_init', function (): void {
    wp_register_ability(
        'ai/my-ability',
        array(
            'label'         => __( 'My Ability', 'my-plugin' ),
            'description'   => __( 'Does something useful.', 'my-plugin' ),
            'ability_class' => My_Ability::class,
        )
    );
} );
```

Required overrides:

- `input_schema()`
- `output_schema()`
- `execute_callback( $input )`
- `permission_callback( $input )`
- `meta()` — return `[ 'show_in_rest' => true ]` to expose over REST, and/or `[ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ]` to expose as an MCP tool

Optional:

- `guideline_categories()` — return any of `'site' | 'copy' | 'images' | 'additional'` to have site editorial guidelines automatically appended to your system instruction (see [§4.5 Editorial Guidelines](#45-editorial-guidelines)).
- A `system-instruction.php` file next to your ability class that returns a string. `Abstract_Ability::get_system_instruction()` will auto‑load it via reflection and `extract()` any `$data` you pass in as variables.

The result of your system instruction is filterable via `wpai_system_instruction` (filter — `string $instruction, string $name, array $data`).

### 4.5 Editorial Guidelines

The plugin can inject site-wide editorial guidance into every ability's system instruction so all abilities share a consistent tone, vocabulary, and policy. Guidelines come from a single `wp_guideline` custom post type managed by Gutenberg ≥ 23.0; the plugin only consumes them.

#### The `wp_guideline` CPT

`Guidelines::is_available()` (`includes/Services/Guidelines.php`) returns true when the `wp_guideline` post type is registered. The service reads the most recent guideline post (`publish` or `draft`), then pulls these post-meta keys:

| Category slug | Post meta key | XML tag in the prompt |
|---|---|---|
| `site` | `_guideline_site` | `<site-context>` |
| `copy` | `_guideline_copy` | `<copy-guidelines>` |
| `images` | `_guideline_images` | `<image-guidelines>` |
| `additional` | `_guideline_additional` | `<additional-guidelines>` |
| Block-specific | `_guideline_block_{sanitized_block_name}` (the `/` in block names is replaced with `_`, e.g. `core/paragraph` → `core_paragraph`) | `<block-guidelines>` |

If the CPT isn't registered (Gutenberg < 23.0 or no plugin registers it), `format_for_prompt()` returns an empty string and ability prompts are unaffected.

#### Wiring an ability to guidelines

Implement `guideline_categories()` on your ability. Return any subset of `'site' | 'copy' | 'images' | 'additional'`. `Abstract_Ability::get_system_instruction()` then prepends the matching guideline blocks (wrapped in `<guidelines>...</guidelines>`) to your `system-instruction.php` output.

```php
class My_Ability extends Abstract_Ability {
    protected function guideline_categories(): array {
        return array( 'site', 'copy' );
    }
}
```

#### Helpers for non-ability code

If you generate text outside the Abilities API but still want guideline injection:

```php
$xml = WordPress\AI\format_guidelines_for_prompt(
    array( 'site', 'copy' ),
    'core/paragraph'  // optional block name → adds <block-guidelines>
);

$keyed = WordPress\AI\get_guidelines();          // all categories
$copy  = WordPress\AI\get_guidelines( 'copy' );  // single category
```

#### Filters

| Filter | Type | Use |
|---|---|---|
| `wpai_use_guidelines` | filter — `bool` (default `true`) | Globally disable injection without un-registering the CPT (e.g., `__return_false` in staging). |
| `wpai_max_guideline_length` | filter — `int` (default `5000`) | Cap the per-category character length used in `format_for_prompt()`; longer content is truncated with `mb_substr`. |

---

## 5. Generating with the AI Service / SDK

For non‑ability code paths, use the `AI_Service` singleton — it auto‑applies the site's preferred model list before handing you a prompt builder:

```php
use function WordPress\AI\get_ai_service;

$service = get_ai_service();

// Simple
$text = $service->create_textgen_prompt( 'Summarize this text' )->generate_text();

// With options (snake_case → mapped to SDK ModelConfig camelCase)
$text = $service->create_textgen_prompt( 'Translate to French', array(
    'system_instruction' => 'You are a translator.',
    'temperature'        => 0.3,
    'max_tokens'         => 500,
) )->generate_text();

// Chain SDK builder methods directly
$titles = $service->create_textgen_prompt( 'Generate titles' )
    ->using_candidate_count( 5 )
    ->generate_texts();
```

Recognized option keys (mapped in `AI_Service::$option_key_map`): `system_instruction`, `candidate_count`, `max_tokens`, `temperature`, `top_p`, `top_k`, `stop_sequences`, `presence_penalty`, `frequency_penalty`, `logprobs`, `top_logprobs`.

If you need to bypass the service, call `wp_ai_client_prompt( $prompt )` directly — that's the SDK entry point — and chain `using_system_instruction`, `using_temperature`, `using_model_preference`, `using_model_config`, `is_supported_for_text_generation`, `is_supported_for_image_generation`, `generate_text`, `generate_texts`, etc.

### 5.1 Model preference filters

The plugin ships sensible defaults but you can override them per‑site:

| Filter | What it returns |
|---|---|
| `wpai_preferred_text_models` | `[[provider, model_id], ...]` for text generation |
| `wpai_preferred_image_models` | Same shape for image generation |
| `wpai_preferred_vision_models` | Same shape for vision (e.g., alt‑text) |

Each entry is `[ 'anthropic', 'claude-sonnet-4-6' ]` style. The first one supported by an installed connector wins.

---

## 6. The Experiment / Feature Framework

This is how the user‑facing features are packaged. Everything lives under `includes/Experiments/{Name}/` (or `includes/Features/{Name}/` for graduated/non‑experimental ones), each class extending `WordPress\AI\Abstracts\Abstract_Feature` and implementing the `Feature` contract.

### 6.1 Lifecycle

`WordPress\AI\Features\Loader::init()` runs on plugin boot:

1. Builds default features from the `wpai_default_feature_classes` filter (a map of `id => class-string`). The plugin's own experiments are added by `Experiments::register_default_experiment_classes()` at priority 9.
2. Fires `do_action( 'wpai_register_features', $registry )` so third parties can call `$registry->register_feature( new My_Feature() )` directly with a constructed instance.
3. Applies the loader-level `wpai_features_enabled` filter. If that filter returns false, no features are registered and `wpai_features_initialized` does not fire. Otherwise, each registered feature runs `is_enabled()`; that requires the global option `wpai_features_enabled`, then reads the feature option `wpai_feature_{id}_enabled`, then applies the per-feature filter before calling the feature's `register()` method.
4. Fires `do_action( 'wpai_features_initialized' )`.

### 6.2 Adding a feature

```php
namespace My\Plugin;

use WordPress\AI\Abstracts\Abstract_Feature;

// Point this at your plugin's main file before using plugins_url().
const MY_PLUGIN_FILE = __FILE__;

class My_Feature extends Abstract_Feature {
    public static function get_id(): string { return 'my-feature'; }

    protected function load_metadata(): array {
        return array(
            'label'       => __( 'My Feature', 'my-plugin' ),
            'description' => __( 'Adds X to the editor.', 'my-plugin' ),
        );
    }

    public function register(): void {
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets(): void {
        wp_enqueue_script(
            'my-plugin-my-feature',
            plugins_url( 'build/my-feature.js', MY_PLUGIN_FILE ),
            array( 'wp-api-fetch', 'wp-element' ),
            '1.0.0',
            array( 'strategy' => 'defer' )
        );

        wp_localize_script( 'my-plugin-my-feature', 'MyFeatureData', array(
            'enabled' => $this->is_enabled(),
        ) );
    }
}

add_filter( 'wpai_default_feature_classes', function ( array $classes ): array {
    $classes[ My_Feature::get_id() ] = My_Feature::class;
    return $classes;
} );
```

#### Adding feature-specific settings

To expose toggles, dropdowns, or numeric inputs on **Settings → AI**, override `register_settings()` to hook into the WordPress Settings API (using the `Settings_Registration::OPTION_GROUP` constant) *and* `get_settings_fields()` to describe how each field should render in the React UI:

```php
use WordPress\AI\Settings\Settings_Registration;

class My_Feature extends Abstract_Feature {
    // ...id, metadata, register, enqueue_assets omitted...

    public function register_settings(): void {
        register_setting(
            Settings_Registration::OPTION_GROUP,
            $this->get_field_option_name( 'mode' ),
            array(
                'type'              => 'string',
                'default'           => 'fast',
                'sanitize_callback' => array( $this, 'sanitize_mode' ),
                'show_in_rest'      => array(
                    'schema' => array(
                        'type' => 'string',
                        'enum' => array( 'fast', 'thorough' ),
                    ),
                ),
            )
        );
    }

    public function get_settings_fields(): array {
        return array(
            array(
                'id'       => 'mode',
                'label'    => __( 'Generation mode', 'my-plugin' ),
                'type'     => 'text',
                'default'  => 'fast',
                'elements' => array(
                    array( 'value' => 'fast',     'label' => __( 'Fast', 'my-plugin' ) ),
                    array( 'value' => 'thorough', 'label' => __( 'Thorough', 'my-plugin' ) ),
                ),
            ),
        );
    }

    public function sanitize_mode( $value ): string {
        return in_array( $value, array( 'fast', 'thorough' ), true ) ? $value : 'fast';
    }
}
```

Mechanics:

- **Option naming.** `get_field_option_name( 'mode' )` resolves to `wpai_feature_my-feature_field_mode`. Always go through this helper instead of hard-coding the option name — it keeps your settings co-located with the feature ID and lets `Settings_Registration` collect them into the `ai_experiments` option group automatically.
- **Field IDs are short.** In `get_settings_fields()` use `'mode'`, not the namespaced form. `Settings_Page::get_settings_feature_metadata()` calls `get_field_option_name()` per field before sending the payload to the React UI.
- **Field shape.** The `id`, `label`, `type`, `default`, `elements`, and `isValid` keys map to Gutenberg's DataForm Field contract. `Content_Classification` (`includes/Experiments/Content_Classification/Content_Classification.php`) is the canonical reference for `text` (with `elements`) and `integer` (with `isValid: { min, max }`) field types.

### 6.3 Feature/experiment hooks summary

| Hook | Type | Purpose |
|---|---|---|
| `wpai_default_feature_classes` | filter | Map of `id => class-string`. Add, remove, or replace defaults. |
| `wpai_register_features` | action | Receives `$registry`. Best for runtime registration with a pre‑built instance. |
| `wpai_features_initialized` | action | Fires once all enabled features have run `register()`. Does not fire if the loader-level `wpai_features_enabled` filter returns false. |
| `wpai_features_enabled` | filter | Loader-level hard kill switch for *all* features (e.g., `__return_false` in staging). This is separate from the global option of the same name. |
| `wpai_feature_{id}_enabled` | filter | Override the individual feature option after the global option has allowed feature checks to continue. It cannot enable a feature while the global option is false. |
| `wpai_settings_feature_groups` | filter — `array $groups` | Override the `id => { label, description, order }` map of group headers shown on Settings → AI. Defaults cover the `editor`, `admin`, and `other` categories. |
| `wpai_settings_feature_metadata` | filter — `array $metadata, Registry $registry` | Final pass over the full `{ groups, features }` payload sent to the React settings UI. Use to inject custom field types, hide a feature, or override labels. |
| Option `wpai_features_enabled` | option | Backing store for the global **Enable AI** setting. Required before any feature's `is_enabled()` can return true. |
| Option `wpai_feature_{id}_enabled` | option | Backing store toggled from Settings → AI. |
| Option `wpai_feature_{id}_field_{option_name}` | option | Backing store for custom per‑feature settings fields registered by a feature. |

### 6.4 Disabling defaults

```php
// Disable one feature
add_filter( 'wpai_feature_excerpt-generation_enabled', '__return_false' );

// Remove from registration entirely
add_filter( 'wpai_default_feature_classes', function ( array $c ): array {
    unset( $c['summarization'] );
    return $c;
} );

// Kill switch
add_filter( 'wpai_features_enabled', '__return_false' );
```

The per-feature filter can disable a feature or enable it when the global option is already on. To enable a feature from code on a site where the global option is off, also set or filter the global option path instead of relying on `wpai_feature_{id}_enabled` alone.

---

## 7. Asset Loading

> **Note:** `Asset_Loader` is marked `@internal` in this plugin. It's safe to use within features that ship inside this plugin, but external plugins should consider it unstable and prefer `wp_enqueue_script` / `wp_enqueue_style` directly. The notes below describe what it does so you can mirror the behavior if you need.

`WordPress\AI\Asset_Loader` reads the `*.asset.php` dependency manifests produced by `@wordpress/scripts` from `build-scripts/` and registers them with WordPress:

```php
Asset_Loader::enqueue_script( 'my-feature', 'experiments/my-feature' );
Asset_Loader::enqueue_style( 'my-feature', 'experiments/my-feature' );
Asset_Loader::localize_script( 'my-feature', 'MyFeatureData', array( 'foo' => 'bar' ) );
```

Two prefixing rules apply:

- The **registered script/style handle** is prefixed with `ai_`. The example above registers `ai_my-feature`.
- The **localized JS object** name is prefixed with `ai`. The example above creates `window.aiMyFeatureData`.

Source files live in `src/`; entry points are configured in `webpack.config.js`. Scripts are enqueued with `defer`. Styles automatically register an RTL counterpart (`*-rtl.css`) if present.

---

## 8. Other useful filters

### 8.1 Cross-cutting filters

| Filter | Where | Use |
|---|---|---|
| `wpai_pre_normalize_content` | `helpers.php` (`normalize_content`) | Mutate content before HTML stripping. |
| `wpai_normalize_content` | `helpers.php` (`normalize_content`) | Final tweak of the normalized string handed to abilities. |
| `wpai_system_instruction` | `Abstract_Ability::get_system_instruction()` | Inject site‑specific tone/policy into every ability's system prompt. |
| `wpai_has_ai_credentials` | `helpers.php` (`has_ai_credentials`) | Mark site as credentialed even when no API key is in options. |
| `wpai_pre_has_valid_credentials_check` | `helpers.php` (`has_valid_ai_credentials`) | Skip the live `is_supported_for_text_generation()` probe (e.g., during tests). |
| `wpai_use_guidelines` | `Services\Guidelines::should_use_guidelines()` | Globally enable/disable editorial guideline injection (see [§4.5](#45-editorial-guidelines)). |
| `wpai_max_guideline_length` | `Services\Guidelines::format_for_prompt()` | Per-category character cap (default 5000). |
| `wpai_ability_category` | `Experiments\Abilities_Explorer\Ability_Handler` | Override the category label shown for an ability in the Abilities Explorer UI. Receives `$category_label, $slug`. |

### 8.2 Ability-specific filters

| Filter | Where | Signature |
|---|---|---|
| `wpai_get_post_details` | `Abilities\Utilities\Posts` (`ai/get-post-details`) | `array $details, int $post_id, array $fields` — mutate the `{ content, title, slug, ... }` array. |
| `wpai_get_post_terms` | `Abilities\Utilities\Posts` (`ai/get-post-terms`) | `WP_Term[] $terms, int $post_id, string[] $allowed_taxonomies` — mutate the term list. |
| `wpai_content_classification_strategy` | `Experiments\Content_Classification` | `string $strategy` — override `existing_only` / `allow_new` independently of the stored option. |
| `wpai_content_classification_max_suggestions` | `Experiments\Content_Classification` | `int $max` — override the max suggestion count (clamped 1–10). |
| `wpai_content_classification_prompt` | `Abilities\Content_Classification` | `string $prompt, string $context, string $taxonomy, array $assigned_terms, array $available_terms` — replace the constructed user prompt before sending to the model. |
| `wpai_content_classification_suggestions` | `Abilities\Content_Classification` | `array $suggestions, string $taxonomy, string $strategy` — post-process the parsed suggestion array (e.g., drop low-confidence ones). |
| `wpai_meta_description` | `Experiments\Meta_Description` | `string $meta_description` — final hook before the value is returned/stored. |
| `wpai_meta_description_prompt` | `Abilities\Meta_Description` | `string $prompt, string $content, string $title` — mutate the user prompt before model call. |
| `wpai_meta_description_result_temperature` | `Abilities\Meta_Description` | `float $temperature` (default `0.7`) — controls how aggressively the model varies its output across regenerations. |
| `wpai_meta_description_meta_key` | `Abilities\Meta_Description\SEO_Integration` | `string $key, string $plugin_slug` — override the post-meta key used when writing back to the SEO plugin. |
| `wpai_meta_description_seo_plugins` | `Abilities\Meta_Description\SEO_Integration` | `array $plugins` — add or remove SEO plugins from the auto-detection list. |

---

## 9. JS / Block editor integration

Block‑editor‑side features generally:

1. Localize a `window.ai{Name}Data` blob with the feature's enabled state and any per-feature configuration (`Asset_Loader::localize_script()` adds the `ai` prefix automatically — see §7).
2. Call abilities through the `runAbility()` helper at `src/utils/run-ability.ts` rather than hitting REST directly:
   - The helper prefers `window.wp.abilities.executeAbility` when the upstream Abilities API client script is on the page (it knows about request/response shape, nonces, and extension points).
   - It transparently falls back to `wp.apiFetch` against `/wp-abilities/v1/abilities/{name}/run` with `{ input: { ... } }` when the client script isn't available, logging a one-shot console warning.
   - Pass `{ method: 'GET' | 'DELETE' }` in the third argument when an ability requires a non-POST verb; the helper serializes `input` into the query string for those.
3. Use `@wordpress/data` and Gutenberg slot‑fills (sidebar plugins, block toolbar items) to surface buttons.

```ts
import { runAbility } from '../../utils/run-ability';

const result = await runAbility< { title: string } >(
    'ai/title-generation',
    { context: String( postId ) }
);
```

The **Abilities Explorer** experiment (`Tools → Abilities Explorer`) is the easiest way to see live schemas and exercise endpoints without writing JS.

---

## 10. Where to look in the repo

| Need | File |
|---|---|
| Plugin bootstrap | `ai.php`, `includes/Main.php` |
| Ability base class | `includes/Abstracts/Abstract_Ability.php` |
| Feature base + contract | `includes/Abstracts/Abstract_Feature.php`, `includes/Contracts/Feature.php` |
| Feature registration loop | `includes/Features/Loader.php`, `includes/Features/Registry.php` |
| Experiment registration | `includes/Experiments/Experiments.php` |
| AI provider plumbing | `includes/Services/AI_Service.php`, `includes/helpers.php` |
| Editorial guidelines service | `includes/Services/Guidelines.php` |
| Settings page + registration | `includes/Settings/Settings_Page.php`, `includes/Settings/Settings_Registration.php` |
| JS ability helper | `src/utils/run-ability.ts` |
| REST API testing recipes | `docs/TESTING_REST_API.md` |
| Architecture overview | `docs/ARCHITECTURE_OVERVIEW.md` |
| Per‑experiment specs | `docs/experiments/*.md`, `docs/features/image-generation.md` |
| Reference experiment | `includes/Experiments/Example_Experiment/` |

---

## 11. Quick reference — common integration recipes

**Generate text from anywhere in PHP:**
```php
$text = WordPress\AI\get_ai_service()
    ->create_textgen_prompt( 'Write a haiku about WordPress' )
    ->generate_text();
```

**Run a built‑in ability programmatically:**
```php
$summary = wp_get_ability( 'ai/summarization' )->execute( array( 'content' => $body ) );
```

**Add an editorial guideline category to your custom ability:**
```php
protected function guideline_categories(): array { return array( 'site', 'copy' ); }
```

**Override preferred model for a single site:**
```php
add_filter( 'wpai_preferred_text_models', fn() => array(
    array( 'anthropic', 'claude-sonnet-4-6' ),
) );
```

**Enable a feature only for editors and above:**
```php
add_filter( 'wpai_feature_review-notes_enabled',
    fn( $on ) => $on && current_user_can( 'edit_others_posts' )
);
```

**Check from the front end whether AI is usable before showing UI:**
```php
if ( WordPress\AI\has_valid_ai_credentials() ) { /* render button */ }
```

---

*Generated against `ai.php` v0.8.0. If you change a hook signature or add a new connector field, please update this guide.*
