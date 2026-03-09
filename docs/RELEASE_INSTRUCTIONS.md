
# Release Instructions

The following can be copied into a [new, blank GitHub issue](https://github.com/WordPress/ai/issues) who's title is formatted as `Release version X.Y.Z`.  Once the issue is submitted, the checklist in the body of the issue should be followed to release a new version of the WordPress AI plugin.  All references to `X.Y.Z` below should be updated to the actual release version number.

```
This issue is for tracking changes for the X.Y.Z release.  Target release date: **DD Month YYYY.**

## Pre-release steps

- [ ] Review and merge #.

## [Release steps](https://github.com/WordPress/ai/blob/develop/docs/RELEASE_INSTRUCTIONS.md)

- [ ] Branch: Starting from `develop`, cut a release branch named `release/X.Y.Z` for your changes.
- [ ] Version bump: Bump the version number in `ai.php`, `package-lock.json`, and `readme.txt` if it does not already reflect the version being released.  In `includes/bootstrap.php`, ensure you're updating the `AI_EXPERIMENTS_VERSION` version constant.
- [ ] Update `@since`: Find all new `@since x.x.x` lines and update those with the new version number in place of `x.x.x`.
- [ ] Changelog: Add/update the changelog in `CHANGELOG.md` and in `readme.txt`.
- [ ] Props: update `CREDITS.md` file with any new contributors, confirm maintainers are accurate.
- [ ] Readme updates: Make any other readme changes as necessary in `README.md` and `readme.txt`.
- [ ] New files: Check to be sure any new files/paths that are unnecessary in the production version are included in `.gitattributes`.
- [ ] Merge release PR: Make a non-fast-forward merge from your release branch to `develop` (or merge the pull request).
- [ ] Merge develop to trunk: Do the same for `develop` into `trunk` (`git checkout trunk && git merge --no-ff develop`).  `trunk` now contains the stable development version.
- [ ] Push: Push your trunk branch to GitHub (e.g. `git push origin trunk`).
- [ ] Compare: Check that no results show in [comparing a merge from develop into trunk](https://github.com/WordPress/ai/compare/trunk...develop) as that means the prior steps completed successfully.
- [ ] [Wait for build](https://xkcd.com/303/): Head to the [Actions](https://github.com/WordPress/ai/actions) tab in the repo and wait for it to finish if it hasn't already.  If it doesn't succeed, figure out why and start over.
- [ ] Check the build: Check out the `trunk` branch and test for functionality locally.
- [ ] Test: Check the [end-to-end tests](https://github.com/WordPress/ai/actions/workflows/test.yml) are passing.  Only proceed if everything tests successfully.
- [ ] Release: Create a [new release](https://github.com/WordPress/ai/releases/new), naming the tag and the release with the new version number, and targeting the `trunk` branch.  Paste the changelog for the release from [`CHANGELOG.md`](https://github.com/WordPress/ai/blob/develop/CHANGELOG.md) into the body of the release and include a link to `[View all items closed in the milestone](https://github.com/WordPress/ai/milestone/#?closed=1)`.  The release should now appear under [releases](https://github.com/WordPress/ai/releases).

## Post-release steps

- [ ] Close milestone: Edit the [milestone](https://github.com/WordPress/ai/milestone/#) with release date (in the `Due date (optional)` field) and link to GitHub release (in the `Description field`), then close the milestone.
- [ ] Punt incomplete items: If any open issues or PRs which were milestoned for `X.Y.Z` do not make it into the release, update their milestone to `X.Y.Z+1`, `X.Y+1.0`, `X+1.0.0` or `Future Release`.
- [ ] Announce: Publish release announcement post on Make/AI, cross-posting to Make/Core and Make/Test ([example](https://make.wordpress.org/ai/2025/11/27/announcing-the-ai-experiments-plugin-v0-1-0/)).
- [ ] Profile badges: Grant new contributors the `Core AI Contributor` [profile badge](https://make.wordpress.org/ai/wp-admin/tools.php?page=profile-badges).
```
