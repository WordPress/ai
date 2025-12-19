# Comment Moderation

## Summary
Adds AI-powered sentiment analysis, toxicity scoring, and reply suggestions to the classic Comments screen. Moderators can see badges directly in `edit-comments.php`, run bulk analysis, and request suggested replies without leaving wp-admin.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation::register()` wires everything once the experiment is enabled:
  - `wp_abilities_api_init` → registers `ai/comment-analysis` and `ai/reply-suggestion` abilities (`includes/Abilities/Comment_Moderation/*.php`).
  - `manage_edit-comments_columns`, `manage_comments_custom_column` → inject sentiment/toxicity columns.
  - `bulk_actions-edit-comments`, `handle_bulk_actions-edit-comments`, `admin_notices` → add the “Analyze with AI” bulk flow and status notices.
  - `comment_row_actions` → adds the “AI Reply” row action.
  - `admin_enqueue_scripts` → enqueues the React bundle on `edit-comments.php`.
  - `admin_head-edit-comments.php` → prints inline badge styles so they render even when JS fails.
- REST and comment-meta updates happen via the two abilities; the experiment itself only orchestrates UI + enqueue points.

## Assets & Data Flow
1. `enqueue_assets()` loads `experiments/comment-moderation` (`src/experiments/comment-moderation/index.tsx`) and localizes `window.CommentModerationData` with `enabled` + nonce.
2. The React entry mounts two controllers:
   - `LazyAnalysisController` polls for comments that need analysis and calls `runAbility( 'ai/comment-analysis' )`, updating comment meta and refreshing rows in place.
   - `ReplyModalController` opens a modal when an “AI Reply” row action is clicked, calling `runAbility( 'ai/reply-suggestion' )` to fetch draft replies the moderator can paste.
3. Both controllers rely on the shared `run-ability.ts` helper so they can use the Abilities API client when available or fall back to REST calls.
4. Ability responses are persisted via comment meta (`_ai_toxicity_score`, `_ai_sentiment`, `_ai_analysis_status`, `_ai_analyzed_at`), which the PHP column renderers read to display badges.

## Testing
1. Enable Experiments globally and toggle **Comment Moderation** under `Settings → AI Experiments`.
2. Visit `Comments → All Comments`. Pending comments should show “Analyze with AI” badges; clicking one should enqueue an analysis request and update the badge once complete.
3. Select multiple comments, choose the “Analyze with AI” bulk action, and confirm the inline notice reports how many were queued.
4. Approve a comment and click its “AI Reply” row action. The modal should display suggested replies; applying one should copy it into the WordPress reply form.
5. Toggle the experiment off and reload the page—columns, badges, row/bulk actions, and scripts should disappear.

## Notes
- The experiment only runs for users with `moderate_comments`.
- Analysis locks each comment while it is processing to prevent duplicate requests.
- Replies and analysis rely on AI credentials; without valid credentials the whole experiment remains disabled via the shared experiment toggle logic.
