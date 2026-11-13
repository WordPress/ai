<?php
/**
 * The curated map of core admin screens behind the `ai/find-admin-screens` ability.
 *
 * Each entry names a screen by its menu title, the `admin_url()` path that opens
 * it, the words a person might use when they mean it, and the capability core
 * requires to open it. The order breaks ties, so the more common screen of two
 * equally good matches comes first.
 *
 * The block-theme Site Editor and the classic Customizer are mutually exclusive
 * here, matching what core shows in the Appearance menu.
 *
 * @package WordPress\AI\Abilities\Site
 *
 * @since x.x.x
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wpai_settings   = __( 'Settings', 'ai' );
$wpai_appearance = __( 'Appearance', 'ai' );
$wpai_tools      = __( 'Tools', 'ai' );

$wpai_screens = array(
	array(
		'title'      => __( 'Dashboard', 'ai' ),
		'path'       => 'index.php',
		'keywords'   => array( 'home', 'overview', 'at a glance', 'activity', 'welcome' ),
		'capability' => 'read',
	),
	array(
		'title'      => __( 'Updates', 'ai' ),
		'section'    => __( 'Dashboard', 'ai' ),
		'path'       => 'update-core.php',
		'keywords'   => array( 'update', 'upgrade', 'new version', 'wordpress version', 'auto updates' ),
		'capability' => 'update_core',
	),
	array(
		'title'      => __( 'Posts', 'ai' ),
		'path'       => 'edit.php',
		'keywords'   => array( 'all posts', 'blog posts', 'articles', 'drafts', 'published', 'scheduled', 'edit post', 'delete post', 'trash' ),
		'capability' => 'edit_posts',
	),
	array(
		'title'      => __( 'Add New Post', 'ai' ),
		'section'    => __( 'Posts', 'ai' ),
		'path'       => 'post-new.php',
		'keywords'   => array( 'new post', 'write', 'create post', 'blog post', 'article', 'publish' ),
		'capability' => 'edit_posts',
	),
	array(
		'title'      => __( 'Categories', 'ai' ),
		'section'    => __( 'Posts', 'ai' ),
		'path'       => 'edit-tags.php?taxonomy=category',
		'keywords'   => array( 'category', 'taxonomy', 'organize posts' ),
		'capability' => 'manage_categories',
	),
	array(
		'title'      => __( 'Tags', 'ai' ),
		'section'    => __( 'Posts', 'ai' ),
		'path'       => 'edit-tags.php?taxonomy=post_tag',
		'keywords'   => array( 'tag', 'taxonomy', 'post tags' ),
		'capability' => 'manage_categories',
	),
	array(
		'title'      => __( 'Media Library', 'ai' ),
		'section'    => __( 'Media', 'ai' ),
		'path'       => 'upload.php',
		'keywords'   => array( 'media', 'images', 'photos', 'files', 'uploads', 'video', 'audio', 'pdf', 'attachments' ),
		'capability' => 'upload_files',
	),
	array(
		'title'      => __( 'Pages', 'ai' ),
		'path'       => 'edit.php?post_type=page',
		'keywords'   => array( 'all pages', 'static pages', 'about page', 'contact page', 'edit page' ),
		'capability' => 'edit_pages',
	),
	array(
		'title'      => __( 'Add New Page', 'ai' ),
		'section'    => __( 'Pages', 'ai' ),
		'path'       => 'post-new.php?post_type=page',
		'keywords'   => array( 'new page', 'create page' ),
		'capability' => 'edit_pages',
	),
	array(
		'title'      => __( 'Comments', 'ai' ),
		'path'       => 'edit-comments.php',
		'keywords'   => array( 'approve', 'reply', 'pending', 'moderate', 'delete comment' ),
		'capability' => 'edit_posts',
	),
	array(
		'title'      => __( 'Themes', 'ai' ),
		'section'    => $wpai_appearance,
		'path'       => 'themes.php',
		'keywords'   => array( 'theme', 'appearance', 'look', 'design', 'switch theme', 'install theme', 'activate theme' ),
		'capability' => 'switch_themes',
	),
);

if ( wp_is_block_theme() ) {
	$wpai_screens[] = array(
		'title'      => __( 'Editor', 'ai' ),
		'section'    => $wpai_appearance,
		'path'       => 'site-editor.php',
		'keywords'   => array( 'site editor', 'full site editing', 'templates', 'template parts', 'header', 'footer', 'navigation', 'menus', 'styles', 'colors', 'fonts', 'typography', 'layout', 'patterns', 'design' ),
		'capability' => 'edit_theme_options',
	);
} else {
	$wpai_screens[] = array(
		'title'      => __( 'Customize', 'ai' ),
		'section'    => $wpai_appearance,
		'path'       => 'customize.php',
		'keywords'   => array( 'customizer', 'header', 'footer', 'colors', 'background', 'logo', 'site icon', 'favicon', 'menus', 'widgets', 'additional css', 'design' ),
		'capability' => 'customize',
	);
}

$wpai_screens = array_merge(
	$wpai_screens,
	array(
		array(
			'title'      => __( 'Plugins', 'ai' ),
			'path'       => 'plugins.php',
			'keywords'   => array( 'plugin', 'extensions', 'addons', 'activate', 'deactivate', 'installed plugins' ),
			'capability' => 'activate_plugins',
		),
		array(
			'title'      => __( 'Add New Plugin', 'ai' ),
			'section'    => __( 'Plugins', 'ai' ),
			'path'       => 'plugin-install.php',
			'keywords'   => array( 'install plugin', 'new plugin', 'plugin directory', 'upload plugin' ),
			'capability' => 'install_plugins',
		),
		array(
			'title'      => __( 'Users', 'ai' ),
			'path'       => 'users.php',
			'keywords'   => array( 'all users', 'accounts', 'members', 'authors', 'editors', 'roles', 'delete user', 'people' ),
			'capability' => 'list_users',
		),
		array(
			'title'      => __( 'Add New User', 'ai' ),
			'section'    => __( 'Users', 'ai' ),
			'path'       => 'user-new.php',
			'keywords'   => array( 'new user', 'invite', 'create account', 'add author' ),
			'capability' => 'create_users',
		),
		array(
			'title'      => __( 'Profile', 'ai' ),
			'section'    => __( 'Users', 'ai' ),
			'path'       => 'profile.php',
			'keywords'   => array( 'my profile', 'my account', 'password', 'email address', 'display name', 'nickname', 'bio', 'application passwords', 'admin color scheme', 'toolbar' ),
			'capability' => 'read',
		),
		array(
			'title'      => __( 'Site Health', 'ai' ),
			'section'    => $wpai_tools,
			'path'       => 'site-health.php',
			'keywords'   => array( 'health check', 'status', 'diagnostics', 'debug', 'performance', 'security', 'php version', 'server info', 'problems' ),
			'capability' => 'view_site_health_checks',
		),
		array(
			'title'      => __( 'Export', 'ai' ),
			'section'    => $wpai_tools,
			'path'       => 'export.php',
			'keywords'   => array( 'backup content', 'download content', 'wxr', 'xml', 'migrate' ),
			'capability' => 'export',
		),
		array(
			'title'      => __( 'Import', 'ai' ),
			'section'    => $wpai_tools,
			'path'       => 'import.php',
			'keywords'   => array( 'importer', 'upload content', 'wxr', 'migrate', 'move from blogger', 'rss import' ),
			'capability' => 'import',
		),
		array(
			'title'      => __( 'Export Personal Data', 'ai' ),
			'section'    => $wpai_tools,
			'path'       => 'export-personal-data.php',
			'keywords'   => array( 'gdpr', 'privacy request', 'data request', 'personal data', 'user data' ),
			'capability' => 'export_others_personal_data',
		),
		array(
			'title'      => __( 'Erase Personal Data', 'ai' ),
			'section'    => $wpai_tools,
			'path'       => 'erase-personal-data.php',
			'keywords'   => array( 'gdpr', 'privacy request', 'delete personal data', 'right to be forgotten', 'erasure', 'user data' ),
			'capability' => 'erase_others_personal_data',
		),
		array(
			'title'      => __( 'General', 'ai' ),
			'section'    => $wpai_settings,
			'path'       => 'options-general.php',
			'keywords'   => array( 'site title', 'tagline', 'site name', 'timezone', 'time zone', 'language', 'locale', 'date format', 'time format', 'week starts on', 'admin email', 'site address', 'url', 'membership', 'registration', 'new user default role' ),
			'capability' => 'manage_options',
		),
		array(
			'title'      => __( 'Writing', 'ai' ),
			'section'    => $wpai_settings,
			'path'       => 'options-writing.php',
			'keywords'   => array( 'default post category', 'default post format', 'post via email', 'update services', 'ping', 'editor' ),
			'capability' => 'manage_options',
		),
		array(
			'title'      => __( 'Reading', 'ai' ),
			'section'    => $wpai_settings,
			'path'       => 'options-reading.php',
			'keywords'   => array( 'search engine visibility', 'discourage search engines', 'google', 'bing', 'indexing', 'noindex', 'hide', 'seo', 'homepage', 'home page', 'front page', 'posts page', 'blog page', 'posts per page', 'blog pages show at most', 'feed', 'rss', 'syndication' ),
			'capability' => 'manage_options',
		),
		array(
			'title'      => __( 'Discussion', 'ai' ),
			'section'    => $wpai_settings,
			'path'       => 'options-discussion.php',
			'keywords'   => array( 'comments', 'spam', 'stop', 'block', 'prevent', 'moderation', 'comment approval', 'blocklist', 'disallowed keys', 'pingbacks', 'trackbacks', 'avatars', 'gravatar', 'close comments', 'disable comments', 'notifications' ),
			'capability' => 'manage_options',
		),
		array(
			'title'      => __( 'Media', 'ai' ),
			'section'    => $wpai_settings,
			'path'       => 'options-media.php',
			'keywords'   => array( 'image sizes', 'thumbnail size', 'medium size', 'large size', 'crop', 'uploads folder', 'organize uploads' ),
			'capability' => 'manage_options',
		),
		array(
			'title'      => __( 'Permalinks', 'ai' ),
			'section'    => $wpai_settings,
			'path'       => 'options-permalink.php',
			'keywords'   => array( 'url structure', 'pretty urls', 'slugs', 'links', 'category base', 'tag base', '404' ),
			'capability' => 'manage_options',
		),
		array(
			'title'      => __( 'Privacy', 'ai' ),
			'section'    => $wpai_settings,
			'path'       => 'options-privacy.php',
			'keywords'   => array( 'privacy policy', 'policy page', 'gdpr', 'cookies' ),
			'capability' => 'manage_privacy_options',
		),
	)
);

return $wpai_screens;
