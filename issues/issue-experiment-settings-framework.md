# Experiment Settings Framework

## What problem does this address?

The current settings page has limitations that don't scale well as experiments grow in complexity:

1. **Flat list structure** - All experiments shown in a single list with inline settings
2. **Limited settings space** - Custom settings squeezed into list items via `render_settings_fields()`
3. **No standardized settings API** - Each experiment implements settings differently
4. **PHP-only rendering** - No React components for dynamic settings UI
5. **No categorization** - Can't group related experiments
6. **Missing patterns** - No shared components for common setting types (model selection, temperature, etc.)

As experiments add more configuration options (model preferences, tone settings, output formats, advanced options), the current UI becomes unwieldy.

## What is your proposed solution?

Create a comprehensive **Experiment Settings Framework** with three layers:

1. **Settings Registration API** (PHP) - How experiments declare their settings
2. **Settings UI Components** (React) - Reusable components for rendering settings
3. **Settings Page Architecture** - The overall admin page structure

---

## Sub-Issue 1: Settings Registration API

### Problem
Currently experiments use `register_settings()` and `render_settings_fields()` methods with no standardization. Each experiment reinvents the wheel for common patterns.

### Proposed Solution

Create a declarative settings schema that experiments return:

```php
// In experiment class
protected function get_settings_schema(): array {
    return array(
        'sections' => array(
            array(
                'id'          => 'general',
                'title'       => __( 'General Settings', 'ai' ),
                'description' => __( 'Configure basic behavior.', 'ai' ),
                'fields'      => array(
                    array(
                        'id'          => 'default_candidates',
                        'type'        => 'number',
                        'label'       => __( 'Default Suggestions', 'ai' ),
                        'description' => __( 'Number of suggestions to generate.', 'ai' ),
                        'default'     => 3,
                        'min'         => 1,
                        'max'         => 10,
                    ),
                    array(
                        'id'      => 'tone',
                        'type'    => 'select',
                        'label'   => __( 'Default Tone', 'ai' ),
                        'options' => array(
                            'neutral'      => __( 'Neutral', 'ai' ),
                            'professional' => __( 'Professional', 'ai' ),
                            'casual'       => __( 'Casual', 'ai' ),
                        ),
                        'default' => 'neutral',
                    ),
                ),
            ),
            array(
                'id'          => 'advanced',
                'title'       => __( 'Advanced', 'ai' ),
                'collapsible' => true,
                'fields'      => array(
                    array(
                        'id'          => 'temperature',
                        'type'        => 'range',
                        'label'       => __( 'Temperature', 'ai' ),
                        'description' => __( 'Controls randomness. Lower = more focused.', 'ai' ),
                        'default'     => 0.7,
                        'min'         => 0,
                        'max'         => 1,
                        'step'        => 0.1,
                    ),
                    array(
                        'id'          => 'model_override',
                        'type'        => 'model_select',
                        'label'       => __( 'Model Override', 'ai' ),
                        'description' => __( 'Override the default model for this experiment.', 'ai' ),
                        'default'     => '',
                    ),
                ),
            ),
        ),
    );
}
```

### Field Types

| Type | Description | Props |
|------|-------------|-------|
| `toggle` | Boolean on/off | `default` |
| `text` | Single line text | `default`, `placeholder`, `maxLength` |
| `textarea` | Multi-line text | `default`, `rows`, `placeholder` |
| `number` | Numeric input | `default`, `min`, `max`, `step` |
| `range` | Slider input | `default`, `min`, `max`, `step` |
| `select` | Dropdown | `options`, `default` |
| `radio` | Radio group | `options`, `default` |
| `checkbox_group` | Multiple checkboxes | `options`, `default` |
| `model_select` | AI model picker | `default`, `providers` |
| `token_limit` | Token/length control | `default`, `min`, `max` |
| `custom` | Custom React component | `component` |

### Storage

Settings stored as: `ai_experiment_{experiment_id}_setting_{field_id}`

Or grouped: `ai_experiment_{experiment_id}_settings` (serialized array)

### Benefits
- Declarative schema, less boilerplate
- Automatic validation and sanitization
- REST API exposure for React UI
- Consistent option naming

---

## Sub-Issue 2: Settings UI Components

### Problem
No reusable React components for experiment settings. Each would need to build from scratch.

### Proposed Solution

Create a `/packages/experiment-settings/` package with:

#### Core Components

```tsx
// Main settings panel for an experiment
<ExperimentSettingsPanel
  experimentId="title-generation"
  schema={settingsSchema}
  values={currentValues}
  onChange={handleChange}
/>

// Individual section
<SettingsSection
  title="General Settings"
  description="Configure basic behavior."
  collapsible={false}
>
  {children}
</SettingsSection>

// Field wrapper with label, description, error
<SettingsField
  id="temperature"
  label="Temperature"
  description="Controls randomness."
  error={validationError}
>
  <RangeControl ... />
</SettingsField>
```

#### Specialized Components

```tsx
// Model selection with provider grouping
<ModelSelectControl
  value={selectedModel}
  onChange={setModel}
  providers={['openai', 'anthropic', 'google']}
  showCosts={true}
/>

// Temperature with visual indicator
<TemperatureControl
  value={0.7}
  onChange={setTemperature}
  showLabels={true} // "Focused" ... "Creative"
/>

// Token/length limit
<TokenLimitControl
  value={150}
  onChange={setLimit}
  estimatedCost={0.002}
/>

// Preset selector (e.g., for tones, styles)
<PresetSelector
  presets={[
    { id: 'professional', label: 'Professional', icon: briefcase },
    { id: 'casual', label: 'Casual', icon: smile },
  ]}
  value={selectedPreset}
  onChange={setPreset}
/>
```

#### Shared Patterns

```tsx
// Consistent save/reset footer
<SettingsFooter
  onSave={handleSave}
  onReset={handleReset}
  isSaving={isSaving}
  hasChanges={hasChanges}
/>

// Validation error display
<SettingsValidationErrors errors={errors} />

// Settings changed indicator
<UnsavedChangesNotice />
```

### Integration with @wordpress/components

Build on top of existing components:
- `ToggleControl`
- `SelectControl`
- `RangeControl`
- `TextControl`
- `TextareaControl`
- `Panel`, `PanelBody`, `PanelRow`
- `Notice`
- `Spinner`

---

## Sub-Issue 3: Settings Page Architecture

### Problem
Current page is a single flat form. Need structure for:
- Multiple experiments with varying complexity
- Categories/grouping
- Navigation between experiments
- Global vs. experiment-specific settings

### Proposed Solution

#### Option A: Tabbed Interface

```
┌─────────────────────────────────────────────────────────────────┐
│  AI Experiments Settings                                        │
├─────────────────────────────────────────────────────────────────┤
│  [General] [Title Generation] [Alt Text] [Excerpt] [Advanced]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Title Generation                                               │
│  ─────────────────────────────────────────────────────────────  │
│                                                                 │
│  ☑ Enable Title Generation                                      │
│                                                                 │
│  ┌─ General Settings ────────────────────────────────────────┐  │
│  │                                                           │  │
│  │  Default Suggestions    [3        ▼]                      │  │
│  │  Number of titles to generate                             │  │
│  │                                                           │  │
│  │  Default Tone           [Neutral  ▼]                      │  │
│  │  Tone for generated titles                                │  │
│  │                                                           │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                 │
│  ┌─ Advanced ─────────────────────────────────────────── [▼] ┐  │
│  │                                                           │  │
│  │  Temperature            [====●=====] 0.7                  │  │
│  │  Lower = more focused, Higher = more creative             │  │
│  │                                                           │  │
│  │  Model Override         [Use Default ▼]                   │  │
│  │                                                           │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                 │
│                                    [Reset to Defaults] [Save]   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### Option B: Sidebar Navigation

```
┌───────────────┬─────────────────────────────────────────────────┐
│               │                                                 │
│  AI Settings  │  Title Generation                               │
│  ───────────  │  ─────────────────────────────────────────────  │
│               │                                                 │
│  ▸ General    │  ☑ Enable Title Generation                      │
│               │                                                 │
│  Experiments  │  General Settings                               │
│  ───────────  │  ┌───────────────────────────────────────────┐  │
│  ● Title Gen  │  │  Default Suggestions    [3        ▼]     │  │
│  ○ Alt Text   │  │  Default Tone           [Neutral  ▼]     │  │
│  ○ Excerpt    │  └───────────────────────────────────────────┘  │
│  ○ Tagging    │                                                 │
│               │  Advanced                                       │
│  ▸ Advanced   │  ┌───────────────────────────────────────────┐  │
│  ▸ Developer  │  │  Temperature            [====●=====] 0.7 │  │
│               │  │  Model Override         [Use Default ▼]  │  │
│               │  └───────────────────────────────────────────┘  │
│               │                                                 │
│               │                    [Reset to Defaults] [Save]   │
│               │                                                 │
└───────────────┴─────────────────────────────────────────────────┘
```

#### Option C: Expandable Cards (Enhanced Current)

```
┌─────────────────────────────────────────────────────────────────┐
│  AI Experiments                                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ☑ Enable AI Experiments                                        │
│                                                                 │
│  ┌─ Title Generation ──────────────────────────────────── [▼] ┐ │
│  │  ☑ Enabled                                                 │ │
│  │  Generates title suggestions from content                  │ │
│  │                                                            │ │
│  │  [Configure Settings →]                                    │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                 │
│  ┌─ Alt Text Generation ───────────────────────────────── [▶] ┐ │
│  │  ☐ Enabled                                                 │ │
│  │  Generates descriptive alt text for images                 │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                 │
│  ┌─ Excerpt Generation ────────────────────────────────── [▶] ┐ │
│  │  ☐ Enabled                                                 │ │
│  │  Generates post excerpts from content                      │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘

[Click "Configure Settings" opens slide-out panel or modal]
```

### Recommendation

**Option B (Sidebar Navigation)** provides:
- Clear hierarchy
- Room for many experiments
- Familiar pattern (matches Site Editor, WooCommerce, etc.)
- Space for global settings, advanced, developer sections

---

## Sub-Issue 4: REST API for Settings

### Problem
React UI needs to read/write settings. Need proper REST endpoints.

### Proposed Solution

```
GET  /wp-json/ai/v1/experiments
     Returns all experiments with their settings schemas

GET  /wp-json/ai/v1/experiments/{id}
     Returns single experiment with schema and current values

GET  /wp-json/ai/v1/experiments/{id}/settings
     Returns current settings values for experiment

POST /wp-json/ai/v1/experiments/{id}/settings
     Updates settings for experiment

POST /wp-json/ai/v1/experiments/{id}/settings/reset
     Resets experiment settings to defaults
```

### Response Format

```json
{
  "id": "title-generation",
  "label": "Title Generation",
  "description": "Generates title suggestions from content",
  "enabled": true,
  "settings": {
    "schema": { ... },
    "values": {
      "default_candidates": 3,
      "tone": "neutral",
      "temperature": 0.7
    },
    "defaults": {
      "default_candidates": 3,
      "tone": "neutral",
      "temperature": 0.7
    }
  }
}
```

---

## Implementation Phases

### Phase 1: Foundation
- [ ] Create `Settings_Schema` class for parsing experiment schemas
- [ ] Add `get_settings_schema()` method to `Abstract_Experiment`
- [ ] Register REST API endpoints for settings
- [ ] Create basic React settings page shell

### Phase 2: UI Components
- [ ] Port existing settings to React
- [ ] Create `ExperimentSettingsPanel` component
- [ ] Create common field components (text, select, range, toggle)
- [ ] Create specialized components (model select, temperature)

### Phase 3: Experiment Migration
- [ ] Add settings schema to Title Generation
- [ ] Add settings schema to Alt Text Generation
- [ ] Add settings schema to other experiments
- [ ] Remove legacy PHP rendering

### Phase 4: Advanced Features
- [ ] Sidebar navigation implementation
- [ ] Settings import/export
- [ ] Settings presets
- [ ] Per-user setting overrides (future)

---

## Related Upstream Issues

- #103 - Modernize admin UI to use React and WP components
- #102 - Add basic settings experience for Title Generation
- #90 - Clarify and consolidate Title Generation options
- #35 - Shared UI patterns and consistency pass
- #33 - Add advanced configuration tools for power users
- #32 - Add AI Playground interface

---

## Open Questions

1. **Storage format**: Individual options vs. serialized array per experiment?
2. **Validation**: Client-side only, or server-side too?
3. **Permissions**: Should some settings require higher capabilities?
4. **Multisite**: Network-level defaults with site overrides?
5. **Export/Import**: Allow settings backup and sharing?

## Labels

`enhancement`, `admin`, `settings`, `framework`, `react`
