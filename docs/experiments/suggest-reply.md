# Suggest Reply

## Summary

The Suggest Reply experiment adds an AI-powered "Suggest reply" action to the classic Comments admin screen and the Activity widget on the Dashboard. When activated, moderators can generate AI-suggested replies to comments, customize the tone, and provide specific guidelines for the reply. The experiment exposes one WordPress Ability (`ai/reply-suggestion`) that can be used from the UI or via REST API.

## Overview

When enabled, each comment in the Comments list table and the Dashboard Activity widget gets an additional **Suggest reply** action link. Clicking it opens a modal overlay allowing users to generate context-aware replies.

**Key Features:**

- Adds a "Suggest reply" action to comments in the list table and the Dashboard Activity widget
- Provides a modal interface to set the desired Tone (friendly, professional, casual) and optional editorial Guidelines
- Generates a single, relevant reply based on the comment text and parent post context
- Automatically populates the inline WordPress reply form when the generated reply is selected
- Uses one shared ability (`ai/reply-suggestion`) exposed via REST API

### Input Schema

The `ai/reply-suggestion` ability accepts:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'comment_id' => array(
            'type'        => 'integer',
            'description' => 'The ID of the comment to generate a reply for.',
            'required'    => true,
        ),
        'tone'       => array(
            'type'        => 'string',
            'enum'        => array( 'professional', 'friendly', 'casual' ),
            'default'     => 'friendly',
            'description' => 'The tone for the reply.',
        ),
        'guidelines' => array(
            'type'        => 'string',
            'default'     => '',
            'description' => 'Optional free-text editorial guidelines to apply when writing the reply.',
        ),
    ),
    'required'   => array( 'comment_id' ),
)
```

### Output Schema

The ability returns:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'comment_id' => array(
            'type'        => 'integer',
            'description' => 'The comment ID.',
        ),
        'reply'      => array(
            'type'        => 'string',
            'description' => 'The generated reply suggestion.',
        ),
    ),
)
```

### Permissions

- `ai/reply-suggestion` requires `current_user_can( 'moderate_comments' )`

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/reply-suggestion/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Example

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/reply-suggestion/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "comment_id": 1,
      "tone": "professional",
      "guidelines": "Thank the user for their feedback."
    }
  }'
```

**Response:**

```json
{
  "comment_id": 1,
  "reply": "Thank you for your valuable feedback! We appreciate you taking the time to share your thoughts."
}
```

### Error Responses

The ability may return:

- `missing_comment_id`: `comment_id` was not provided
- `comment_not_found`: no comment exists for the given ID
- `insufficient_capabilities`: current user lacks moderation permissions

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings -> AI`
   - Enable global AI features and toggle **Suggest Reply**
   - Ensure valid AI connector credentials are configured

2. **Suggest reply modal:**
   - Go to `Comments -> All Comments`
   - Hover over an comment and click **Suggest reply**
   - Select a Tone, enter Guidelines, and click **Generate**
   - Verify that the AI generates a reply
   - Click **Use this reply** and verify the inline comment reply textarea is populated with the text

3. **REST API:**
   - Call `POST /wp-json/wp-abilities/v1/abilities/ai/reply-suggestion/run` with a valid `comment_id`
   - Verify response shape and error handling for invalid IDs or insufficient permissions

## Notes & Considerations

### Requirements

- Requires valid AI credentials and text-generation-capable models
- Requires users with comment moderation capabilities for ability access

### Limitations

- Works on the classic comments list table and the Dashboard Activity widget (no block-based comments UI integration here)
