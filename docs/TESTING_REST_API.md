# Testing REST API Endpoints

This document provides instructions for testing the AI plugin's REST API endpoints using curl.

## Prerequisites

- WordPress site running (e.g., `https://wordpress-ai.test`)
- Administrator account credentials
- curl installed

## Authentication Methods

### Method 1: Application Password (Recommended)

1. **Create an Application Password:**
   - Log into WordPress admin
   - Go to Users → Your Profile
   - Scroll to "Application Passwords"
   - Create a new application password (name it "Testing" or similar)
   - Copy the generated password (you'll only see it once)

2. **Make the request:**

```bash
curl -X POST "https://wordpress-ai.test/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run" \
  -u "your-username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This is a sample article about artificial intelligence and machine learning. AI has revolutionized many industries including healthcare, finance, and transportation. Machine learning algorithms can now process vast amounts of data to identify patterns and make predictions that were previously impossible."
    }
  }'
```

### Method 2: Cookie Authentication with Nonce

1. **Get a nonce:**
   - Log into WordPress admin in your browser
   - Open browser DevTools → Console
   - Run: `wpApiSettings.nonce` (if available)

2. **Get your session cookie:**
   - Open DevTools → Application/Storage → Cookies
   - Copy the `wordpress_logged_in_[hash]` cookie value

3. **Make the request:**

```bash
curl -X POST "https://wordpress-ai.test/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE_HERE" \
  -H "Cookie: wordpress_logged_in_[hash]=YOUR_COOKIE_VALUE_HERE" \
  -d '{
    "input": {
      "content": "This is a sample article about artificial intelligence and machine learning. AI has revolutionized many industries including healthcare, finance, and transportation. Machine learning algorithms can now process vast amounts of data to identify patterns and make predictions that were previously impossible."
    }
  }'
```

## Example: Excerpt Generation

**Endpoint:** `POST /wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run`

**Request Body:**
```json
{
  "input": {
    "content": "This is a sample article about artificial intelligence and machine learning. AI has revolutionized many industries including healthcare, finance, and transportation. Machine learning algorithms can now process vast amounts of data to identify patterns and make predictions that were previously impossible."
  }
}
```

**Full curl command (using Application Password):**
```bash
curl -X POST "https://wordpress-ai.test/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This is a sample article about artificial intelligence and machine learning. AI has revolutionized many industries including healthcare, finance, and transportation. Machine learning algorithms can now process vast amounts of data to identify patterns and make predictions that were previously impossible."
    }
  }'
```

**Expected Response:**
```json
{
  "excerpts": [
    "This article explores how artificial intelligence and machine learning have transformed industries like healthcare, finance, and transportation. These technologies enable algorithms to analyze massive datasets, uncovering patterns and generating predictions that were once beyond human capability.",
    "...",
    "..."
  ]
}
```

## Example: Title Generation

**Endpoint:** `POST /wp-json/wp-abilities/v1/abilities/ai/title-generation/run`

**Request Body:**
```json
{
  "input": {
    "content": "Your article content here...",
    "candidates": 3
  }
}
```

**Full curl command:**
```bash
curl -X POST "https://wordpress-ai.test/wp-json/wp-abilities/v1/abilities/ai/title-generation/run" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Your article content here...",
      "candidates": 3
    }
  }'
```

## Common Errors

### 401 Unauthorized
- **Cause:** Invalid or missing authentication
- **Solution:** Verify your Application Password or nonce/cookie are correct

### 405 Method Not Allowed
- **Cause:** Using GET instead of POST
- **Solution:** Ensure you're using `-X POST` in curl

### 400 Bad Request
- **Cause:** Invalid input format
- **Solution:** Ensure the request body has `"input"` as the top-level key, and the JSON is valid

### 404 Not Found
- **Cause:** Ability not registered
- **Solution:** Ensure the plugin is activated and the ability is registered

## Testing with Postman

1. **Create a new POST request**
2. **URL:** `https://wordpress-ai.test/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run`
3. **Authorization:** 
   - Type: Basic Auth
   - Username: Your WordPress username
   - Password: Application Password (not your regular password)
4. **Headers:**
   - `Content-Type: application/json`
5. **Body:** 
   - Select "raw" and "JSON"
   - Paste the JSON with `input` wrapper
