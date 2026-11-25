# Table Caption Generation

## Summary

The Table Caption Generation experiment adds a **Generate caption** button to the Table block's settings. It uses the table's headers and cells, post title, and nearby text to suggest a short caption. Users can review and edit the suggestion before applying it to the existing caption field.

## At a Glance

- **Type:** Experiment; off by default
- **Runs without a user action:** No, only when you ask
- **When it runs:** Click Generate caption or Regenerate caption in the Table block's settings
- **Sent to your AI provider:** Table headers and cells, the current post title, nearby text.
- **Requests and data:** One request per click for an eligible table

## Using the Experiment

1. Configure a text-generation provider in **Settings → Connectors**.
2. Enable **Table Caption Generation** in **Settings → AI**.
3. Open a post or page, select a Table block, and open **AI table caption** in its block settings.
4. Click **Generate caption**, then review or edit the suggestion.
5. Choose **Apply caption**, **Discard**, or **Regenerate caption**.

Applying a suggestion updates only the caption. The table's cells and formatting stay unchanged.

## Developer Reference

The experiment registers the `ai/table-caption-generation` ability:

```text
POST /wp-json/wp-abilities/v1/abilities/ai/table-caption-generation/run
```

Input requires `post_id` and a structured `table` object containing `head`, `body`, and `foot` rows. Each row contains cells with `content` and optional header/scope/span information. `title` and `context` are optional. The output is a caption.

Requires permission to edit the post and a REST-enabled post type that supports the editor. See [REST API testing](../TESTING_REST_API.md) for authentication.

## Notes and Limitations

- Supports editable Table blocks in the post editor.
- If the table, caption, or source context changes, generate a new suggestion before applying it.
- Suggestions disappear when another block is selected or the editor is closed.
