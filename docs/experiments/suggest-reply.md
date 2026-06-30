# Suggest Reply

## Summary

The Suggest Reply experiment adds an AI-powered "Suggest reply" action to the classic Comments admin screen and the Activity widget on the Dashboard. When activated, moderators can generate AI-suggested replies to comments and customize the tone. The ability also automatically applies any site-wide editorial guidelines. The experiment exposes one WordPress Ability (`ai/reply-suggestion`) that can be used from the UI or via REST API.

## Overview

When enabled, each comment in the Comments list table and the Dashboard Activity widget gets an additional **Suggest reply** action link. Clicking it automatically opens the inline reply editor, generates a context-aware reply, and inserts it into the textarea.

**Key Features:**

- Adds a "Suggest reply" action to comments in the list table and the Dashboard Activity widget
- Injects in-editor controls for regenerating replies with a different Tone (friendly, professional, casual)
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
    ),
    'required'   => array( 'comment_id' ),
)
```

### Output Schema

The ability returns:

```php
array(
    'type'        => 'string',
    'description' => 'The generated reply suggestion.',
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
      "tone": "professional"
    }
  }'
```

**Response:**

```json
"Thank you for your valuable feedback! We appreciate you taking the time to share your thoughts."
```

### Error Responses

The ability may return:

- `missing_comment_id`: `comment_id` was not provided
- `comment_not_found`: no comment exists for the given ID
- `insufficient_capabilities`: current user lacks moderation permissions
- `post_not_found`: post of the comment is not found

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings -> AI`
   - Enable global AI features and toggle **Suggest Reply**
   - Ensure valid AI connector credentials are configured

2. **Suggest reply:**
   - Go to `Comments -> All Comments`
   - Hover over a comment and click **Suggest reply**
   - Verify that the inline reply form opens, disables temporarily, and populates with the AI-generated reply
   - Use the Tone dropdown to change the tone and Suggest Reply button in the inline reply form to regenerate the reply.

3. **REST API:**
   - Call `POST /wp-json/wp-abilities/v1/abilities/ai/reply-suggestion/run` with a valid `comment_id`
   - Verify response shape and error handling for invalid IDs or insufficient permissions

## Notes & Considerations

### Requirements

- Requires valid AI credentials and text-generation-capable models
- Requires users with comment moderation capabilities for ability access

### Limitations

- Works on the classic comments list table and the Dashboard Activity widget (no block-based comments UI integration here)
