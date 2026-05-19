# Multi-Provider Support

## Summary

The plugin supports multiple AI providers through the WordPress AI Client and connector system. Features and experiments can run against a prioritized provider/model list, automatically fall back when a preferred model is unavailable, and validate capability support before execution.

## Overview

### For End Users

You can configure one or more AI connectors (for example OpenAI, Google, Anthropic) in WordPress settings. Once configured:

- Experiments can use any compatible connected provider.
- Capability checks prevent running requests on unsupported connectors.
- If a preferred model is unavailable, another configured model/provider can be used.

This allows flexibility in cost, performance, and reliability across provider ecosystems.

### For Developers

Provider selection is driven by model preference lists and capability checks:

- Text workflows use `get_preferred_models_for_text_generation()`.
- Image workflows use `get_preferred_image_models()`.
- Vision workflows use `get_preferred_vision_models()`.
- Features can override preferred ordering using filters.

Most experiment abilities build prompts with `using_model_preference( ...$models )`, then check runtime support with `is_supported_for_text_generation()` or `is_supported_for_image_generation()`.

## Architecture & Behavior

### Connector Detection

Credential detection is connector-aware:

- `has_ai_credentials()` inspects `wp_get_connectors()` for `ai_provider` connectors with configured authentication.
- `wpai_has_ai_credentials` filter allows custom connector implementations to report configured status.
- `has_valid_ai_credentials()` performs a runtime support probe using AI client prompt checks.

### Model Selection and Fallback

Default model preference arrays are ordered. The first supported and available provider/model pair is used by the AI Client prompt builder.

Example default text preference sequence includes:

- Anthropic `claude-sonnet-4-6`
- Google `gemini-3-flash-preview`, `gemini-2.5-flash`
- OpenAI `gpt-5.4-mini`, `gpt-4.1-mini`

When earlier preferences are unavailable, lower-priority entries act as fallback candidates.

### Capability Validation

Before execution, abilities commonly call support checks to avoid hard failures and return clear errors:

- `ensure_text_generation_supported()` in `Abstract_Ability`.
- `ensure_image_generation_supported()` in `Abstract_Ability`.

This ensures a feature does not run if no configured provider supports the required capability.

## Common Extension Points

### Override Preferred Text Models

```php
add_filter( 'wpai_preferred_text_models', function( $models ) {
    return array(
        array( 'openai', 'gpt-5.4-mini' ),
        array( 'anthropic', 'claude-sonnet-4-6' ),
    );
} );
```

### Override Preferred Image Models

```php
add_filter( 'wpai_preferred_image_models', function( $models ) {
    return array(
        array( 'openai', 'gpt-image-2' ),
    );
} );
```

### Report Non-API-Key Connector Credentials

```php
add_filter( 'wpai_has_ai_credentials', function( $has_credentials, $connectors ) {
    // Custom credential validation for alternate auth methods.
    return $has_credentials;
}, 10, 2 );
```

## Operational Notes

- Configure at least one connector that supports the feature's required capability.
- Multi-provider setups can improve resilience when individual providers are unavailable.
- Keep model preference filters aligned with currently available provider model IDs.
- If no provider supports the requested capability, abilities should return explicit `WP_Error` responses.

## Related Files

- `includes/helpers.php`
- `includes/Abstracts/Abstract_Ability.php`
- `includes/Services/AI_Service.php`
- `includes/Admin/Dashboard/AI_Status_Widget.php`
- `includes/Admin/Dashboard/AI_Capabilities_Widget.php`
