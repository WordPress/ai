# Topic Pages: quick test guide

Use a test site with a block theme, a connected AI provider, and Topic Pages enabled.

1. **First guide:** Create a category with five public posts. Visit the archive: the timeline should appear without an AI request. Open the category’s Edit screen, Regenerate each text section, then Save to publish it.
2. **Preview:** Open Posts → Categories → Edit → Topic guide. Regenerate Summary. The preview should change; the public archive should keep its old text.
3. **Discard:** Discard the preview. The published text should remain unchanged, including after reloading.
4. **Save:** Regenerate again, then Save. Reload the archive. It should show the replacement. Repeat for Narrative overview.
5. **Presentation:** Check the quote-style summary has one paragraph and no source links. The overview should have exactly two paragraphs with underlined source links. Check the vertical timeline, header, footer, and normal post pagination. Test on a narrow screen too.

| Edge case | Expected result |
| --- | --- |
| Enable the experiment or publish a post | No AI requests or background jobs start |
| Four public posts, then a fifth | Normal archive at four; first guide becomes eligible at five |
| Posts only in a child category | They count toward the parent category |
| More than 20 posts | Generation still works; timeline has at most ten entries |
| Untitled posts or non-English text | Links stay readable; generation does not fail on cut-off characters |
| Several posts on the same date | No duplicate timeline entries; first and latest remain included |
| Reload or use the Category form’s Update with a preview pending | Preview remains private and can still be saved or discarded |
| Repeated clicks during generation | One request runs; controls stay disabled until it finishes |
| Change a source during generation | Result is rejected; regenerate using the updated articles |
| Two editors or tabs | An older tab cannot overwrite newer changes; it offers to load the latest version |
| Provider disconnected, unavailable, or invalid response | Clear error; existing public text and preview stay safe |
| Source becomes private, password-protected, deleted, or leaves the category | Affected AI text is hidden; source no longer appears in the timeline |
| Edit a source article | Screen marks affected text as needing regeneration; no automatic AI refresh |
| Logged out, Subscriber, or Author | Cannot use generation or publication controls |
| Custom template for one category | Custom template wins over the preset |
| Disable the experiment | Topic blocks and review controls disappear; saved content is retained |

Check the prose too: Summary should explain the current situation; Overview should explain how it developed. Read the linked sources before relying on AI claims.
