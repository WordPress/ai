# Post Table Bulk AI Actions

## What problem does this address?

When managing many posts in the Posts list table, editors often need to perform repetitive AI tasks like generating titles or excerpts for multiple posts at once. Currently, they must open each post individually in the editor to access AI features.

## What is your proposed solution?

Add AI-powered bulk actions and Quick Edit enhancements to the Posts list table:

### Bulk Actions
- **Bulk Generate Titles**: Select multiple posts and generate AI title suggestions for all
- **Bulk Generate Excerpts**: Generate excerpts for posts missing them
- **Bulk Generate Alt Text**: For featured images missing alt text

### Quick Edit Enhancement
- Add "Generate Title" button in Quick Edit panel
- Add "Generate Excerpt" button in Quick Edit panel
- Show AI suggestions inline without opening full editor

## User Flow

1. User selects multiple posts in list view
2. User selects "Generate Titles" from Bulk Actions dropdown
3. Modal appears showing progress and generated suggestions
4. User reviews and approves/edits suggestions per post
5. Changes saved in bulk

## Technical Considerations

- Should respect existing AI provider configuration
- Needs rate limiting for bulk operations
- Progress indication for long-running operations
- Ability to cancel mid-operation

## Design Notes

- Keep UI consistent with existing WordPress bulk action patterns
- Consider adding AI indicator column to show which posts have AI-generated content
