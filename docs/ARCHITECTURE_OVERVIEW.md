# Architecture Overview

This page explains how the plugin is put together: how it boots, how its two extension mechanisms (Abilities and Features/Experiments) work, how it talks to AI providers, and which functions, classes, and hooks make up its developer-facing surface. For a step-by-step tutorial on building a new experiment, see the [Developer Guide](DEVELOPER_GUIDE.md); this page explains the system that guide's steps plug into.

## Features vs. Experiments

The plugin ships most of its functionality as **Experiments**: opt-in, early-stage units of functionality that a site enables individually under Settings → AI. A small number of experiments graduate to **Features** — stable, generally-available functionality, like the one shipped example, Image Generation (`includes/Features/Image_Generation/`). Both implement the same `Contracts\Feature` interface and go through the same registration and enable/disable machinery described below; only their `stability` metadata and default visibility differ. See [Feature & Experiment Lifecycle](FEATURE_EXPERIMENT_LIFECYCLE.md) for the policy on when something graduates.

## Directory Map

```
ai/
├── ai.php                       # Plugin bootstrap
├── includes/                    # All PHP source (PSR-4, WordPress\AI\ namespace)
│   ├── Abilities/               # WordPress Ability implementations, one directory per ability
│   ├── Abstracts/               # Abstract_Ability, Abstract_Feature, Abstract_Gated_Ability
│   ├── Admin/                   # Activation/Deactivation/Uninstall, Upgrades, Site Health, Dashboard
│   ├── CLI/                     # WP-CLI commands (wp ai embeddings, wp ai alt-text)
│   ├── Connector_Approval/      # Attributes and gates outbound AI requests per connector
│   ├── Contracts/               # The Feature interface
│   ├── Embeddings/              # Portable vector storage and similarity math (no WP hooks of its own)
│   ├── Experiments/             # One directory per experiment, plus the Experiments registrar
│   ├── Features/                # Feature/Experiment registry and loader machinery
│   ├── Logging/                 # AI Request Logging experiment's backing code
│   ├── REST/                    # Plugin-wide REST controllers (providers, settings import/export)
│   ├── Services/                # Guidelines service; the deprecated AI_Service
│   ├── Settings/                # Settings screen and the per-feature options it registers
│   ├── Vendor/                  # Vendored third-party code (SDK overlay, Secrets)
│   ├── Asset_Loader.php         # wp-scripts asset enqueue/localize helper
│   ├── Deprecated.php           # Back-compat shims for renamed hooks
│   ├── Main.php                 # Bootstrap orchestration (see below)
│   ├── Requirements.php         # Environment checks gating Main::load()
│   ├── SDK_Overlay.php          # Backports newer AI Client SDK classes when needed
│   └── helpers.php              # The plugin's public function library
├── docs/                        # This documentation
├── src/                         # JS/SCSS source for admin and experiment UIs
├── tests/                       # PHPUnit integration tests and Playwright e2e tests
└── uninstall.php                # Delegates to Admin\Uninstall
```

## Bootstrap & Request Lifecycle

1. **`ai.php`** defines the `WPAI_*` constants, requires `includes/autoload.php` (a small PSR-4 `spl_autoload_register()` for the `WordPress\AI\` namespace — new classes need no registration, only the right file path), calls `SDK_Overlay::register()` to backport any AI Client SDK classes the environment's own copy is missing, and instantiates `Main::get_instance()`.
2. **`Main::setup()`** hooks `Main::load()` onto `plugins_loaded` and registers activation/deactivation callbacks. Nothing else runs before `plugins_loaded`.
3. **`Main::load()`** (on `plugins_loaded`) bails out via `Requirements::are_requirements_met()` if the environment doesn't qualify (PHP/WP version, AI support), then requires `includes/helpers.php`, runs any pending `Admin\Upgrades`, initializes `Deprecated` back-compat shims, and defers the rest to `init`:
   - `init` priority 15 → `Main::initialize_features()`
   - `init` priority 20 → `Main::register_provider_data()` (exposes connector availability to scripts via `Asset_Loader`)
   - `wp_abilities_api_categories_init` → registers the shared `ai-experiments` ability category every built-in ability uses.
4. **`Main::initialize_features()`** runs, in order: `Experiments::init()` (registers built-in experiment classes onto the `wpai_default_feature_classes` filter), constructs the one `Features\Registry`, runs `Features\Loader::init()` (resolves default feature classes, fires `wpai_register_features` for third parties, then calls `register()` on every enabled feature), initializes `Settings\Settings_Registration`, and — only in the relevant contexts — the Settings admin page, dashboard widgets, Site Health integration, and the `wp ai embeddings` WP-CLI command. The whole method is wrapped in a `try`/`catch` that reports failures via `_doing_it_wrong()` rather than fataling the site.

## The Feature/Experiment Framework

Every experiment and feature implements `Contracts\Feature` (`get_id()`, `get_label()`, `get_description()`, `get_category()`, `get_stability()`, `get_capability()`, `register()`, plus the enabled-state and settings-metadata methods below), almost always by extending `Abstracts\Abstract_Feature` rather than the interface directly. `Abstract_Feature`'s constructor calls the subclass's `load_metadata()` (must return `label` and `description`; `category`, `stability`, `image`, and `capability` are optional) and validates the result.

Enabled state is two independent checks, both cached per instance:
- `is_globally_enabled()` reads the single `wpai_features_enabled` **option** (the plugin-wide Settings → AI toggle).
- `is_individually_enabled()` reads the per-feature option `wpai_feature_{$id}_enabled`, filterable via `wpai_feature_{$id}_enabled`.
- `is_enabled()` is true only when both are.

Registration flow, tying back to the bootstrap sequence above:

```
Experiments::init()                              // adds built-in classes to the filter, priority 9
  → wpai_default_feature_classes (filter)        // third parties add their own classes here too
Features\Loader::init()
  → get_default_features()                        // reads the filter, instantiates each class
  → do_action( 'wpai_register_features', $registry ) // third parties can register instances directly instead
  → for each feature where is_enabled(): $feature->register()
  → do_action( 'wpai_features_initialized' )
```

A feature's `register()` is where it hooks into WordPress — registering abilities, REST routes, admin pages, or (as covers a whole module) constructing and initializing a manager class that owns its own hooks, schema, and cron events. `AI_Request_Logging::register()` (which delegates to `Logging\AI_Request_Log_Manager::init()`) is a representative example; see [AI Request Logging](experiments/ai-request-logging.md) and the [experiment framework reference](experiments/experiment-framework.md) for the full detail this section deliberately doesn't repeat.

A feature can also declare simple custom settings by overriding `get_settings_fields()` (rendered as a DataForm on the settings page, backed by `register_setting()` calls `Settings\Settings_Registration` makes automatically for every registered feature) — see [Content Classification](experiments/content-classification.md) for a working example. Separately, **every** registered feature automatically gets a `wpai_feature_{$id}_field_developer` option (provider + model) it did not have to declare itself; `WordPress\AI\get_feature_developer_model_config( $feature_id )` reads it back, and `Abstract_Ability::set_provider_model_preference()` uses it to let a site override which provider/model an ability uses.

## The Abilities System

Abilities are registered individually, on the `wp_abilities_api_init` action, by whichever feature owns them — there is no central abilities registry. The pattern (see `Experiments\Title_Generation\Title_Generation::register()` and `register_abilities()`):

```php
public function register(): void {
    add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
}

public function register_abilities(): void {
    wp_register_ability(
        'ai/' . $this->get_id(),
        array(
            'label'         => $this->get_label(),
            'description'   => $this->get_description(),
            'ability_class' => My_Ability::class,
        )
    );
}
```

The ability class itself extends `Abstracts\Abstract_Ability` (a `WP_Ability` subclass living in `includes/Abilities/{Name}/{Name}.php`), implementing `input_schema()`, `output_schema()`, `execute_callback()`, `permission_callback()`, and `meta()`. `Abstract_Ability` layers in optional editorial-guideline injection (`guideline_categories()`), a file-based system-instruction convention (`system-instruction.php` alongside the ability class), and the developer model-override helper mentioned above.

A second pattern exists for abilities meant to be enumerable and independently toggleable rather than tied to one editor experience: `Abilities\Gated\Gated_Abilities` is a static registry (filterable via `wpai_gated_abilities`) of `Abstract_Gated_Ability` subclasses, all registered together by the Custom Abilities experiment. See [Custom Abilities](experiments/custom-abilities.md) and [Abilities Explorer](experiments/abilities-explorer.md).

## AI Provider Integration

This plugin does not ship provider credentials or provider SDKs for OpenAI, Anthropic, Google, etc. — those live in separate **AI Connector** plugins that register themselves as `ai_provider`-type connectors in WordPress core's connector registry (`wp_get_connectors()`). `WordPress\AI\get_ai_connectors()` (`includes/helpers.php`) filters that registry down to `ai_provider` entries, and `has_ai_credentials()` / `has_valid_ai_credentials()` build on it to answer whether a working connector is available at all.

Generation itself goes through the vendored **AI Client SDK** (`WordPress\AiClient\AiClient`, bundled in WordPress core as of 7.0 and backported by `SDK_Overlay` where the environment's copy is older or missing a capability). `get_preferred_models_for_text_generation()`, `get_preferred_image_models()`, and `get_preferred_vision_models()` — each backed by its own filter (`wpai_preferred_text_models`, etc.) — give abilities a fallback list to try in order when no explicit model is configured. Full detail, including capability checks (`ensure_text_generation_supported()` and friends on `Abstract_Ability`), is in [Multi-Provider Support](experiments/multi-provider-support.md); the criteria for a Connector plugin to be listed as "featured" are in [Featured Connectors](FEATURED_CONNECTORS.md).

## Cross-Cutting Subsystems

These modules sit beside the Abilities/Experiments framework rather than inside it — some (Logging) are the backing implementation for one experiment; others (Embeddings, REST, CLI, Admin) are plumbing several experiments and the plugin bootstrap itself depend on.

| Module | What it does |
|---|---|
| `Logging/` | Backs the AI Request Logging experiment: wraps the SDK's HTTP transporter to record every AI request. See [AI Request Logging](experiments/ai-request-logging.md). |
| `Connector_Approval/` | Lets a site require explicit approval before a plugin/theme's outbound AI requests are allowed; attributes each request to its calling plugin. See [Connector Approval](experiments/connector-approval.md). |
| `Vendor/Secrets/` (Key Encryption experiment) | Encrypts connector API keys at rest via a vendored libsodium-based secrets manager. See [Key Encryption](experiments/key-encryption.md). |
| `Embeddings/` | Portable storage (`wpai_embeddings` table) and pure-PHP similarity math for embedding vectors. Deliberately has no WordPress hooks of its own — generation and any automatic (re-)indexing are the job of consumers like the `wp ai embeddings` CLI command. See [Storing Embeddings](experiments/embeddings.md). |
| `Admin/` | Activation/Deactivation, versioned `Upgrades/`, `Uninstall` (removes the plugin's tables, `wpai_`-prefixed options, transients, and scheduled events unless `wpai_remove_data_on_uninstall` returns `false`), Site Health, and dashboard widgets. |
| `REST/` + per-experiment `REST/` controllers | See the endpoint inventory below. |
| `CLI/` | `wp ai embeddings` (generate/compare vectors) and `wp ai alt-text`, registered only when `WP_CLI` is defined. |

### REST API inventory

Every route lives under the `ai/v1` namespace:

| Route | Registered by |
|---|---|
| `GET /ai/v1/providers` | `REST\Models_Controller` |
| `GET /ai/v1/settings/export`, `POST /ai/v1/settings/import` | `REST\Settings_IO_Controller` — see [Settings Import/Export](admin/settings-import-export.md) |
| `GET/POST /ai/v1/connector-approvals`, `DELETE /ai/v1/connector-approvals/pending` | `Connector_Approval\REST_Controller` |
| `GET /ai/v1/logs`, `/logs/summary`, `/logs/filters`, `/logs/{id}` | `Logging\REST\AI_Request_Log_Controller` |

## Developer-Facing API Reference

Functions in `includes/helpers.php` with a stable `@since` tag are this plugin's public PHP API; classes not explicitly documented here or in a linked page should be treated as internal.

### Functions

| Function | Since | Purpose |
|---|---|---|
| `log_ai_request( array $data )` | 1.3.0 | Writes an entry to the AI Request Log from outside the SDK's own HTTP transporter — for an MCP server or any code invoking an ability directly. Returns the log ID, or `false` when logging is disabled or the write failed. |
| `generate_embeddings( $input, array $args )` | 1.3.0 | Generates one or more embedding vectors. Requires an explicit `provider` and `model` in `$args`; nothing is chosen automatically, because vectors from different models are not comparable. |
| `supports_embedding_generation()` | 1.3.0 | Whether the AI Client SDK's embedding classes are available in this environment. |
| `get_ai_connectors( bool $active_only = true )` | 0.9.0 | Registered `ai_provider` connectors, optionally filtered to those whose plugin is active. |
| `has_ai_credentials()` / `has_valid_ai_credentials()` | 0.1.0 | Whether any / a working AI connector is configured. |
| `get_preferred_models_for_text_generation()`, `get_preferred_image_models()`, `get_preferred_vision_models()` | 0.2.0–0.3.0 | Ordered fallback model lists for abilities that don't have an explicit model configured. Each has a matching filter. |
| `get_guidelines( ?string $category )`, `format_guidelines_for_prompt( array $categories, ?string $block_name )` | 0.8.0 | Reads and formats the site's editorial guidelines for prompt injection. See the Editorial Guidelines section of the [Developer Guide](DEVELOPER_GUIDE.md). |
| `get_feature_developer_model_config( string $feature_id )` | 0.9.0 | Reads the provider/model a site configured in a feature's Developer Options. |

### Filters and actions

| Hook | Fires when |
|---|---|
| `wpai_default_feature_classes` (filter) | Collecting the class list for every feature/experiment. Add a class here to register a new one, keyed by nothing in particular — `Loader` re-keys by `get_id()`. |
| `wpai_register_features` (action) | After default classes are resolved; receives the `Registry` directly, for registering an already-constructed instance instead of a class name. |
| `wpai_features_initialized` (action) | After every enabled feature's `register()` has run. |
| `wpai_features_enabled` (filter) | Global kill switch checked before any feature is initialized, independent of the `wpai_features_enabled` **option** the Settings page toggles. |
| `wpai_feature_{$id}_enabled` (filter) | Per-feature enabled override. |
| `wpai_remove_data_on_uninstall` (filter) | Return `false` to keep the plugin's tables, options, transients, and scheduled events on uninstall. |
| `wpai_gated_abilities` (filter) | The list of abilities the Custom Abilities experiment registers together. |
| `wpai_preferred_text_models`, `wpai_preferred_image_models`, `wpai_preferred_vision_models` (filters) | The fallback model lists behind the `get_preferred_*` functions above. |

Several experiments expose their own additional hooks — `wpai_request_log_*` (Logging), `wpai_{$slug}_system_instruction` / `wpai_{$slug}_prompt` (per-ability prompt customization, see [Prompt Customization](PROMPT_CUSTOMIZATION.md)), and others. Those are documented on their own experiment page rather than duplicated here.

## Testing & Contributing

See the [Developer Guide](DEVELOPER_GUIDE.md) for the full workflow (branching, quality checks, PR/merge process), [Testing](TESTING.md) for the testing philosophy behind the test pyramid this plugin follows, and [Testing the REST API](TESTING_REST_API.md) for manually exercising the endpoints above with an Application Password.
