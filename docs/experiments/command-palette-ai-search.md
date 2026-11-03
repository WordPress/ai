# Command Palette AI Search

## Summary

The Command Palette AI Search experiment adds an AI fallback to the admin command palette (Cmd/Ctrl+K). When the palette finds nothing for a search, such as "how do I hide my site from google", the model picks the registered commands that lead to the right screen and the palette shows them as normal results.

## Overview

### For End Users

The command palette only finds a screen when you type words from its label. With this experiment enabled:

1. Type a question into the command palette, for example "where do I change my site icon?".
2. If the palette finds nothing for it, a **Searching with AI…** row appears after a short pause.
3. Up to three matching screens replace it, best first. The first one is selected, so Enter opens it.
4. If nothing fits, or the request fails, the row disappears and the palette shows "No results found."

Searches the palette already has results for, and searches shorter than three characters, are never sent to the model.

### For Developers

The experiment has three parts:

1. **Experiment Class** (`WordPress\AI\Experiments\Command_Palette_AI_Search\Command_Palette_AI_Search`): registers the ability and enqueues the command loader on screens that have the command palette.
2. **Ability Class** (`WordPress\AI\Abilities\Command_Palette_AI_Search\Command_Palette_AI_Search`): lists the commands for the model, asks it which ones answer the search, and maps its answer back to command names.
3. **Command Loader** (`src/experiments/command-palette-ai-search/`): registers a command loader with the `core/commands` store, sends the search and the registered commands to the ability, and returns the chosen commands.

The ability only chooses from commands that are already in the palette, and each result runs that command's own callback. It never builds URLs, so it can only send someone to a screen the palette already offers them.

## Architecture & Implementation

### Key Hooks & Entry Points

`WordPress\AI\Experiments\Command_Palette_AI_Search\Command_Palette_AI_Search::register()` wires:

- `wp_abilities_api_init` -> registers `ai/command-palette-ai-search`.
- `admin_enqueue_scripts` -> enqueues `experiments/command-palette-ai-search` JS and CSS when `wp-commands` is enqueued.

### Assets & Data Flow

1. **PHP side**
   - Enqueues script and stylesheet: `experiments/command-palette-ai-search`, only when the command palette (`wp-commands`) is on the page.

2. **JS side**
   - Registers the `ai/command-palette-ai-search` command loader.
   - Waits 450 ms after the last keystroke. If the palette shows no results, it reads every registered command from the `core/commands` store.
   - Calls `runAbility( 'ai/command-palette-ai-search', { query, commands } )`, sending each command's `name`, `label` and `keywords`.
   - Shows the matching commands with the original command's callback. Answers are kept in memory for the page load.

3. **Ability side**
   - Lists each command as `c{index}: label (keywords)`, adds a hint about whether the site uses a block or classic theme, and asks the model for at most three IDs.
   - Requests a JSON response restricted to the listed IDs. When the provider doesn't support structured output, it falls back to a plain prompt and extracts the JSON from the reply.
   - Drops IDs that are not in the list, removes repeats, and returns command names.

### Input Schema

```php
array(
    'type'       => 'object',
    'required'   => array( 'query', 'commands' ),
    'properties' => array(
        'query'    => array(
            'type'      => 'string',
            'minLength' => 3,
            'maxLength' => 200,
        ),
        'commands' => array(
            'type'     => 'array',
            'minItems' => 1,
            'maxItems' => 500,
            'items'    => array(
                'type'       => 'object',
                'required'   => array( 'name', 'label' ),
                'properties' => array(
                    'name'     => array( 'type' => 'string' ),
                    'label'    => array( 'type' => 'string' ),
                    'keywords' => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'string' ),
                    ),
                ),
            ),
        ),
    ),
)
```

### Output Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'commands' => array(
            'type'  => 'array',
            'items' => array( 'type' => 'string' ),
        ),
    ),
)
```

`commands` holds the names of the chosen commands, best first. It is empty when no command fits.

### Permissions

Users need the `edit_posts` capability, so subscribers can't run the ability. The results are always names from the command list in the request, so the ability never reveals a screen the caller didn't send. In the palette, that list is the commands the user already has, and each result runs that command's own callback.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/command-palette-ai-search/run
```

See [TESTING_REST_API.md](../TESTING_REST_API.md) for authentication.

### Request Example

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/command-palette-ai-search/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "query": "how do I hide my site from google",
      "commands": [
        { "name": "options-general.php", "label": "Settings > General" },
        { "name": "options-reading.php", "label": "Settings > Reading" },
        { "name": "options-discussion.php", "label": "Settings > Discussion" }
      ]
    }
  }'
```

**Response:**

```json
{
  "commands": [ "options-reading.php" ]
}
```

### Error Responses

- `insufficient_data`: the search or the command list was empty.
- `insufficient_capabilities`: the user lacks the `edit_posts` capability.
- `invalid_response`: the model's reply did not contain a `commands` array.
- A `WP_Error` from `ensure_text_generation_supported()` if no connected provider supports text generation.

## Extending

### Adding Keywords to Commands

The model sees each command's keywords, so keywords added to a command also improve the AI results. Commands registered with `keywords` through `@wordpress/commands` are sent as they are.

### Customizing the System Instruction

Filter the system instruction per site:

```php
add_filter( 'wpai_system_instruction', function ( string $instruction, string $name ): string {
    if ( 'ai/command-palette-ai-search' !== $name ) {
        return $instruction;
    }
    return $instruction . "\nPrefer screens over actions that create content.";
}, 10, 2 );
```

### Filtering the Prompt

```php
add_filter( 'wpai_command_palette_ai_search_prompt', function ( string $prompt, string $query, array $commands ): string {
    return $prompt;
}, 10, 3 );
```

### Filtering the Prompt Builder

```php
add_filter( 'wpai_command_palette_ai_search_prompt_builder', function ( $prompt_builder ) {
    return $prompt_builder->using_temperature( 0 );
} );
```

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`.
   - Toggle **Command Palette AI Search** to enabled.
   - Make sure an AI Connector with text generation support is configured.

2. **Search with a question:**
   - On any admin screen, open the command palette with Cmd/Ctrl+K.
   - Type `how do I hide my site from google` and wait.
   - Verify that **Searching with AI…** appears, then **Settings > Reading** replaces it and is selected.
   - Press Enter and verify that Settings > Reading opens.

3. **Try other questions:**
   - `stop spam comments` → Settings > Discussion
   - `where do I install a new plugin?` → Plugins > Add Plugin
   - `how do I bake a cake` → no AI results; the row disappears.

4. **Check searches the palette already answers:**
   - Type `reading` or `menus` and verify that no AI row appears.

5. **Check failures:**
   - Disconnect all providers, search with a question, and verify that the AI row disappears without an error in the palette.

## Notes & Considerations

### Requirements

- Requires an AI Connector that supports text generation.
- Only loads on screens that have the command palette.

### Limitations

- Results can only be commands already registered in the palette. Settings on screens that have no command of their own, such as the Site Icon field, lead to the screen that contains them rather than the field itself.
- Each search sends the full command list (usually 100–150 commands), so response time depends on the model. Small, fast models work best.
- Site Editor commands that come from command loaders are not included, because loaders are not listed in the store.
