# Suggest Reply

Adds a "Suggest reply" action to the Comments screen and the Activity dashboard widget. AI generates reply candidates based on the comment content, post context, and optional editorial guidelines, which the moderator can review, edit, and insert.

## Summary

- Extends `Abstract_Feature` and registers the `ai/reply-suggestion` WP Ability.
- Injects a "Suggest reply" link into each comment row on the **Comments** admin screen and the **Activity** dashboard widget.
- Opens a modal that lets the moderator choose a reply tone, add editorial guidelines, and generate an AI reply.
- The generated reply can be inserted directly into the inline reply form, or copied to the clipboard.

## Functionality

- **Tone selection** — choose between *Friendly*, *Professional*, and *Casual*.
- **Optional guidelines** — free-text instructions the AI applies when drafting the reply (e.g. "always mention our support email").
- **Generate / Regenerate** — calls the `ai/reply-suggestion` ability, which uses the comment content and parent post context as prompt input.
- **Use this reply** — inserts the generated reply into the WordPress inline comment reply form and focuses it.
- **Copy** — copies the reply to the clipboard with a transient "Copied!" confirmation.

## Requirements

- An AI provider must be connected and enabled in **Settings → AI**.
- The connected provider must support text generation.
- The current user must have the `moderate_comments` capability.

## Usage

1. Go to **Comments** or the **Dashboard** in the WordPress admin.
2. Hover over any comment row (or recent comment in the Activity widget) and click **Suggest reply**.
3. Optionally adjust the tone or add editorial guidelines.
4. Click **Generate** to produce an AI reply suggestion.
5. Click **Use this reply** to populate the inline reply form, or **Copy** to copy it to the clipboard.
