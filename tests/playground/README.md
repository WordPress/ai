# Playground sample content

The WordPress.org Live Preview and the Playground button on pull requests both start with a site full of content, so every AI feature has something to work on. The content is the book _Milestones: The Story of WordPress_ (WordPress Library, Vol. 1), the same text as [wordpress.org/book](https://wordpress.org/book/).

## What gets imported

| Content                                         | Count    | Notes                                                                                                                                     |
| ----------------------------------------------- | -------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Front page "Milestones: The Story of WordPress" | 1 page   | Cover, contents with the book's chapter numbers 1–44, and an "About this content" credit                                                  |
| Part pages (children of the front page)         | 6 pages  | Part illustration, haiku, chapter list, and the part's timeline from the book's endmatter                                                 |
| Chapter posts                                   | 45 posts | The Introduction and chapters 1–44, one day apart from 13 November 2015 (when the book went up on wordpress.org/book) to 27 December 2015 |
| Categories                                      | 6        | Part 1 to Part 6, described by each part's era                                                                                            |
| Images                                          | 61       | Resized to at most 1200 px wide as WebP; animated GIFs kept as they are. Image captions from the book become block captions.              |
| Footnotes                                       | 20       | Core Footnotes block                                                                                                                      |
| Comments                                        | 12       | Made up, from Wapuu: 9 approved (one threaded reply), 3 pending for Comment Moderation                                                    |

Excerpts, tags and some alt text are left empty on purpose, so features such as Excerpt Generation, Content Classification and Alt Text Generation have work to do.

## How the previews load it

- `.wordpress-org/blueprints/blueprint.json` (Live Preview) installs the plugin, deletes the default "Hello world!" post and "Sample Page", imports `milestones/milestones.xml` from `trunk` with the `importWxr` step, then sets the front page, site title and permalinks. During the import a temporary must-use plugin limits the generated image sizes to thumbnails, which halves the import time in the browser (about 30 s to 15 s); the last step removes it, so images uploaded later get every size.
- `.github/workflows/pr-playground-preview.yml` reuses those steps for the PR's Playground button. It loads the WXR file from the PR's own commit and maps the image URLs there with `urlMapping`, so a PR that changes this content previews its own version.

The WXR file also works with `wp import` or Tools > Import on any test site.

## Rebuilding

The files in `milestones/` are generated; don't edit them by hand.

```sh
git clone https://github.com/WordPress/library.git ../library
npm run build:test-content -- ../library
```

The script (`build.mjs`) reads the library's `manifest.json` for chapter titles and slugs, converts each chapter's Markdown to blocks, resizes the images and writes `milestones/milestones.xml`. The library commit it was built from is recorded at the top of the WXR file. The made-up comments and the list of left-out images are in the script.

## License

_Milestones: The Story of WordPress_ is written by WordPress contributors and dual-licensed under the [GNU GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) and [Creative Commons Attribution-ShareAlike 4.0](https://creativecommons.org/licenses/by-sa/4.0/). Source: [github.com/WordPress/library](https://github.com/WordPress/library).

The content here is modified from the original: chapters are split into posts and pages, the text is converted to blocks, images are resized, some photographs are left out and the comments are made up. The front page of the imported site says the same.

Left out because they are third-party photographs with their own licenses (some non-commercial), or show people without a credit:

- All photographs in the Introduction
- The two WordCamp 2006 photos by Scott Beale (Laughing Squid)
- The Ohio LinuxFest 2007 photo by Sean T. Evans in "Habari"
- The PHP version chart by Nexen Services in "Habari"
- The photo of the first core team meetup in "Meeting in Person"
