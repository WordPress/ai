# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

## [1.1.0] - 2026-06-30
### Added
- New Experiment: Type Ahead; automatically suggests ghost text at the end of paragraphs, can be manually triggered within a paragraph ([#151](https://github.com/WordPress/ai/pull/151), [#776](https://github.com/WordPress/ai/pull/776)).
- New Experiment: Key Encryption; encrypts AI Connector API keys before storing them in the database ([#560](https://github.com/WordPress/ai/pull/560)).
- Ensure all Features that rely on utilizing content are disabled until minimum content thresholds are met ([#581](https://github.com/WordPress/ai/pull/581)).
- New `core/read-settings` Ability ([#691](https://github.com/WordPress/ai/pull/691), [#806](https://github.com/WordPress/ai/pull/806)).
- New `wpai_has_image_generation_support` filter that allows 3rd parties to claim support for Image Generation, for example if authenticating without an API key ([#748](https://github.com/WordPress/ai/pull/748)).
- New setting to choose if guest comments should be moderated or not, defaulting to `yes` ([#751](https://github.com/WordPress/ai/pull/751)).
- Explicit save button to developer settings panel, requiring a user to click save before the Provider and Model settings are saved ([#761](https://github.com/WordPress/ai/pull/761)).
- Documentation callouts that the plugin has targeted support for the Block Editor only ([#766](https://github.com/WordPress/ai/pull/766)).

### Changed
- Note prompting the user to save after running Editorial Notes ([#682](https://github.com/WordPress/ai/pull/682)).
- Use `__next40pxDefaultSize` for buttons consistently ([#702](https://github.com/WordPress/ai/pull/702)).
- Skip Comment Analysis and Moderation when comment has already been flagged as spam/trash ([#743](https://github.com/WordPress/ai/pull/743)).
- Replace developer mode settings CSS with Stack component ([#785](https://github.com/WordPress/ai/pull/785)).
- Use character-based count instead of word-based to determine when features are available to use ([#802](https://github.com/WordPress/ai/pull/802)).
- Use `Notice` component to display warnings within experiment modals ([#803](https://github.com/WordPress/ai/pull/803)).
- Added success snackbar when saving or resetting developer settings ([#807](https://github.com/WordPress/ai/pull/807)).

### Fixed
- Add `wordCountType` to check for user's locale and update to count character or words when detecting minimum content length ([#581](https://github.com/WordPress/ai/pull/581)).
- Restrict Content Resizing to REST-exposed post types when a post ID is provided ([#658](https://github.com/WordPress/ai/pull/658)).
- Only show Editorial Updates button when pending Notes are linked to current blocks ([#682](https://github.com/WordPress/ai/pull/682)).
- Hide developer settings on stable Features when AI is disabled ([#737](https://github.com/WordPress/ai/pull/737)).
- Improve readability of Ability Explorer schema output by preventing unicode escaping for non‑ASCII characters ([#740](https://github.com/WordPress/ai/pull/740)).
- The "Last 30 Days" summary period in the AI Requests Logs page now uses a fixed 30-day window so the summary cards and logs table cover the same span ([#753](https://github.com/WordPress/ai/pull/753)).
- Developer Tools popover overlapping the WP admin bar ([#756](https://github.com/WordPress/ai/pull/756)).
- Persistence of suggested terms when running the Content Classification experiment ([#769](https://github.com/WordPress/ai/pull/769)).
- Restored term suggestion pills to their original positions if the backend term assignment API fails, resolving stale state race conditions ([#772](https://github.com/WordPress/ai/pull/772)).
- Preserve omitted `runAbility()` input so ability schema defaults can apply when abilities are invoked without input ([#775](https://github.com/WordPress/ai/pull/775)).
- Ensure scalar input schemas are allowed in the Abilities Explorer validation ([#787](https://github.com/WordPress/ai/pull/787)).
- Standardize the Title Generation button text ([#790](https://github.com/WordPress/ai/pull/790)).
- Scope the Editorial Note generation loading spinner only to the block currently being reviewed ([#794](https://github.com/WordPress/ai/pull/794)).
- Snackbar notifications no longer overlap the settings content; they are pinned to the bottom-left of the content area ([#801](https://github.com/WordPress/ai/issues/801)).

### Developer
- Skip push-triggered CI workflows on forks ([#722](https://github.com/WordPress/ai/pull/722)).
- Remove `any` type assertions from notices store dispatches ([#725](https://github.com/WordPress/ai/pull/725)).
- Fix link to AI plugin roadmap view on the GitHub Project board ([#742](https://github.com/WordPress/ai/pull/742)).
- Updated Content Summarization E2E selectors to use Playwright user-facing attributes ([#762](https://github.com/WordPress/ai/pull/762)).
- Updated Title Generation and Editorial Notes E2E selectors to use Playwright user-facing attributes ([#773](https://github.com/WordPress/ai/pull/773)).
- Extracts repeated inline Developer Tools menu interactions for E2E tests into reusable utility functions ([#786](https://github.com/WordPress/ai/pull/786)).
- Update Issue templates ([#796](https://github.com/WordPress/ai/pull/796), [#804](https://github.com/WordPress/ai/pull/804)).
- Playground PR link now redirects users to `/wp-admin/admin.php?page=ai-wp-admin` ([#797](https://github.com/WordPress/ai/pull/797)).
- Bump `phpstan/phpstan` from 2.2.1 to 2.2.2 and `phpstan/php-8-stubs` from 0.4.35 to 0.4.36 ([#744](https://github.com/WordPress/ai/pull/744)).
- Bumps `shivammathur/setup-php` from 2.37.1 to 2.37.2 and `codecov/codecov-action` from 6.0.1 to 7.0.0 ([#745](https://github.com/WordPress/ai/pull/745)).
- Bump `@wordpress/build` from 0.16.0 to 0.16.1 ([#754](https://github.com/WordPress/ai/pull/754)).
- Bump `wp-phpunit/wp-phpunit` from 6.9.4 to 7.0.0 ([#779](https://github.com/WordPress/ai/pull/779)).
- Bump `actions/checkout` from 6.0.3 to 7.0.0 and `softprops/action-gh-release` from 3.0.0 to 3.0.1 ([#780](https://github.com/WordPress/ai/pull/780)).

## [1.0.2] - 2026-06-16
### Added
- Manual refresh button to the AI Request Logs table header ([#687](https://github.com/WordPress/ai/pull/687)).
- New `ai_generated` param on our Image Import Ability to set if the imported image was AI generated or not ([GHSA-42mg-ffvx-4xff](https://github.com/WordPress/ai/security/advisories/GHSA-42mg-ffvx-4xff)).

### Changed
- Ensure Editorial Notes and Editorial Updates controls stay grouped together in the post editor sidebar ([#605](https://github.com/WordPress/ai/pull/605)).
- Use explicit UTF-8 encoding for generated Meta Description character counts ([#655](https://github.com/WordPress/ai/pull/655)).
- Return a consistent decorative flag from Alt Text Generation results ([#659](https://github.com/WordPress/ai/pull/659)).
- Show an error message immediately in the Image Generation UI when there's no AI Connector in place that supports image generation ([#679](https://github.com/WordPress/ai/pull/679)).
- Use a neutral icon for disabled Features and Experiments in the AI Status widget ([#720](https://github.com/WordPress/ai/pull/720)).

### Fixed
- Abilities Explorer schema validation ([#612](https://github.com/WordPress/ai/pull/612)).
- Alt Text Generation button becomes unresponsive after using Next/Previous in the media modal ([#631](https://github.com/WordPress/ai/pull/631)).
- Add descriptive accessible labels to approval matrix toggle controls ([#637](https://github.com/WordPress/ai/pull/637)).
- Added accessible labels to the Provider and Category filter dropdowns on the Abilities Explorer page ([#642](https://github.com/WordPress/ai/pull/642)).
- Lost focus after generating a Title ([#644](https://github.com/WordPress/ai/pull/644)).
- Lost focus when generating Alt Text in Image block inspector controls ([#645](https://github.com/WordPress/ai/pull/645)).
- Lost focus when toggling the Connector Approval state ([#646](https://github.com/WordPress/ai/pull/646)).
- Lost focus after generating Images ([#647](https://github.com/WordPress/ai/pull/647)).
- Added an accessible label to the ability test payload textarea in the Abilities Explorer ([#649](https://github.com/WordPress/ai/pull/649)).
- Excerpt generation post context payload ([#651](https://github.com/WordPress/ai/pull/651)).
- Clear out the Meta Description suggestion when the modal closes ([#653](https://github.com/WordPress/ai/pull/653)).
- Lost focus after running Content Resizing actions ([#663](https://github.com/WordPress/ai/pull/663)).
- Column reordering and hiding in the AI Request Logs table now persists instead of resetting to the default ([#669](https://github.com/WordPress/ai/pull/669)).
- Summary statistics showing zero for short time periods on non-UTC MySQL servers ([#671](https://github.com/WordPress/ai/pull/671)).
- UI inconsistency on AI Request Logs page ([#676](https://github.com/WordPress/ai/pull/676)).
- Ensure thinking tokens are counted in AI Request Logs ([#680](https://github.com/WordPress/ai/pull/680)).
- Ensure the Ability schemas and outputs are valid JSON Schema for strict REST and MCP consumers ([#688](https://github.com/WordPress/ai/pull/688)).
- Title Generation button disappears after toggling off "Show template" ([#694](https://github.com/WordPress/ai/pull/694)).
- Prevent accidental interactions and stale feedback in the Meta Description Generation modal and improve focus handling ([#696](https://github.com/WordPress/ai/pull/696)).
- Ensure focus isn't lost after generating an Excerpt inline ([#698](https://github.com/WordPress/ai/pull/698)).
- AI Request Logs: "Copy Log ID" gives no feedback when copied ([#700](https://github.com/WordPress/ai/pull/700)).
- AI Request Logs: main header overlapping table header ([#705](https://github.com/WordPress/ai/pull/705)).
- Allow users to clear an applied Meta Description while preventing whitespace-only descriptions ([#706](https://github.com/WordPress/ai/pull/706)).
- Rename unforwarded `MaskCanvas` component function to `InnerMaskCanvas` to avoid duplicate declarations ([#713](https://github.com/WordPress/ai/pull/713)).

### Security
- Remove the `meta` param from our Image Import Ability ([GHSA-42mg-ffvx-4xff](https://github.com/WordPress/ai/security/advisories/GHSA-42mg-ffvx-4xff)).
- Check the current user's capabilities and the comment type before setting an Editorial Note ([GHSA-j7hg-vqpw-f98f](https://github.com/WordPress/ai/security/advisories/GHSA-j7hg-vqpw-f98f)).

### Developer
- Clarify AI Connector provider setup documentation ([#638](https://github.com/WordPress/ai/pull/638)).
- Add `@WordPress/ai-maintainers` team ([#677](https://github.com/WordPress/ai/pull/677)).
- Removes the `ready_for_review` pull request event from the Test and Plugin Check GitHub Actions workflows ([#703](https://github.com/WordPress/ai/pull/703)).
- Bump `tmp` from 0.2.5 to 0.2.7 ([#630](https://github.com/WordPress/ai/pull/630)).
- Bump `phpstan/php-8-stubs` from 0.4.34 to 0.4.35 ([#635](https://github.com/WordPress/ai/pull/635)).
- Bump `phpstan/phpstan` from 2.1.54 to 2.2.1 ([#635](https://github.com/WordPress/ai/pull/635), [#672](https://github.com/WordPress/ai/pull/672)).
- Bump `codecov/codecov-action` from 6.0.0 to 6.0.1 ([#636](https://github.com/WordPress/ai/pull/636)).
- Bump `WordPress/action-wp-playground-pr-preview` to latest version ([#673](https://github.com/WordPress/ai/pull/673)).
- Bump `actions/checkout` from 6.0.2 to 6.0.3 ([#707](https://github.com/WordPress/ai/pull/707)).
- Bump `wordpress/plugin-check-action` from v1.1.6 to v1.1.7 ([#726](https://github.com/WordPress/ai/pull/726)).
- Update NPM dev-depependencies ([#712](https://github.com/WordPress/ai/pull/712)).

## [1.0.1] - 2026-05-27
### Added
- New helper functions that are used to determine if we have valid AI Connector credentials ([#603](https://github.com/WordPress/ai/pull/603)).
- New helper methods, `is_globally_enabled` and `is_individually_enabled` to help tell if a feature is enabled individually or if features are globally enabled ([#604](https://github.com/WordPress/ai/pull/604)).

### Changed
- Removed the description from the Abilities listing within the Abilities Explorer ([#592](https://github.com/WordPress/ai/pull/592)).
- Filter Guideline queries by the guideline type content ([#593](https://github.com/WordPress/ai/pull/593)).
- Use the new `has_connector_authentication` instead of `is_connector_configured` to avoid unnecessary API requests ([#603](https://github.com/WordPress/ai/pull/603)).

### Removed
- Deprecated `__nextHasNoMarginBottom` prop ([#609](https://github.com/WordPress/ai/pull/609)).

### Fixed
- Utilize a new `is_connector_configured` function to properly determine if a connector is configured, whether via an API key, constant or ENV var ([#537](https://github.com/WordPress/ai/pull/537)).
- "Generate Editorial Note" button appearing in the block settings menu during post revisions ([#591](https://github.com/WordPress/ai/pull/591)).
- If the Connector Approvals experiment is turned on, ensure we don't over-aggressively block functionality in the AI plugin that isn't actually making requests, like Request Logging ([#595](https://github.com/WordPress/ai/pull/595)).
- Better matching of the originating code when the Connector Approvals experiment is on ([#595](https://github.com/WordPress/ai/pull/595)).
- Focus loss issues when interacting with Purge actions in the Request Logs experiments page ([#599](https://github.com/WordPress/ai/pull/599)).
- Disable the "Purge All" button when no logs are available to purge ([#599](https://github.com/WordPress/ai/pull/599)).
- AI Status feature checklist properly shows if an individual feature is enabled even if globally features are disabled ([#604](https://github.com/WordPress/ai/pull/604)).
- Ensure focus isn't lost when buttons enter disabled state during Alt Text Generation, Content Classification, Content Summarization, Excerpt Generation, Featured Image Generation, and Title Generation ([#608](https://github.com/WordPress/ai/pull/608), [#611](https://github.com/WordPress/ai/pull/611)).
- Settings page strings, which are enqueued as script modules, are now localized at runtime ([#613](https://github.com/WordPress/ai/pull/613)).
- Connector Approvals "Dismiss" button failing for pending requests whose key contains a slash ([#615](https://github.com/WordPress/ai/pull/615)).
- Hide empty provider capabilities section in the dashboard widget ([#616](https://github.com/WordPress/ai/pull/616)).
- Playground and test configs now target the latest WordPress release instead of the beta release ([#626](https://github.com/WordPress/ai/pull/626)).
- Connector Approvals notice no longer overlaps the page header on the AI Request Logs screen ([#628](https://github.com/WordPress/ai/pull/628)).

### Developer
- Corrected installation instructions in readme ([#620](https://github.com/WordPress/ai/pull/620)).
- Bump `@wordpress/build` from 0.13.0 to 0.14.0 ([#606](https://github.com/WordPress/ai/pull/606)).
- Bump `@wordpress/scripts` from 32.1.0 to 32.2.0 ([#606](https://github.com/WordPress/ai/pull/606)).

## [1.0.0] - 2026-05-19
### Added
- New Experiment: Request Logging that provides observability for all AI operations ([#437](https://github.com/WordPress/ai/pull/437)).
- New Experiment: Connector Approvals that allows administrators the ability to determine which plugins can access which AI connectors ([#467](https://github.com/WordPress/ai/pull/467)).
- Integrate Alt Text generation into the experimental media editor ([#446](https://github.com/WordPress/ai/pull/446)).
- Sorting and filtering in Comments screen by Toxicity and/or Sentiment ([#518](https://github.com/WordPress/ai/pull/518)).
- Toxicity and Sentiment labelling in admin dashboard for comments ([#518](https://github.com/WordPress/ai/pull/518)).

### Changed
- Disable the Summarization button until content reaches a certain length ([#492](https://github.com/WordPress/ai/pull/492)).
- Refined image generation loading state ([#512](https://github.com/WordPress/ai/pull/512)).
- Featured image button now hides when image is already set ([#512](https://github.com/WordPress/ai/pull/512)).
- When no AI provider is configured and a feature is triggered, show actionable guidance directing users to configure an AI Connector ([#523](https://github.com/WordPress/ai/pull/523)).
- Update Meta Description loading state and remove duplicate heading in modal ([#527](https://github.com/WordPress/ai/pull/527)).
- Rename "Review Notes" experiment to "Editorial Notes" and "Refine from Notes" experiment to "Editorial Updates" ([#528](https://github.com/WordPress/ai/pull/528)).
- Keep comments without moderation metadata visible when sorting by Comment Moderation columns ([#538](https://github.com/WordPress/ai/pull/538)).
- Updated plugin banner and icons ([#546](https://github.com/WordPress/ai/pull/546)).
- Show a notice when a user has chosen a provider that no longer exists ([#552](https://github.com/WordPress/ai/pull/552)).
- When no provider is configured, show an error notice instead of an admin notice for alt text generation ([#561](https://github.com/WordPress/ai/pull/561)).
- Standardize error message text ([#562](https://github.com/WordPress/ai/pull/562)).
- Abilities Explorer page heading ([#585](https://github.com/WordPress/ai/pull/585)).

### Fixed
- Ensure we properly use the new client-side Abilities API ([#482](https://github.com/WordPress/ai/pull/482)).
- Keep keyboard focus on the Provider select when resetting per-feature developer settings to default ([#532](https://github.com/WordPress/ai/pull/532)).
- Deduplicate provider API requests on the settings page when developer mode is toggled on ([#542](https://github.com/WordPress/ai/pull/542)).
- Update the Playground Preview workflow to use `pluginData` instead of `pluginZipFile` ([#548](https://github.com/WordPress/ai/pull/548)).
- Empty space shown for Model field when saved provider no longer exists in developer settings ([#552](https://github.com/WordPress/ai/pull/552)).
- Prevent analyzing newly inserted comments when no provider is configured ([#554](https://github.com/WordPress/ai/pull/554)).
- Ensure the meta description modal doesn't open if no provider is configured ([#558](https://github.com/WordPress/ai/pull/558)).
- False error for alt text generation on decorative images in media library ([#559](https://github.com/WordPress/ai/pull/559)).
- Show a failed badge when comment analysis fails ([#568](https://github.com/WordPress/ai/pull/568)).
- Correct RTL rendering of directional icons, runtime-set styles, and inline styles in the admin UI ([#573](https://github.com/WordPress/ai/pull/573)).
- Add notice to standalone image generation when there is no provider connected ([#575](https://github.com/WordPress/ai/pull/575)).
- Ensure we show a more specific error message when no valid AI connector is in place and we try to generate a featured image ([#576](https://github.com/WordPress/ai/pull/576)).
- Improve keyboard focus visibility for suggested term actions in content classification ([#580](https://github.com/WordPress/ai/pull/580)).
- User-facing text in several experiments is now fully translatable, and JS-side translations are loaded at runtime ([#582](https://github.com/WordPress/ai/pull/582)).
- Make title generation and content classification UI react to current editor state ([#584](https://github.com/WordPress/ai/pull/584)).
- Ensure global AI enabled options are migrated properly ([#586](https://github.com/WordPress/ai/pull/586)).

### Developer
- Various documentation updates ([#475](https://github.com/WordPress/ai/pull/475), [#501](https://github.com/WordPress/ai/pull/501), [#540](https://github.com/WordPress/ai/pull/540), [#550](https://github.com/WordPress/ai/pull/550), [#569](https://github.com/WordPress/ai/pull/569)).
- Add an `.npmrc` config file ([#535](https://github.com/WordPress/ai/pull/535)).
- Add a 7 day cooldown period for GitHub Action updates triggered by Dependabot ([#553](https://github.com/WordPress/ai/pull/553)).
- Bump `phpstan/phpstan` from 2.1.51 to 2.1.54 ([#524](https://github.com/WordPress/ai/pull/524)).
- Bump `@wordpress/build` from 0.12.0 to 0.13.0 ([#525](https://github.com/WordPress/ai/pull/525)).
- Bump `@wordpress/scripts` from 32.0.0 to 32.1.0 ([#525](https://github.com/WordPress/ai/pull/525)).
- Bump `dealerdirect/phpcodesniffer-composer-installer` from 1.2.0 to 1.2.1 ([#555](https://github.com/WordPress/ai/pull/555)).
- Bump `shivammathur/setup-php` from 2.37.0 to 2.37.1 ([#556](https://github.com/WordPress/ai/pull/556)).
- Bump `actions/dependency-review-action` from 4.9.0 to 5.0.0 ([#556](https://github.com/WordPress/ai/pull/556)).
- Bump `wordpress/plugin-check-action` from 1.1.5 to 1.1.6 ([#556](https://github.com/WordPress/ai/pull/556)).

## [0.9.0] - 2026-05-07
### Added
- New Experiment: Comment Moderation to automatically moderate comments based on toxicity detection and sentiment analysis ([#155](https://github.com/WordPress/ai/pull/155), [#516](https://github.com/WordPress/ai/pull/516)).
- New Experiment: Content Resizing to shorten, expand, or rephrase selected block content ([#331](https://github.com/WordPress/ai/pull/331)).
- Developer Mode settings page toggle to set the desired provider and model per feature ([#486](https://github.com/WordPress/ai/pull/486)).
- WP-CLI command, `wp ai alt-text generate`, for bulk alt text generation ([#436](https://github.com/WordPress/ai/pull/436)).
- Basic styles for the Content Summary block ([#510](https://github.com/WordPress/ai/pull/510)).

### Changed
- Compress the AI settings page by moving the global AI toggle into the header with an infotip ([#455](https://github.com/WordPress/ai/pull/455)).
- Update AI settings page to use `@wordpress/ui` components and related UI adjustments ([#472](https://github.com/WordPress/ai/pull/472), [#488](https://github.com/WordPress/ai/pull/488), [#490](https://github.com/WordPress/ai/pull/490), [#491](https://github.com/WordPress/ai/pull/491), [#505](https://github.com/WordPress/ai/pull/505), [#519](https://github.com/WordPress/ai/pull/519)).
- AI-generated images are now saved with descriptive, slugified filenames derived from the post title or prompt instead of `ai-generated-image-<timestamp>` ([#471](https://github.com/WordPress/ai/pull/471)).
- For image generation, set guidelines as part of the prompt instead of system instructions ([#497](https://github.com/WordPress/ai/pull/497)).
- Update the Content Summary experiment to render the summary in a Group variation block instead of a Paragraph variation block ([#510](https://github.com/WordPress/ai/pull/510)).

### Fixed
- Standards compliance switch from the custom `$builder->is_text_generation_supported()` method with the abstract `ensure_text_generation_supported()` method ([#465](https://github.com/WordPress/ai/pull/465)).
- Ability schema JSON viewer now stays LTR under RTL admin languages ([#485](https://github.com/WordPress/ai/pull/485)).
- Ensure the Generate Image button doesn't render in contexts that aren't valid ([#489](https://github.com/WordPress/ai/pull/489)).
- Localize several user-facing fallback error strings in image-generation and summarization flows ([#500](https://github.com/WordPress/ai/pull/500)).

### Security
- Bump `serialize-javascript` from 6.0.2 to 7.0.5 ([#503](https://github.com/WordPress/ai/pull/503)).
- Bump `postcss` from 8.5.10 to 8.5.14 ([#503](https://github.com/WordPress/ai/pull/503)).
- Bump `minimatch` from 3.0.8 to 3.1.4 ([#503](https://github.com/WordPress/ai/pull/503)).

### Developer
- Ignore `/.wp-env.test.override.json` so local test-only `wp-env` overrides stay untracked ([#484](https://github.com/WordPress/ai/pull/484)).
- Add E2E coverage for Dashboard Widgets ([#498](https://github.com/WordPress/ai/pull/498)).
- Add custom experiment reference documentation for developers extending the plugin ([#499](https://github.com/WordPress/ai/pull/499)).
- Add squash merge commit approach to developer guide ([#504](https://github.com/WordPress/ai/pull/504), [#511](https://github.com/WordPress/ai/pull/511), [#521](https://github.com/WordPress/ai/pull/521)).
- Bump `phpstan/phpstan` from 2.1.46 to 2.1.51 ([#468](https://github.com/WordPress/ai/pull/468), [#493](https://github.com/WordPress/ai/pull/493)).
- Bump `actions/setup-node` from 6.3.0 to 6.4.0 ([#469](https://github.com/WordPress/ai/pull/469)).

## [0.8.0] - 2026-04-23
### Added
- New Experiment: Refine from Notes, automatically apply editorial notes to content ([#289](https://github.com/WordPress/ai/pull/289)).
- AI Status and AI Capabilities dashboard widgets, plus framework for registering new dashboard widgets ([#311](https://github.com/WordPress/ai/pull/311)).
- Integrates Gutenberg's Guidelines allowing abilities to respect site-wide editorial standards ([#359](https://github.com/WordPress/ai/pull/359)).
- Check `wp_supports_ai()` before initializing experiments ([#268](https://github.com/WordPress/ai/pull/268)).
- Admin redirect from the old `ai` page to the new `ai-wp-admin` page ([#424](https://github.com/WordPress/ai/pull/424)).
- Set the new `gpt-image-2` model for our preferred model list ([#456](https://github.com/WordPress/ai/pull/456)).

### Changed
- Promote Image Generation from an Experiment to a Feature ([#418](https://github.com/WordPress/ai/pull/418)).
- Title Generation now utilizes a modal for editing and regeneration before applying changes to the Post Title ([#290](https://github.com/WordPress/ai/pull/290)).
- Update feature descriptions to include AI provider model supports ([#377](https://github.com/WordPress/ai/pull/377)).
- Update button loading states to match the standard loading pattern ([#382](https://github.com/WordPress/ai/pull/382), [#389](https://github.com/WordPress/ai/pull/389), [#396](https://github.com/WordPress/ai/pull/396), [#433](https://github.com/WordPress/ai/pull/433), [#449](https://github.com/WordPress/ai/pull/449)).
- Refactor `Main` bootstrap class ([#404](https://github.com/WordPress/ai/pull/404)).
- Allow bulk enabling/disabling Experiments in groups ([#422](https://github.com/WordPress/ai/pull/422)).
- Improve visual hierarchy on the AI settings page so card titles are more prominent than the toggle labels ([#431](https://github.com/WordPress/ai/pull/431)).
- Reduce the context we send when running Review Notes to decrease the amount of tokens used ([#434](https://github.com/WordPress/ai/pull/434)).
- Refactor `strpos` to `str_starts_with` and `str_contains` ([#438](https://github.com/WordPress/ai/pull/438)).
- Render Review Notes only on post types that support `editor.notes` ([#444](https://github.com/WordPress/ai/pull/444)).
- Improve accessibility of the Meta Description modal: inline "Copied!" confirmation on the copy button and accessibleWhenDisabled on disabled controls ([#445](https://github.com/WordPress/ai/pull/445)).
- Refactor `Asset_Loader` class and add error checking when dependencies are missing ([#458](https://github.com/WordPress/ai/pull/458)).

### Removed
- Remove references to DALL·E image models ([#414](https://github.com/WordPress/ai/pull/414)).

### Fixed
- Excerpt and Title generation no longer include conversational preambles, wrapper quotes, markdown, or meta-commentary when using smaller language models ([#440](https://github.com/WordPress/ai/pull/440)).
- Defer failed `Requirements` messages until translation functions are available ([#453](https://github.com/WordPress/ai/pull/453)).

### Developer
- Align testing docs with current test setup ([#393](https://github.com/WordPress/ai/pull/393)).
- Add `dependabot.yml` config file with cooldowns and groupings ([#405](https://github.com/WordPress/ai/pull/405)).
- Bump `actions/checkout` from 5.0.0 to 6.0.2 ([#407](https://github.com/WordPress/ai/pull/407)).
- Bump `shivammathur/setup-php` from 2.35.4 to 2.37.0 ([#407](https://github.com/WordPress/ai/pull/407)).
- Bump `actions/setup-node` from 5.0.0 to 6.3.0 ([#407](https://github.com/WordPress/ai/pull/407)).
- Bump `ramsey/composer-install` from 3.1.1 to 4.0.0 ([#407](https://github.com/WordPress/ai/pull/407)).
- Bump `actions/upload-artifact` from 4.6.2 to 7.0.1 ([#407](https://github.com/WordPress/ai/pull/407), [#442](https://github.com/WordPress/ai/pull/442)).
- Bump `actions/dependency-review-action` from 4.8.2 to 4.9.0 ([#407](https://github.com/WordPress/ai/pull/407)).
- Bump `actions/github-script` from 7.0.1 to 8.0.0 ([#407](https://github.com/WordPress/ai/pull/407)).
- Bump `actions/cache` from 4.2.4 to 5.0.5 ([#407](https://github.com/WordPress/ai/pull/407), [#442](https://github.com/WordPress/ai/pull/442)).
- Bump `actions/github-script` from 8.0.0 to 9.0.0 ([#415](https://github.com/WordPress/ai/pull/415)).
- Update `blueprint.json` to use WordPress beta version ([#423](https://github.com/WordPress/ai/pull/423)).
- Bump `basic-ftp` from 5.2.1 to 5.2.2 ([#426](https://github.com/WordPress/ai/pull/426)).
- Update PR template sections ([#429](https://github.com/WordPress/ai/pull/429)).
- Ensure our JS lint script excludes the `build-scripts` directory ([#432](https://github.com/WordPress/ai/pull/432)).
- Bump `softprops/action-gh-release` from 2.6.1 to 3.0.0 ([#442](https://github.com/WordPress/ai/pull/442)).
- Update all NPM dependencies and migrate configs ([#447](https://github.com/WordPress/ai/pull/447)).
- Add `npm run format`, and apply repo-wide formatting ([#463](https://github.com/WordPress/ai/pull/463)).

## [0.7.0] - 2026-04-09
### Added
- New Experiment: Content Classification to generate taxonomy terms based on post content ([#313](https://github.com/WordPress/ai/pull/313)).
- New Experiment: SEO Descriptions that provides AI-generated meta description support ([#318](https://github.com/WordPress/ai/pull/318)).
- Added a bulk "Generate Alt Text" action to Media Library to generate alt text for multiple images at once ([#330](https://github.com/WordPress/ai/pull/330)).
- Added Category filtering to the Abilities table to improve organization and discoverability ([#355](https://github.com/WordPress/ai/pull/355)).
- Added extensibility hooks for customizing system instructions, and post context during AI operations ([#304](https://github.com/WordPress/ai/pull/304)).
- Added a new `wpai_has_ai_credentials` filter to allow 3rd parties to modify the credential detection logic, for instance to support non-API-key connectors to report their configured status ([#337](https://github.com/WordPress/ai/pull/337)).

### Changed
- Adjust Alt Text Generation to better align with the W3C Alt Text decision tree guidance ([#374](https://github.com/WordPress/ai/pull/374)).
- Updated AI settings page leveraging modern `wp-build` DataForm route ([#340](https://github.com/WordPress/ai/pull/340), [#376](https://github.com/WordPress/ai/pull/376)).
- Revised Feature and Experiment Lifecycle and other documentation updates ([#326](https://github.com/WordPress/ai/pull/326), [#329](https://github.com/WordPress/ai/pull/329)).
- Update some of our system instructions to prompt the LLM to return content in the same language as the original content they were given ([#357](https://github.com/WordPress/ai/pull/357)).
- Updated end-to-end tests to resolve flaky failures and account for markup changes in the Connectors screen ([#360](https://github.com/WordPress/ai/pull/360)).
- Updated preferred models to more recent ones for the three default providers ([#361](https://github.com/WordPress/ai/pull/361)).
- Updated provider compatibility checks to use the AI Client's built-in `is_supported_*` methods for improved validation and error reporting ([#362](https://github.com/WordPress/ai/pull/362)).
- Updated the PR preview workflow to use a preferred WordPress version for improved consistency during testing ([#366](https://github.com/WordPress/ai/pull/366)).
- Switch to using a `Button` component instead of a `ToolbarButton` component within the Title Generation Experiment when in normal editing mode (non-template mode) ([#375](https://github.com/WordPress/ai/pull/375)).

### Removed
- Unneeded `function_exists` checks ([#378](https://github.com/WordPress/ai/pull/378)).

### Fixed
- Improved error messages when Image Generation or Editing fails due to incompatible providers ([#332](https://github.com/WordPress/ai/pull/332)).
- Fixed an issue where Title Generation could fail when using the Anthropic provider ([#341](https://github.com/WordPress/ai/pull/341)).
- Invalid schema type in the summarization ability that prevented proper execution in some environments ([#347](https://github.com/WordPress/ai/pull/347)).
- Fixed an issue where the Generate Alt Text button could appear when an Image block was not selected, particularly when working with Patterns ([#356](https://github.com/WordPress/ai/pull/356)).
- Fixed an issue where repeated calls to load system instructions could return empty content ([#358](https://github.com/WordPress/ai/pull/358)).
- Fixed an issue where retrieving post content did not always return the most recently edited version ([#367](https://github.com/WordPress/ai/pull/367)).

### Developer
- Bump `flatted` from 3.3.3 to 3.4.2 ([#328](https://github.com/WordPress/ai/pull/328)).
- Bump `lodash-es` from 4.17.23 to 4.18.1 ([#369](https://github.com/WordPress/ai/pull/369)).
- Bump `lodash` from 4.17.23 to 4.18.1 ([#370](https://github.com/WordPress/ai/pull/370)).
- Bump `node-forge` from 1.3.3 to 1.4.0 ([#371](https://github.com/WordPress/ai/pull/371)).
- Bump `picomatch` from 2.3.1 to 2.3.2 and from 4.0.3 to 4.0.4 ([#372](https://github.com/WordPress/ai/pull/372)).
- Bump `yaml` from 1.10.2 to 1.10.3 and from 2.8.2 to 2.8.3 ([#373](https://github.com/WordPress/ai/pull/373)).
- Updates Composer & NPM to their latest (semver-comptible) versions ([#401](https://github.com/WordPress/ai/pull/401)).

## [0.6.0] - 2026-03-20
**There are Breaking Changes in this release.**

### Breaking Changes
- Refactor `Experiments` to be a type of `Feature`, improving how functionality is organized and surfaced ([#316](https://github.com/WordPress/ai/pull/316)).

The following classes have been removed. Anyone that was directly using these will need to make updates to utilize the correct replacements: `Abstract_Experiment`, `Invalid_Experiment_Metadata_Exception`, `Invalid_Experiment_Exception`, `Experiment_Loader`, `Experiment_Registry`.

- Standardize the Title Generation Ability to align with other registered Abilities ([#227](https://github.com/WordPress/ai/pull/227)).

The `ai/title-generation` Ability now uses a `context` argument instead of a `post_id` argument in the `input_schema`. Anyone directly using this Ability will need to make updates to account for that.

### Added
- New Experiment: Image Editing via prompt-based image refining in the Post Editor and Media Library ([#292](https://github.com/WordPress/ai/pull/292)).
- New Experiment: Image Editing via expanding or removing background and removing or replacing items in the Media Libary ([#305](https://github.com/WordPress/ai/pull/305), [#312](https://github.com/WordPress/ai/pull/312)).

### Changed
- Rename the plugin from "AI Experiments" to "AI" ([#287](https://github.com/WordPress/ai/pull/287)).
- Replace `Invalid_Experiment_Exception` with `_doing_it_wrong()` ([#303](https://github.com/WordPress/ai/pull/303)).
- Rename hook prefixes in `helpers.php` ([#315](https://github.com/WordPress/ai/pull/315)).
- Rename plugin constants to `WPAI_*` ([#317](https://github.com/WordPress/ai/pull/317)).
- Refactor the upgrade routine and add v0.6.0 migrations ([#321](https://github.com/WordPress/ai/pull/321)).
- Move the Generate Alt Text button to the new Content tab for improved discoverability ([#306](https://github.com/WordPress/ai/pull/306)).
- Remove stray "AI" references from UI for improved consistency ([#320](https://github.com/WordPress/ai/pull/320)).
- Update documentation ([#314](https://github.com/WordPress/ai/pull/314)).

### Fixed
- Remove duplicate error display in the Generate Alt Text flow ([#255](https://github.com/WordPress/ai/pull/255)).

## [0.5.0] - 2026-03-12
**Note this version bumps the WordPress minimum supported version from 6.9 to 7.0.**

### Added
- Switch to using AI Client bundled in WordPress 7.0 ([#275](https://github.com/WordPress/ai/pull/275), [#301](https://github.com/WordPress/ai/pull/301)).

### Changed
- Bump WordPress minimum supported version from 6.9 to 7.0 ([#272](https://github.com/WordPress/ai/pull/272)).
- Bump WordPress tested-up-to version 7.0 ([#272](https://github.com/WordPress/ai/pull/272)).
- Migrate credentials from the AI Credentials to the new Connectors screen ([#286](https://github.com/WordPress/ai/pull/286)).
- Improve documentation and plugin assets ([#280](https://github.com/WordPress/ai/pull/280), [#281](https://github.com/WordPress/ai/pull/281), [#291](https://github.com/WordPress/ai/pull/291), [#293](https://github.com/WordPress/ai/pull/293), [#296](https://github.com/WordPress/ai/pull/296)).

### Removed
- No longer using AI Client via Composer package ([#271](https://github.com/WordPress/ai/pull/271)).

### Developer
- Bump `simple-git` from 3.30.0 to 3.33.0 ([#295](https://github.com/WordPress/ai/pull/295)).

## [0.4.1] - 2026-03-06
### Fixed
- Issues with 0.4.0 release merge and deploy ([#266](https://github.com/WordPress/ai/pull/266)).

### Developer
- Bump `immutable` from 5.1.4 to 5.1.5 ([#273](https://github.com/WordPress/ai/pull/273)).
- Bump `svgo` from 3.3.2 to 3.3.3 ([#274](https://github.com/WordPress/ai/pull/274)).
- Updated Release Instructions documentation ([#277](https://github.com/WordPress/ai/pull/277)).

## [0.4.0] - 2026-03-05
### Added
- Inline Image Generation directly in the post editor, enabling users to generate images without leaving authoring/editing flows ([#235](https://github.com/WordPress/ai/pull/235)).
- Generate Image within the Media Library with prompt-based image generation workflows ([#258](https://github.com/WordPress/ai/pull/258)).
- Generate Review Notes experiment to analyze post content or individual blocks and suggest refinements via Notes comments in the editor ([#260](https://github.com/WordPress/ai/pull/260), [#267](https://github.com/WordPress/ai/pull/267)).
- Split editor and admin experiments within the settings page ([#232](https://github.com/WordPress/ai/pull/232)).
- Contextual help text to the Abilities Explorer screen to assist users in understanding what Abilities are and how to use them ([#243](https://github.com/WordPress/ai/pull/243)).

### Changed
- Update “Generate Summary” button style to use consistent UI with other buttons in the ediot ([#253](https://github.com/WordPress/ai/pull/253)).
- Standardize Abilities invocation using the `runAbility` helper to improve consistency across API calls ([#228](https://github.com/WordPress/ai/pull/228)).
- Make provider labels in the Abilities Explorer translatable and adjust badge styling for clarity ([#247](https://github.com/WordPress/ai/pull/247)).
- Improve Abilities Explorer table layout by aligning spacing and styles with WordPress admin table conventions ([#248](https://github.com/WordPress/ai/pull/248)).
- Improve the Ability test page with better internationalization and add copy-to-clipboard functionality ([#256](https://github.com/WordPress/ai/pull/256)).

### Removed
- Remove unused checkbox column from the Abilities Explorer table, as it was not tied to any bulk actions ([#246](https://github.com/WordPress/ai/pull/246)).

### Fixed
- Fix the position and behavior of the “Copy” button in code blocks within the Abilities Explorer ([#245](https://github.com/WordPress/ai/pull/245)).

### Developer
- Bump `basic-ftp` from 5.1.0 to 5.2.0 ([#259](https://github.com/WordPress/ai/pull/259)).

## [0.3.1] - 2026-02-18
### Fixed
- Increased image generation request timeout from 30s to 90s to reduce failed generations on slower providers/models ([#226](https://github.com/WordPress/ai/pull/226)).

### Developer
- Added Experiment lifecycle and contribution criteria documentation, plus general doc tidy-ups to better explain how experiments land in (and could eventually graduate from) the plugin ([#219](https://github.com/WordPress/ai/pull/219)).
- Updated the Pull Request template to include an “AI tools usage” disclosure section, aligned with the [equivalent change in core](https://github.com/WordPress/wordpress-develop/pull/10850) ([#217](https://github.com/WordPress/ai/pull/217)).
- Bump `qs` from 6.14.1 to 6.14.2 ([#229](https://github.com/WordPress/ai/pull/229)).

## [0.3.0] - 2026-02-09
### Added
- Content Summarization Experiment, allowing authors to generate and store AI-powered summaries directly in the post editor ([#147](https://github.com/WordPress/ai/pull/147)).
- Featured Image Generation Experiment, enabling AI-generated featured images from the editor sidebar with optional alt text and AI attribution metadata ([#146](https://github.com/WordPress/ai/pull/146)).
- Alt Text Generation Experiment, supporting images within Image blocks and Media Library workflows ([#156](https://github.com/WordPress/ai/pull/156)).
- “Experiments” and “Credentials” quick action links to the Installed Plugins screen for faster configuration ([#206](https://github.com/WordPress/ai/pull/206)).

### Changed
- Replace the global “Enable Experiments” checkbox with an auto-submitting enable/disable button to reduce friction when toggling experiments ([#168](https://github.com/WordPress/ai/pull/168)).

### Fixed
- Improve robustness of asset loading to handle missing or invalid built files and prevent admin and editor warnings ([#175](https://github.com/WordPress/ai/pull/175)).
- Add missing strict typing declarations in the Abilities Explorer to ensure consistency and correctness ([#208](https://github.com/WordPress/ai/pull/208)).

### Developer
- Streamline and clarify Contributor and Developer documentation to improve onboarding and reduce duplication ([#169](https://github.com/WordPress/ai/pull/169)).
- Fix inline documentation issues, including missing `@global` tags, non-standard hook tags, and incomplete `@return` descriptions ([#207](https://github.com/WordPress/ai/pull/207), [#210](https://github.com/WordPress/ai/pull/210)).
- Bump `phpunit/phpunit` from 9.6.31 to 9.6.33 as part of ongoing test and tooling maintenance ([#209](https://github.com/WordPress/ai/pull/209)).
- Expand and align allowed open source licenses in dependency configuration to better match Gutenberg and ecosystem tooling ([#212](https://github.com/WordPress/ai/pull/212), [#213](https://github.com/WordPress/ai/pull/213), [#214](https://github.com/WordPress/ai/pull/214)).

## [0.2.1] - 2026-01-26
### Added
- Introduced a shared `AI_Service` layer to standardize provider access across experiments ([#101](https://github.com/WordPress/ai/pull/101)).

### Changed
- Documentation updates ([#195](https://github.com/WordPress/ai/pull/195)).

### Fixed
- Guarded against `preg_replace()` returning `null` to prevent content corruption in `normalize_content()` ([#177](https://github.com/WordPress/ai/pull/177)).

### Security
- Change our user permission checks to use `edit_post` instead of `read_post` ([GHSA-mxf5-gp98-93wv](https://github.com/WordPress/ai/security/advisories/GHSA-mxf5-gp98-93wv)).

### Developer
- Bumped `diff` from 4.0.2 to 4.0.4 ([#196](https://github.com/WordPress/ai/pull/196)).
- Bumped `lodash-es` from 4.17.22 to 4.17.23 ([#198](https://github.com/WordPress/ai/pull/198)).
- Bumped `lodash` from 4.17.21 to 4.17.23 ([#199](https://github.com/WordPress/ai/pull/199)).

## [0.2.0] – 2026-01-20
### Added
- Core excerpt generation support for AI-powered summaries, including a new Excerpt Generation Experiment with editor UI ([#96](https://github.com/WordPress/ai/pull/96), [#143](https://github.com/WordPress/ai/pull/143)).
- Abilities Explorer - a new admin screen to view and interact with registered AI abilities in the plugin ([#63](https://github.com/WordPress/ai/pull/63)).
- Introduce foundational backend support for Content Summarization and Image Generation experiments (API-only; no UI yet) ([#134](https://github.com/WordPress/ai/pull/134), [#136](https://github.com/WordPress/ai/pull/136)).
- Improve plugin documentation and onboarding with expanded WP.org readme content ([#135](https://github.com/WordPress/ai/pull/135)).
- Add Playground preview support to build and PR workflows using the official WordPress action ([#144](https://github.com/WordPress/ai/pull/144)).

### Changed
- Rely on the Abilities API bundled with WordPress 6.9 and remove the previously bundled dependency (minimum WP version updated) ([#107](https://github.com/WordPress/ai/pull/107)).
- Reorganize Playground blueprints and update demo paths to align with WordPress.org conventions ([#137](https://github.com/WordPress/ai/pull/137)).
- Improve and clarify plugin documentation, descriptions, screenshots, and in-context messaging ([#69](https://github.com/WordPress/ai/pull/69), [#158](https://github.com/WordPress/ai/pull/158), [#161](https://github.com/WordPress/ai/pull/161), [#162](https://github.com/WordPress/ai/pull/162), [#164](https://github.com/WordPress/ai/pull/164)).
- Update and align runtime and development dependencies, including `preact`, `qs`, `express`, and React overrides ([#165](https://github.com/WordPress/ai/pull/165), [#166](https://github.com/WordPress/ai/pull/166), [#171](https://github.com/WordPress/ai/pull/171)).
- Replace custom Plugin Check setup with the official GitHub workflow for more reliable enforcement ([#139](https://github.com/WordPress/ai/pull/139)).

### Fixed
- Resolve UI and messaging issues on the AI Experiments settings screen ([#130](https://github.com/WordPress/ai/pull/130), [#132](https://github.com/WordPress/ai/pull/132)).
- Ensure AI Experiments are visible even when no credentials are configured ([#173](https://github.com/WordPress/ai/pull/173)).
- Fix Plugin Check, linting, and CI failures introduced by updated tooling and workflows ([#150](https://github.com/WordPress/ai/pull/150), [#163](https://github.com/WordPress/ai/pull/163), [#167](https://github.com/WordPress/ai/pull/167), [#176](https://github.com/WordPress/ai/pull/176)).

### Developer
- Cleanup and standardize scaffold, linting, TypeScript, and CI configuration to better align with WordPress Coding Standards ([#172](https://github.com/WordPress/ai/pull/172)).

## [0.1.1] - 2025-12-01
### Added
- Link to the plugin settings screen from the plugin list table ([#98](https://github.com/WordPress/ai/pull/98)).
- WordPress Playground live preview integration ([#85](https://github.com/WordPress/ai/pull/85)).
- RTL language support and inlining for performance ([#113](https://github.com/WordPress/ai/pull/113)).

### Changed
- Updated namespace to `ai_experiments` ([#111](https://github.com/WordPress/ai/pull/111)).
- Bumped WP AI Client from `dev-trunk` to 0.2.0 ([#118](https://github.com/WordPress/ai/pull/118), [#122](https://github.com/WordPress/ai/pull/122), [#125](https://github.com/WordPress/ai/pull/125)).

### Removed
- Valid AI credentials check from the Experiment `is_enabled` check ([#120](https://github.com/WordPress/ai/pull/120)).
- Example Experiment registration ([#121](https://github.com/WordPress/ai/pull/121)).

### Fixed
- Bug in asset loader causing missing dependencies ([#113](https://github.com/WordPress/ai/pull/113)).

### Developer
- Bumped `js-yaml` from 3.14.1 to 3.14.2 ([#105](https://github.com/WordPress/ai/pull/105)).
- Updated format script to only format JS to avoid random JSON file changes ([#114](https://github.com/WordPress/ai/pull/114)).
- Updated documentation ([#108](https://github.com/WordPress/ai/pull/108), [#112](https://github.com/WordPress/ai/pull/112)).

## [0.1.0] - 2025-11-26
First public release of the AI Experiments plugin, introducing a framework for exploring experimental AI-powered features in WordPress. 🎉

### Added
- Experiment registry and loader system for managing AI features
- Abstract experiment base class for consistent feature development
- Experiment: Title Generation
- Basic admin settings screen with toggle support
- Initial integration with WP AI Client SDK and Abilities API
- Utilities Ability for common AI tasks and testing

[Unreleased]: https://github.com/WordPress/ai/compare/trunk...develop
[1.1.0]: https://github.com/WordPress/ai/compare/1.0.2...1.1.0
[1.0.2]: https://github.com/WordPress/ai/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/WordPress/ai/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/WordPress/ai/compare/0.9.0...1.0.0
[0.9.0]: https://github.com/WordPress/ai/compare/0.8.0...0.9.0
[0.8.0]: https://github.com/WordPress/ai/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/WordPress/ai/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/WordPress/ai/compare/0.5.0...0.6.0
[0.5.0]: https://github.com/WordPress/ai/compare/0.4.1...0.5.0
[0.4.1]: https://github.com/WordPress/ai/compare/0.4.0...0.4.1
[0.4.0]: https://github.com/WordPress/ai/compare/0.3.1...0.4.0
[0.3.1]: https://github.com/WordPress/ai/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/WordPress/ai/compare/0.2.1...0.3.0
[0.2.1]: https://github.com/WordPress/ai/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/WordPress/ai/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/WordPress/ai/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/WordPress/ai/tree/0.1.0
