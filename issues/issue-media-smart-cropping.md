# Smart Image Cropping

## Overview

Automatically detect the focal point of uploaded images so every generated size keeps the subject centered. This replaces the default "center crop everything" behavior and saves editors from manually recropping thumbnails, featured images, and social previews.

## What problem does this address?

- Default crops often cut off faces or important subjects, especially when the subject is off-center.
- Each registered image size might need a different crop, forcing manual edits in the Media Library.
- Featured images look inconsistent across themes because the focal point shifts per aspect ratio.

## Proposed solution

1. **Subject detection** – Run an AI model (faces + general objects) when an image is uploaded or regenerated.
2. **Focal point metadata** – Store the X/Y coordinates as attachment meta so every image size can center around it.
3. **Smart cropping per size** – When WordPress generates each intermediate size, bias the crop toward the stored focal point.
4. **Manual override** – Provide UI controls in the Media Library image editor to adjust or reset the focal point.

### User flow

1. User uploads image with the subject off to one side.
2. Background job detects the dominant face/object and records focal coordinates.
3. All generated thumbnails align to that focal point.
4. If the detection is wrong, the editor opens the attachment details modal and drags the focal point marker; regenerated crops update automatically.

### Visual reference

```
Original: subject left aligned
+------------------+
|  [face]          |
|    |             |
+------------------+

Default crop (wrong)            Smart crop (correct)
+--------+                      +--------+
|        |                      | [face] |
+--------+                      +--------+
```

## Integration points

- **Media upload pipeline** – Hook into `wp_generate_attachment_metadata` to trigger detection.
- **Attachment details modal** – Show an “AI focal point” section with status, preview, and buttons (`Adjust`, `Reset to center`).
- **Image editor** – Add a smart-crop overlay so editors can refine suggestions.
- **Featured image + block rendering** – Because focal points live in attachment meta, any template that respects WordPress crops benefits automatically.
- **Regenerate thumbnails** – Offer a bulk “Reprocess with smart cropping” action for legacy libraries.

## Settings

| Setting | Options | Default |
|---------|---------|---------|
| Enable smart cropping | On/Off | On |
| Auto-apply on upload | On/Off | On |
| Detection model | Faces / Subjects / Both | Both |
| Fallback behavior | Center / Top / Bottom | Center |
| Allow manual override | On/Off | On |

## Technical considerations

- Reuse existing `add_image_size()` registrations; the only change is where the crop origin is anchored.
- Store focal point as normalized floats (0-1) in attachment meta so regenerations remain consistent even if the image is regenerated later.
- Processing should be asynchronous for large uploads; queue detections via Action Scheduler or a custom job runner.
- Consider privacy-sensitive sites that cannot send media to third-party APIs; allow local models or an opt-out.

## Bulk processing UI

```
Smart Cropping
Images without focal points: 234
[Process all] [Select images]
Progress: ███████░░ 70%
Currently processing: team-photo.jpg
```

## Open questions

1. Should we expose per-size overrides (e.g., hero images use different focal point)?
2. What happens if multiple subjects exist—do we let editors choose via a dropdown?
3. Do we surface confidence scores so editors know when to review manually?

## Labels

`enhancement`, `ai`, `media`, `images`, `accessibility`
