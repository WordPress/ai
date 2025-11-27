# Abilities Explorer: DataViews Refactor

## What problem does this address?

The current Abilities Explorer (PR #63) provides basic ability listing, but doesn't align with modern WordPress admin UI patterns. The DataViews component provides:
- Consistent UX with Site Editor (templates, pages, patterns)
- Built-in filtering, sorting, and search
- Multiple view modes (table, grid, list)
- Bulk actions support
- Responsive design out of the box

## What is your proposed solution?

Refactor the Abilities Explorer to use the `@wordpress/dataviews` component, following the prototype approach demonstrated by @juanmaguitar.

### Reference Implementation

A prototype already exists: [juanma-wp/abilities-dashboard](https://github.com/juanma-wp/abilities-dashboard)

[Live Demo on WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/juanma-wp/abilities-dashboard/main/_playground/blueprint.json)

## Proposed DataViews Structure

### Fields (Columns)

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Ability identifier (e.g., `ai/title-generation`) |
| `label` | string | Human-readable name |
| `description` | string | What the ability does |
| `category` | string | Ability category |
| `source` | string | Origin (core, plugin name, theme) |
| `status` | badge | Active/Inactive indicator |
| `actions` | buttons | View, Test, Copy ID |

### Filters

- **Category**: Filter by ability category
- **Source**: Filter by origin (Core, Plugin, Theme)
- **Status**: Active/Inactive
- **Search**: Full-text search across name, label, description

### View Modes

- **Table**: Default, detailed view with all columns
- **Grid**: Card-based view for visual browsing
- **List**: Compact list for quick scanning

### Bulk Actions

- Export selected abilities (JSON schema)
- Copy ability IDs to clipboard

## UI Mockup

```
┌─────────────────────────────────────────────────────────────────┐
│  AI Experiments → Abilities                                     │
├─────────────────────────────────────────────────────────────────┤
│  [Explorer] [MCP Server] [Test]                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ [Search abilities...]          [Category ▼] [Source ▼] │    │
│  │                                                         │    │
│  │ View: [Table] [Grid] [List]              Showing 12/45  │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ □ │ Name                  │ Category │ Source  │ Actions│    │
│  ├───┼──────────────────────┼──────────┼─────────┼────────┤    │
│  │ □ │ ai/title-generation  │ Content  │ AI Exp. │ [⋮]    │    │
│  │   │ Title Generation     │          │         │        │    │
│  │   │ Generates title...   │          │         │        │    │
│  ├───┼──────────────────────┼──────────┼─────────┼────────┤    │
│  │ □ │ ai/alt-text-gen...   │ Media    │ AI Exp. │ [⋮]    │    │
│  │   │ Alt Text Generation  │          │         │        │    │
│  │   │ Generates alt text...│          │         │        │    │
│  ├───┼──────────────────────┼──────────┼─────────┼────────┤    │
│  │ □ │ ai/get-post-details  │ Utility  │ Core    │ [⋮]    │    │
│  │   │ Get Post Details     │          │         │        │    │
│  │   │ Retrieves post...    │          │         │        │    │
│  └───┴──────────────────────┴──────────┴─────────┴────────┘    │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ ◀ 1 2 3 ... 5 ▶                    Items per page: [10]│    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Grid View

```
┌────────────────┐  ┌────────────────┐  ┌────────────────┐
│ 🏷️ Content     │  │ 🖼️ Media       │  │ 🔧 Utility     │
│                │  │                │  │                │
│ Title          │  │ Alt Text       │  │ Get Post       │
│ Generation     │  │ Generation     │  │ Details        │
│                │  │                │  │                │
│ Generates      │  │ Generates      │  │ Retrieves      │
│ title          │  │ descriptive    │  │ post data      │
│ suggestions... │  │ alt text...    │  │ and meta...    │
│                │  │                │  │                │
│ [View] [Test]  │  │ [View] [Test]  │  │ [View] [Test]  │
└────────────────┘  └────────────────┘  └────────────────┘
```

## Technical Implementation

### Data Source

```typescript
interface AbilityData {
  id: string;           // e.g., "ai/title-generation"
  label: string;        // e.g., "Title Generation"
  description: string;
  category: string;
  source: 'core' | 'plugin' | 'theme';
  sourceName: string;   // e.g., "AI Experiments"
  inputSchema: object;
  outputSchema: object;
  isActive: boolean;
}
```

### REST Endpoint

Use existing abilities API or create wrapper:
```
GET /wp-json/ai/v1/abilities
GET /wp-json/ai/v1/abilities/{id}
```

### DataViews Configuration

```typescript
const fields = [
  {
    id: 'name',
    label: __( 'Name', 'ai' ),
    enableGlobalSearch: true,
    render: ( { item } ) => (
      <div>
        <strong>{ item.id }</strong>
        <div>{ item.label }</div>
        <small>{ item.description }</small>
      </div>
    ),
  },
  {
    id: 'category',
    label: __( 'Category', 'ai' ),
    elements: categories, // For filtering
  },
  {
    id: 'source',
    label: __( 'Source', 'ai' ),
    elements: sources, // For filtering
  },
  {
    id: 'actions',
    label: __( 'Actions', 'ai' ),
    render: ( { item } ) => <AbilityActions ability={ item } />,
  },
];

const actions = [
  {
    id: 'view',
    label: __( 'View Details', 'ai' ),
    callback: ( items ) => openDetailModal( items[0] ),
  },
  {
    id: 'test',
    label: __( 'Test Ability', 'ai' ),
    callback: ( items ) => openTestModal( items[0] ),
  },
  {
    id: 'copy-id',
    label: __( 'Copy ID', 'ai' ),
    callback: ( items ) => copyToClipboard( items[0].id ),
  },
];
```

## Detail Modal

When clicking "View Details", show a modal with:

```
┌─────────────────────────────────────────────────────────┐
│  ai/title-generation                              [✕]   │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  Title Generation                                       │
│  ─────────────────────────────────────────────────      │
│  Generates title suggestions from content               │
│                                                         │
│  Category: Content                                      │
│  Source: AI Experiments Plugin                          │
│  Status: ● Active                                       │
│                                                         │
│  Input Schema                                           │
│  ┌─────────────────────────────────────────────────┐    │
│  │ {                                               │    │
│  │   "content": "string",                          │    │
│  │   "post_id": "integer",                         │    │
│  │   "candidates": "integer (1-10, default: 3)"    │    │
│  │ }                                               │    │
│  └─────────────────────────────────────────────────┘    │
│                                                         │
│  Output Schema                                          │
│  ┌─────────────────────────────────────────────────┐    │
│  │ {                                               │    │
│  │   "titles": "string[]"                          │    │
│  │ }                                               │    │
│  └─────────────────────────────────────────────────┘    │
│                                                         │
│  [Test This Ability]                    [Copy ID]       │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

## Dependencies

- `@wordpress/dataviews` - Core component
- PR #63 (Abilities Explorer) - Base to refactor
- WordPress 6.5+ for DataViews support

## Related Issues

- #62 - Add feature to show what abilities are on site
- #63 - Adds Abilities Explorer (PR to refactor)
- Comment from @juanmaguitar proposing DataViews approach

## References

- [DataViews Documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dataviews/)
- [juanma-wp/abilities-dashboard prototype](https://github.com/juanma-wp/abilities-dashboard)
- [DataViews in Site Editor](https://github.com/WordPress/gutenberg/tree/trunk/packages/dataviews)

## Labels

`enhancement`, `admin`, `abilities`, `dataviews`, `ui`
