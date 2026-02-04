<?php
/**
 * Service Account Admin UI.
 *
 * Handles admin interface styling and integration for service accounts.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Service_Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI handler for service accounts.
 *
 * Manages visual differentiation, user list integration, and admin styling
 * for service accounts in the WordPress admin.
 *
 * @since 0.3.0
 */
class Admin_UI {
	/**
	 * The service account manager instance.
	 *
	 * @since 0.3.0
	 * @var Service_Account_Manager
	 */
	protected Service_Account_Manager $manager;

	/**
	 * Cached generated username for the current request.
	 *
	 * @since 0.3.0
	 * @var string|null
	 */
	private ?string $generated_username = null;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 */
	public function __construct() {
		$this->manager = Service_Account_Manager::get_instance();
	}

	/**
	 * Initializes the admin UI hooks.
	 *
	 * @since 0.3.0
	 */
	public function init(): void {
		// Enqueue admin styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		// Add a JS-ready class to prevent layout flash.
		add_action( 'admin_head', array( $this, 'maybe_add_layout_class' ) );

		// Add body class for service account edit pages.
		add_filter( 'admin_body_class', array( $this, 'add_body_class' ) );

		// Mark service account rows in users list.
		add_filter( 'user_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );

		// Ensure service account fields are populated on creation.
		add_filter( 'pre_user_login', array( $this, 'maybe_generate_user_login' ) );
		add_filter( 'pre_user_email', array( $this, 'maybe_generate_user_email' ) );
		add_action( 'user_register', array( $this, 'handle_user_register' ) );

		// Validate service account fields on save.
		add_action( 'user_profile_update_errors', array( $this, 'validate_service_account_fields' ), 10, 3 );

		// Add service account fields on the user edit screen.
		add_action( 'show_user_profile', array( $this, 'add_service_account_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'add_service_account_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_service_account_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_service_account_fields' ) );

		// Hide password fields for service accounts.
		add_action( 'show_user_profile', array( $this, 'maybe_hide_password_section' ) );
		add_action( 'edit_user_profile', array( $this, 'maybe_hide_password_section' ) );
	}

	/**
	 * Enqueues admin styles for service account differentiation.
	 *
	 * @since 0.3.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_styles( string $hook_suffix ): void {
		// Only load on user-related pages.
		$allowed_hooks = array( 'users.php', 'user-edit.php', 'profile.php', 'user-new.php' );

		if ( ! in_array( $hook_suffix, $allowed_hooks, true ) ) {
			return;
		}

		$css = $this->get_styles();

		wp_register_style( 'service-account-admin', false, array(), '0.3.0' );
		wp_enqueue_style( 'service-account-admin' );
		wp_add_inline_style( 'service-account-admin', $css );

		// Add JavaScript for row marking and new user form.
		$this->enqueue_scripts( $hook_suffix );
	}

	/**
	 * Enqueues admin scripts.
	 *
	 * @since 0.3.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	protected function enqueue_scripts( string $hook_suffix ): void {
		if ( 'users.php' === $hook_suffix ) {
			$this->enqueue_users_list_scripts();
		}

		if ( 'user-new.php' === $hook_suffix ) {
			$this->enqueue_new_user_scripts();
		}

		if ( in_array( $hook_suffix, array( 'user-edit.php', 'profile.php' ), true ) ) {
			$this->enqueue_user_edit_scripts();
		}
	}

	/**
	 * Enqueues scripts for the users list page.
	 *
	 * @since 0.3.0
	 */
	protected function enqueue_users_list_scripts(): void {
		// Get service account IDs for row marking.
		// Note: 'fields' => 'ID' returns an array of IDs directly, not user objects.
		$service_account_ids = $this->manager->get_service_accounts( array( 'fields' => 'ID' ) );

		if ( empty( $service_account_ids ) ) {
			return;
		}

		$js = sprintf(
			'document.addEventListener("DOMContentLoaded", function() {
				var serviceAccountIds = %s;
				serviceAccountIds.forEach(function(id) {
					var row = document.querySelector("tr#user-" + id);
					if (row) {
						row.classList.add("service-account-row");
					}
				});
			});',
			wp_json_encode( array_map( 'intval', $service_account_ids ) )
		);

		wp_add_inline_script( 'jquery', $js );
	}

	/**
	 * Enqueues scripts for the new user page.
	 *
	 * Adds JavaScript to conditionally show/hide form fields when the
	 * Service role is selected.
	 *
	 * @since 0.3.0
	 */
	protected function enqueue_new_user_scripts(): void {
		wp_enqueue_script( 'wp-i18n' );

		$service_role = Service_Account_Manager::ROLE;
		$site_domain  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
		$site_domain  = wp_json_encode( $site_domain );

		$js = <<<JS
document.addEventListener("DOMContentLoaded", function() {
	var roleSelect = document.getElementById("role");
	if (!roleSelect) return;

	var serviceRole = "{$service_role}";
	var siteDomain = {$site_domain};

	// Fields to hide for service accounts.
	var fieldIdsToHide = ["first_name", "last_name", "url"];

	// Create a service account name field row.
	var serviceAccountRow = document.createElement("tr");
	serviceAccountRow.className = "form-field form-required service-account-name-row";
	serviceAccountRow.style.display = "none";
	serviceAccountRow.innerHTML = '<th scope="row"><label for="service_account_name">' + wp.i18n.__("Service account name", "ai") + ' <span class="description">(' + wp.i18n.__("required", "ai") + ')</span></label></th>' +
		'<td><input type="text" name="service_account_name" id="service_account_name" class="regular-text" placeholder="' + wp.i18n.__("e.g., Claude Code, Deployment Bot", "ai") + '">' +
		'<p class="description">' + wp.i18n.__("Used to generate the username and email. Create Application Passwords after saving the account.", "ai") + '</p></td>';

	// Insert the field row at the top of the table.
	var formTableBody = document.querySelector("#createuser table.form-table tbody");
	if (formTableBody) {
		formTableBody.insertBefore(serviceAccountRow, formTableBody.firstChild);
	}

	var nameInput = document.getElementById("service_account_name");

	// Store original field state.
	var userLoginField = document.getElementById("user_login");
	var userLoginRow = userLoginField ? userLoginField.closest(".form-field") : null;
	var userLoginDescription = null;
	var originalLoginValue = userLoginField ? userLoginField.value : "";

	var emailField = document.getElementById("email");
	var emailRow = emailField ? emailField.closest(".form-field") : null;
	var emailDescription = null;
	var originalEmailValue = emailField ? emailField.value : "";

	var sendNotificationField = document.getElementById("send_user_notification");
	var sendNotificationRow = sendNotificationField ? sendNotificationField.closest(".form-field") : null;
	var originalNotificationChecked = sendNotificationField ? sendNotificationField.checked : false;

	if (userLoginRow && userLoginField) {
		userLoginDescription = document.createElement("p");
		userLoginDescription.className = "description service-account-username-desc";
		userLoginDescription.textContent = wp.i18n.__("Auto-generated from the service account name.", "ai");
		userLoginDescription.style.display = "none";
		userLoginField.parentNode.insertBefore(userLoginDescription, userLoginField.nextSibling);
	}

	if (emailRow) {
		// Add a description for service accounts.
		emailDescription = document.createElement("p");
		emailDescription.className = "description service-account-email-desc";
		emailDescription.textContent = wp.i18n.__("Auto-generated based on the service account name and site domain.", "ai");
		emailDescription.style.display = "none";
		emailField.parentNode.insertBefore(emailDescription, emailField.nextSibling);
	}

	var generatedSuffix = null;

	function getSuffix() {
		if (generatedSuffix) {
			return generatedSuffix;
		}

		if (window.crypto && window.crypto.getRandomValues) {
			var bytes = new Uint8Array(4);
			window.crypto.getRandomValues(bytes);
			generatedSuffix = Array.from(bytes).map(function(byte) {
				return byte.toString(16).padStart(2, "0");
			}).join("");
		} else {
			generatedSuffix = Math.random().toString(16).slice(2, 10).padEnd(8, "0");
		}

		return generatedSuffix;
	}

	function slugify(value) {
		return value
			.toLowerCase()
			.trim()
			.replace(/['"]/g, "")
			.replace(/[^a-z0-9]+/g, "-")
			.replace(/^-+|-+$/g, "");
	}

	function generateUsername(nameValue) {
		var slug = slugify(nameValue);
		if (!slug) {
			slug = "service-account";
		}
		var suffix = getSuffix();
		var username = "service-" + slug + "-" + suffix;
		if (username.length > 60) {
			username = username.slice(0, 50) + "-" + suffix;
		}
		return username;
	}

	function applyGeneratedValues() {
		if (!nameInput) {
			return;
		}

		var nameValue = nameInput.value.trim();
		if (!nameValue) {
			if (userLoginField) {
				userLoginField.value = "";
			}
			if (emailField) {
				emailField.value = "";
			}
			return;
		}

		var username = generateUsername(nameValue);
		if (userLoginField) {
			userLoginField.value = username;
		}
		if (emailField) {
			emailField.value = username + "@" + siteDomain;
		}
	}

	function toggleServiceAccountFields() {
		var isService = roleSelect.value === serviceRole;

		// Toggle visibility of standard fields.
		fieldIdsToHide.forEach(function(id) {
			var field = document.getElementById(id);
			var row = field ? field.closest("tr") : null;
			if (row) {
				row.style.display = isService ? "none" : "";
			}
		});

		// Toggle the service account name field.
		serviceAccountRow.style.display = isService ? "table-row" : "none";

		// Handle username field - show but mark as auto-generated.
		if (userLoginField && userLoginRow) {
			userLoginField.readOnly = isService;
			userLoginField.style.backgroundColor = isService ? "#f0f0f1" : "";
			if (userLoginDescription) {
				userLoginDescription.style.display = isService ? "block" : "none";
			}
			if (!isService) {
				userLoginField.value = originalLoginValue;
			}
		}

		// Handle email field - show but mark as auto-generated.
		if (emailField && emailRow) {
			emailField.readOnly = isService;
			emailField.style.backgroundColor = isService ? "#f0f0f1" : "";
			if (emailDescription) {
				emailDescription.style.display = isService ? "block" : "none";
			}
			if (!isService) {
				emailField.value = originalEmailValue;
				emailField.placeholder = "";
			}
		}

		// Hide and disable user notifications for service accounts.
		if (sendNotificationRow) {
			sendNotificationRow.style.display = isService ? "none" : "";
		}
		if (sendNotificationField) {
			sendNotificationField.disabled = isService;
			sendNotificationField.checked = isService ? false : originalNotificationChecked;
		}

		// If service is selected, make the name field required.
		if (nameInput) {
			nameInput.required = isService;
		}

		if (isService) {
			applyGeneratedValues();
		} else {
			generatedSuffix = null;
		}
	}

	if (nameInput) {
		nameInput.addEventListener("input", function() {
			if (roleSelect.value === serviceRole) {
				applyGeneratedValues();
			}
		});
	}

	// Initial check.
	toggleServiceAccountFields();

	// Listen for role changes.
	roleSelect.addEventListener("change", toggleServiceAccountFields);
});
JS;

		wp_add_inline_script( 'wp-i18n', $js );
	}

	/**
	 * Enqueues scripts for the user edit pages.
	 *
	 * @since 0.3.0
	 */
	protected function enqueue_user_edit_scripts(): void {
		wp_enqueue_script( 'wp-i18n' );
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_style( 'wp-components' );

		$js = <<<JS
document.addEventListener("DOMContentLoaded", function() {
	if (!document.body.classList.contains("is-service-account-edit")) {
		return;
	}

	var profileForm = document.getElementById("your-profile");
	var detailsSection = document.getElementById("service-account-details-section");
	if (profileForm && detailsSection) {
		var firstHeading = profileForm.querySelector("h2");
		if (firstHeading) {
			profileForm.insertBefore(detailsSection, firstHeading);
		} else {
			profileForm.insertBefore(detailsSection, profileForm.firstChild);
		}
	}

	var heading = document.querySelector(".wrap h1.wp-heading-inline");
	if (heading && detailsSection) {
		var displayName = detailsSection.dataset.serviceAccountName || "";
		var title = displayName
			? wp.i18n.sprintf(wp.i18n.__("Edit Service: %s", "ai"), displayName)
			: wp.i18n.__("Edit Service", "ai");
		heading.textContent = title;
	}

	var rowsToHide = [
		".user-rich-editing-wrap",
		".user-comment-shortcuts-wrap",
		".user-admin-bar-front-wrap",
		".user-language-wrap",
		".user-profile-picture",
		".user-syntax-highlighting-wrap",
		".user-admin-color-wrap"
	];

	rowsToHide.forEach(function(selector) {
		var row = document.querySelector(selector);
		if (row) {
			row.style.display = "none";
		}
	});

	function hideRowById(id) {
		var field = document.getElementById(id);
		var row = field ? field.closest("tr") : null;
		if (row) {
			row.style.display = "none";
		}
	}

	hideRowById("first_name");
	hideRowById("last_name");
	hideRowById("nickname");
	hideRowById("display_name");
	hideRowById("url");

	var detailsTable = document.getElementById("service-account-details");
	var ownerRow = document.querySelector(".service-account-owner-row");

	function moveRowBeforeOwner(fieldId) {
		if (!detailsTable || !ownerRow) {
			return;
		}
		var field = document.getElementById(fieldId);
		var row = field ? field.closest("tr") : null;
		if (row) {
			ownerRow.parentNode.insertBefore(row, ownerRow);
		}
	}

	moveRowBeforeOwner("user_login");
	moveRowBeforeOwner("email");
	moveRowBeforeOwner("role");
	moveRowBeforeOwner("description");

	var roleRow = document.getElementById("role");
	if (roleRow) {
		var roleRowWrapper = roleRow.closest("tr");
		var roleLabel = roleRowWrapper ? roleRowWrapper.querySelector("label") : null;
		var roleCell = roleRowWrapper ? roleRowWrapper.querySelector("td") : null;
		if (roleLabel) {
			roleLabel.textContent = wp.i18n.__("Role (baseline capabilities)", "ai");
		}
		if (roleCell && !roleCell.querySelector(".service-account-role-desc")) {
			var roleDesc = document.createElement("p");
			roleDesc.className = "description service-account-role-desc";
			roleDesc.textContent = wp.i18n.__("Use the role to set default permissions. High-risk admin capabilities remain restricted for service accounts.", "ai");
			roleCell.appendChild(roleDesc);
		}
	}

	var descriptionField = document.getElementById("description");
	if (descriptionField) {
		var descriptionRow = descriptionField.closest("tr");
		var descriptionLabel = descriptionRow ? descriptionRow.querySelector("label") : null;
		if (descriptionLabel) {
			descriptionLabel.textContent = wp.i18n.__("Purpose", "ai");
		}
		var descriptionHelp = document.getElementById("description-description");
		if (descriptionHelp) {
			descriptionHelp.textContent = wp.i18n.__("Describe what this service account is for and which system owns it. This may appear in logs and audits.", "ai");
		}
	}

	var nameInput = document.getElementById("service_account_name");
	if (nameInput && heading) {
		nameInput.addEventListener("input", function() {
			var value = nameInput.value.trim();
			var title = value
				? wp.i18n.sprintf(wp.i18n.__("Edit Service: %s", "ai"), value)
				: wp.i18n.__("Edit Service", "ai");
			heading.textContent = title;
		});
	}

	var appSection = document.querySelector(".application-passwords");
	if (appSection && !appSection.querySelector(".service-account-app-note")) {
		var appNote = document.createElement("p");
		appNote.className = "description service-account-app-note";
		appNote.textContent = wp.i18n.__("Create Application Passwords for separate systems and rotate them independently.", "ai");
		appSection.insertBefore(appNote, appSection.firstChild);
	}

	function getSectionNodes(heading) {
		var nodes = [];
		var next = heading.nextElementSibling;
		while (next && next.tagName !== "H2") {
			nodes.push(next);
			next = next.nextElementSibling;
		}
		return nodes;
	}

	function sectionHasVisibleContent(nodes) {
		return nodes.some(function(node) {
			if (node.classList && node.classList.contains("submit")) {
				return false;
			}

			if (node.tagName === "TABLE" && node.classList.contains("form-table")) {
				var rows = Array.from(node.querySelectorAll("tr"));
				return rows.some(function(row) {
					return row.offsetParent !== null && row.style.display !== "none";
				});
			}

			return node.offsetParent !== null && node.style.display !== "none";
		});
	}

	function hideSection(heading, nodes) {
		heading.style.display = "none";
		nodes.forEach(function(node) {
			if (node.classList && node.classList.contains("submit")) {
				return;
			}
			node.style.display = "none";
		});
	}

	var headings = document.querySelectorAll("#your-profile h2");
	headings.forEach(function(heading) {
		var nodes = getSectionNodes(heading);
		if (!sectionHasVisibleContent(nodes)) {
			hideSection(heading, nodes);
		}
	});
});
JS;

		wp_add_inline_script( 'wp-i18n', $js );

		$owner_js = <<<JS
document.addEventListener("DOMContentLoaded", function() {
	if (!document.body.classList.contains("is-service-account-edit")) {
		return;
	}

	if (!window.wp || !wp.element || !wp.components || !wp.apiFetch) {
		return;
	}

	var ownerField = document.getElementById("service_account_owner_id");
	var ownerContainer = document.getElementById("service-account-owner-select");
	if (!ownerField || !ownerContainer || ownerContainer.dataset.selectEnabled !== "1") {
		return;
	}

	var ownerLabel = ownerContainer.dataset.ownerLabel || "";
	var ownerId = ownerField.value ? String(ownerField.value) : "";

	var createElement = wp.element.createElement;
	var render = wp.element.render;
	var useState = wp.element.useState;
	var ComboboxControl = wp.components.ComboboxControl;
	var Spinner = wp.components.Spinner;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;

	function OwnerSelect() {
		var initialOption = ownerId && ownerLabel ? { value: ownerId, label: ownerLabel } : null;
		var initialOptions = initialOption ? [initialOption] : [];

		var _useState = useState(initialOptions);
		var options = _useState[0];
		var setOptions = _useState[1];

		var _useState2 = useState(ownerId);
		var value = _useState2[0];
		var setValue = _useState2[1];

		var _useState3 = useState(false);
		var isLoading = _useState3[0];
		var setIsLoading = _useState3[1];

		var _useState4 = useState(null);
		var debounceTimer = _useState4[0];
		var setDebounceTimer = _useState4[1];

		function setOwner(newValue, option) {
			if (!newValue || !option) {
				setValue("");
				ownerField.value = "";
				return;
			}
			setValue(newValue);
			ownerField.value = newValue;
			if (option && !options.find(function(item) { return item.value === option.value; })) {
				setOptions([option].concat(options));
			}
		}

		function fetchUsers(search) {
			if (!search || search.length < 2) {
				return;
			}

			setIsLoading(true);
			apiFetch({ path: "/wp/v2/users?search=" + encodeURIComponent(search) + "&per_page=20&context=edit" })
				.then(function(users) {
					var results = users.map(function(user) {
						return {
							value: String(user.id),
							label: user.name + " (" + user.slug + ")"
						};
					});

					if (initialOption) {
						var hasInitial = results.some(function(item) { return item.value === initialOption.value; });
						if (!hasInitial) {
							results.unshift(initialOption);
						}
					}

					setOptions(results);
				})
				.catch(function() {})
				.finally(function() {
					setIsLoading(false);
				});
		}

		function handleFilterChange(inputValue) {
			if (debounceTimer) {
				clearTimeout(debounceTimer);
			}
			var timer = setTimeout(function() {
				fetchUsers(inputValue);
			}, 250);
			setDebounceTimer(timer);
		}

		function handleChange(newValue) {
			if (!newValue) {
				setOwner("", null);
				return;
			}

			var option = options.find(function(item) { return item.value === newValue; });
			if (!option) {
				setOwner("", null);
				return;
			}

			setOwner(newValue, option);
		}

		function clearOwner(event) {
			event.preventDefault();
			setOwner("", null);
		}

		return createElement(
			"div",
			{ className: "service-account-owner-control" },
			createElement(ComboboxControl, {
				id: "service_account_owner_search",
				label: null,
				"aria-label": __("Owner", "ai"),
				value: value,
				options: options,
				onFilterValueChange: handleFilterChange,
				onChange: handleChange,
				__nextHasNoMarginBottom: true
			}),
			isLoading ? createElement(Spinner, null) : null,
			createElement(
				"button",
				{ type: "button", className: "button-link", onClick: clearOwner },
				__("Clear", "ai")
			)
		);
	}

	render(createElement(OwnerSelect), ownerContainer);
});
JS;

		wp_add_inline_script( 'wp-components', $owner_js );
	}

	/**
	 * Adds service account fields to the user profile screen.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function add_service_account_fields( \WP_User $user ): void {
		if ( ! $this->manager->is_service_account( $user ) ) {
			return;
		}

		$can_assign_owner = current_user_can( 'list_users' );
		$owner_id = (int) get_user_meta( $user->ID, 'service_account_owner_id', true );
		$owner    = $owner_id ? get_user_by( 'id', $owner_id ) : null;
		$owner_label = '';
		if ( $owner instanceof \WP_User ) {
			$owner_label = sprintf( '%s (%s)', $owner->display_name, $owner->user_login );
		}
		$system = (string) get_user_meta( $user->ID, 'service_account_system', true );
		$ref    = (string) get_user_meta( $user->ID, 'service_account_reference', true );

		?>
		<div id="service-account-details-section" class="service-account-details-section" data-service-account-name="<?php echo esc_attr( $user->display_name ); ?>">
			<div class="service-account-notice">
				<h3><?php esc_html_e( 'Service Account', 'ai' ); ?></h3>
				<p><?php esc_html_e( 'This account is designed for automated tools and programmatic access.', 'ai' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Assign a role to set baseline capabilities; high-risk administrative capabilities remain restricted for service accounts.', 'ai' ); ?></li>
					<li><?php esc_html_e( 'Create Application Passwords in the section below; you can issue multiple passwords for separate systems.', 'ai' ); ?></li>
					<li><?php esc_html_e( 'Actions performed by this account appear in activity logs, revisions, and audit trails like any other user.', 'ai' ); ?></li>
				</ul>
			</div>
			<h2><?php esc_html_e( 'Service Account Details', 'ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Manage the identity, ownership, and baseline permissions for this service account.', 'ai' ); ?></p>
			<table class="form-table" id="service-account-details" role="presentation">
				<tr class="form-field form-required service-account-name-row">
					<th scope="row">
						<label for="service_account_name"><?php esc_html_e( 'Service account name', 'ai' ); ?> <span class="description">(<?php esc_html_e( 'required', 'ai' ); ?>)</span></label>
					</th>
					<td>
						<input type="text" name="service_account_name" id="service_account_name" class="regular-text" value="<?php echo esc_attr( $user->display_name ); ?>" required>
						<p class="description"><?php esc_html_e( 'Shown in logs, revisions, and activity records.', 'ai' ); ?></p>
					</td>
				</tr>
				<tr class="form-field service-account-owner-row">
					<th scope="row">
						<?php if ( $can_assign_owner ) : ?>
							<label for="service_account_owner_search"><?php esc_html_e( 'Owner', 'ai' ); ?></label>
						<?php else : ?>
							<?php esc_html_e( 'Owner', 'ai' ); ?>
						<?php endif; ?>
					</th>
					<td>
						<input type="hidden" name="service_account_owner_id" id="service_account_owner_id" value="<?php echo esc_attr( (string) $owner_id ); ?>">
						<?php if ( $can_assign_owner ) : ?>
							<div id="service-account-owner-select" data-owner-label="<?php echo esc_attr( $owner_label ); ?>" data-select-enabled="1"></div>
							<p class="description"><?php esc_html_e( 'Optional. Select a WordPress user responsible for this account.', 'ai' ); ?></p>
						<?php else : ?>
							<p>
								<?php
								echo $owner_label
									? esc_html( $owner_label )
									: esc_html__( 'No owner assigned.', 'ai' );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'You do not have permission to assign owners.', 'ai' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="form-field service-account-system-row">
					<th scope="row">
						<label for="service_account_system"><?php esc_html_e( 'System or tool', 'ai' ); ?></label>
					</th>
					<td>
						<input type="text" name="service_account_system" id="service_account_system" class="regular-text" value="<?php echo esc_attr( $system ); ?>" placeholder="<?php esc_attr_e( 'e.g., CI pipeline, automation bot', 'ai' ); ?>">
						<p class="description"><?php esc_html_e( 'The system that uses this account.', 'ai' ); ?></p>
					</td>
				</tr>
				<tr class="form-field service-account-reference-row">
					<th scope="row">
						<label for="service_account_reference"><?php esc_html_e( 'Reference', 'ai' ); ?></label>
					</th>
					<td>
						<input type="text" name="service_account_reference" id="service_account_reference" class="regular-text" value="<?php echo esc_attr( $ref ); ?>" placeholder="<?php esc_attr_e( 'Ticket or tracking ID', 'ai' ); ?>">
						<p class="description"><?php esc_html_e( 'Optional link to internal tracking.', 'ai' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Saves service account fields from the user profile screen.
	 *
	 * @since 0.3.0
	 *
	 * @param int $user_id The user ID.
	 */
	public function save_service_account_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $this->manager->is_service_account( $user ) ) {
			return;
		}

		if ( isset( $_POST['service_account_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$name = sanitize_text_field( wp_unslash( $_POST['service_account_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' !== $name ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $name,
						'nickname'     => $name,
					)
				);
			}
		}

		if ( isset( $_POST['service_account_owner_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( current_user_can( 'list_users' ) ) {
				$owner_id = absint( wp_unslash( $_POST['service_account_owner_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( 0 === $owner_id || ! get_user_by( 'id', $owner_id ) ) {
					delete_user_meta( $user_id, 'service_account_owner_id' );
				} else {
					update_user_meta( $user_id, 'service_account_owner_id', $owner_id );
				}
				delete_user_meta( $user_id, 'service_account_owner' );
			}
		}

		if ( isset( $_POST['service_account_system'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$system = sanitize_text_field( wp_unslash( $_POST['service_account_system'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $system ) {
				delete_user_meta( $user_id, 'service_account_system' );
			} else {
				update_user_meta( $user_id, 'service_account_system', $system );
			}
		}

		if ( isset( $_POST['service_account_reference'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ref = sanitize_text_field( wp_unslash( $_POST['service_account_reference'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $ref ) {
				delete_user_meta( $user_id, 'service_account_reference' );
			} else {
				update_user_meta( $user_id, 'service_account_reference', $ref );
			}
		}
	}

	/**
	 * Validates service account fields before saving.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_Error $errors WP_Error object.
	 * @param bool      $update Whether this is a user update.
	 * @param \stdClass $user   User data object.
	 */
	public function validate_service_account_fields( \WP_Error $errors, bool $update, \stdClass $user ): void {
		if ( ! $this->is_service_account_form_submission() ) {
			if ( $update ) {
				$current_user = get_user_by( 'id', (int) $user->ID );
				if ( ! $current_user || ! $this->manager->is_service_account( $current_user ) ) {
					return;
				}
			} else {
				return;
			}
		}

		$name = $this->get_service_account_name_from_post();
		if ( '' === $name ) {
			$errors->add(
				'service_account_name',
				__( 'Service account name is required.', 'ai' )
			);
		}

		if ( isset( $_POST['service_account_owner_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( current_user_can( 'list_users' ) ) {
				$owner_id = absint( wp_unslash( $_POST['service_account_owner_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( $owner_id && ! get_user_by( 'id', $owner_id ) ) {
					$errors->add(
						'service_account_owner_id',
						__( 'Owner must be a valid WordPress user.', 'ai' )
					);
				}
			}
		}
	}

	/**
	 * Checks whether the current request is a service account form submission.
	 *
	 * @since 0.3.0
	 *
	 * @return bool True when handling a service account submission.
	 */
	private function is_service_account_form_submission(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		if ( empty( $_POST['role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		$role = sanitize_text_field( wp_unslash( $_POST['role'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return Service_Account_Manager::ROLE === $role;
	}

	/**
	 * Gets the service account name from the current request.
	 *
	 * @since 0.3.0
	 *
	 * @return string The sanitized service account name, or an empty string.
	 */
	private function get_service_account_name_from_post(): string {
		if ( empty( $_POST['service_account_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST['service_account_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Generates a username for a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param string $name Service account name.
	 * @return string Generated username.
	 */
	private function generate_username( string $name ): string {
		$sanitized_name = sanitize_title( $name );
		if ( '' === $sanitized_name ) {
			$sanitized_name = 'service-account';
		}

		$short_uuid = substr( wp_generate_uuid4(), 0, 8 );
		$username   = 'service-' . $sanitized_name . '-' . $short_uuid;

		if ( strlen( $username ) > 60 ) {
			$username = substr( $username, 0, 50 ) . '-' . $short_uuid;
		}

		return $username;
	}

	/**
	 * Generates an email address for a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param string $username Service account username.
	 * @return string Generated email address.
	 */
	private function generate_email( string $username ): string {
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';

		return $username . '@' . $site_domain;
	}

	/**
	 * Gets or generates a username for the current request.
	 *
	 * @since 0.3.0
	 *
	 * @param string $name Service account name.
	 * @return string Generated username.
	 */
	private function get_generated_username( string $name ): string {
		if ( null !== $this->generated_username ) {
			return $this->generated_username;
		}

		$this->generated_username = $this->generate_username( $name );

		return $this->generated_username;
	}

	/**
	 * Populates the user login when creating service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @param string $user_login Proposed user login.
	 * @return string Filtered user login.
	 */
	public function maybe_generate_user_login( string $user_login ): string {
		if ( ! $this->is_service_account_form_submission() ) {
			return $user_login;
		}

		if ( '' !== $user_login ) {
			return $user_login;
		}

		$name = $this->get_service_account_name_from_post();

		return $this->get_generated_username( $name );
	}

	/**
	 * Populates the user email when creating service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @param string $user_email Proposed user email.
	 * @return string Filtered user email.
	 */
	public function maybe_generate_user_email( string $user_email ): string {
		if ( ! $this->is_service_account_form_submission() ) {
			return $user_email;
		}

		if ( '' !== $user_email ) {
			return $user_email;
		}

		$name     = $this->get_service_account_name_from_post();
		$username = $this->get_generated_username( $name );

		return $this->generate_email( $username );
	}

	/**
	 * Handles post-processing after service account creation in wp-admin.
	 *
	 * @since 0.3.0
	 *
	 * @param int $user_id The created user ID.
	 */
	public function handle_user_register( int $user_id ): void {
		if ( ! $this->is_service_account_form_submission() ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $this->manager->is_service_account( $user ) ) {
			return;
		}

		// Ensure service account meta is set for admin-created users.
		update_user_meta( $user_id, Service_Account_Manager::META_KEY, true );
		if ( ! get_user_meta( $user_id, '_service_account_created', true ) ) {
			update_user_meta( $user_id, '_service_account_created', time() );
		}

		$name = $this->get_service_account_name_from_post();
		if ( '' !== $name && $name !== $user->display_name ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $name,
					'nickname'     => $name,
				)
			);
		}
	}

	/**
	 * Gets the admin CSS styles.
	 *
	 * @since 0.3.0
	 *
	 * @return string CSS styles.
	 */
	protected function get_styles(): string {
		$css = '
			/* ============================================
			 * Service Account - Users List Table
			 * ============================================ */

			/* Row highlighting */
			.users-php tr.service-account-row {
				background: linear-gradient(to right, rgba(34, 113, 177, 0.04), transparent);
				border-left: 3px solid var(--wp-admin-theme-color, #2271b1);
				opacity: 0.8;
			}

			.users-php tr.service-account-row:hover {
				background: linear-gradient(to right, rgba(34, 113, 177, 0.08), #f6f7f7);
			}

			.users-php tr.service-account-row td {
				border-top-color: rgba(34, 113, 177, 0.2);
			}

			.users-php tr.service-account-row .username strong {
				display: inline-flex;
				align-items: center;
				gap: 6px;
			}

			/* ============================================
			 * Service Account - Edit Page
			 * ============================================ */

			/* Page header notice */
			body.is-service-account-edit #wpbody-content > .wrap > h1 {
				display: flex;
				align-items: center;
				gap: 12px;
			}

			/* Hide Add New button on service account edit screens */
			body.is-service-account-edit .page-title-action {
				display: none;
			}

			.service-account-badge {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				background: var(--wp-admin-theme-color, #2271b1);
				color: #fff;
				font-size: 12px;
				font-weight: 500;
				padding: 4px 12px;
				border-radius: 3px;
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}

			/* Service account notice styled like application passwords */
			.service-account-notice {
				background: rgba(34, 113, 177, 0.02);
				border: 1px solid rgba(34, 113, 177, 0.1);
				border-radius: 4px;
				padding: 15px;
				margin: 0 0 15px;
			}

			/* Service account details section */
			.service-account-details-section {
				margin: 20px 0 10px;
			}

			.service-account-details-section .description {
				margin-top: 4px;
			}

			.service-account-owner-control .components-spinner {
				margin-left: 8px;
			}

			.service-account-owner-control .button-link {
				margin-top: 6px;
			}

			body.is-service-account-edit .service-account-owner-control .components-combobox-control__input,
			body.is-service-account-edit .service-account-owner-control .components-text-control__input,
			body.is-service-account-edit .service-account-owner-control .components-input-control__container,
			body.is-service-account-edit .service-account-owner-control .components-combobox-control__input-wrapper,
			body.is-service-account-edit .service-account-owner-control .components-combobox-control__suggestions-container,
			body.is-service-account-edit .service-account-owner-control .components-flex {
				background-color: #fff;
			}

			.service-account-notice h3 {
				margin: 0 0 8px;
				font-size: 14px;
				color: #1d2327;
			}

			.service-account-notice p {
				margin: 0 0 8px;
				color: #50575e;
			}

			.service-account-notice p:last-child {
				margin-bottom: 0;
			}

			.service-account-notice ul {
				margin: 8px 0 0 20px;
				list-style: disc;
			}

			.service-account-notice li {
				margin: 4px 0;
				color: #50575e;
			}

			/* Subtle edit page background */
			body.is-service-account-edit .form-table th {
				color: #1d2327;
			}

			/* Hide default profile sections on service account edit (prevents flash) */
			html.service-account-js body.is-service-account-edit #your-profile > h2:nth-of-type(1),
			html.service-account-js body.is-service-account-edit #your-profile > h2:nth-of-type(2),
			html.service-account-js body.is-service-account-edit #your-profile > h2:nth-of-type(3),
			html.service-account-js body.is-service-account-edit #your-profile > h2:nth-of-type(4),
			html.service-account-js body.is-service-account-edit #your-profile > table.form-table:nth-of-type(1),
			html.service-account-js body.is-service-account-edit #your-profile > table.form-table:nth-of-type(2),
			html.service-account-js body.is-service-account-edit #your-profile > table.form-table:nth-of-type(3),
			html.service-account-js body.is-service-account-edit #your-profile > table.form-table:nth-of-type(4) {
				display: none;
			}

			html.service-account-js body.is-service-account-edit .user-rich-editing-wrap,
			html.service-account-js body.is-service-account-edit .user-comment-shortcuts-wrap,
			html.service-account-js body.is-service-account-edit .user-admin-bar-front-wrap,
			html.service-account-js body.is-service-account-edit .user-language-wrap,
			html.service-account-js body.is-service-account-edit .user-profile-picture,
			html.service-account-js body.is-service-account-edit .user-syntax-highlighting-wrap,
			html.service-account-js body.is-service-account-edit .user-admin-color-wrap {
				display: none;
			}

			/* Application passwords section highlight */
			body.is-service-account-edit .application-passwords {
				background: rgba(34, 113, 177, 0.02);
				border: 1px solid rgba(34, 113, 177, 0.1);
				border-radius: 4px;
				padding: 15px;
				margin-top: 10px;
			}

		';

		/**
		 * Filters the admin CSS styles for service accounts.
		 *
		 * @since 0.3.0
		 *
		 * @param string $css The CSS styles.
		 */
		return apply_filters( 'service_account_admin_styles', $css );
	}

	/**
	 * Adds body class for service account edit pages.
	 *
	 * @since 0.3.0
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string Modified body classes.
	 */
	public function add_body_class( string $classes ): string {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return $classes;
		}

		// Check if editing a service account.
		if ( 'user-edit' === $screen->base ) {
			$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $user_id && $this->manager->is_service_account( $user_id ) ) {
				$classes .= ' is-service-account-edit';
			}
		}

		// Check if viewing own profile as service account.
		if ( 'profile' === $screen->base && $this->manager->is_service_account( get_current_user_id() ) ) {
			$classes .= ' is-service-account-edit';
		}

		return $classes;
	}

	/**
	 * Adds a JS-ready class to the document for service account layouts.
	 *
	 * @since 0.3.0
	 */
	public function maybe_add_layout_class(): void {
		if ( ! $this->is_service_account_edit_screen() ) {
			return;
		}

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( "document.documentElement.classList.add('service-account-js');" );
		} else {
			echo "<script>document.documentElement.classList.add('service-account-js');</script>";
		}
	}

	/**
	 * Checks if the current screen is a service account edit screen.
	 *
	 * @since 0.3.0
	 *
	 * @return bool True if editing a service account.
	 */
	private function is_service_account_edit_screen(): bool {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		if ( 'user-edit' === $screen->base ) {
			$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $user_id && $this->manager->is_service_account( $user_id );
		}

		if ( 'profile' === $screen->base ) {
			return $this->manager->is_service_account( get_current_user_id() );
		}

		return false;
	}

	/**
	 * Filters user row actions.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, string> $actions User row actions.
	 * @param \WP_User              $user    User object.
	 * @return array<string, string> Modified actions.
	 */
	public function filter_row_actions( array $actions, \WP_User $user ): array {
		if ( ! $this->manager->is_service_account( $user ) ) {
			return $actions;
		}

		// Remove actions that don't apply to service accounts.
		unset( $actions['resetpassword'] );

		return $actions;
	}

	/**
	 * Hides the password section for service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function maybe_hide_password_section( \WP_User $user ): void {
		if ( ! $this->manager->is_service_account( $user ) ) {
			return;
		}

		// Add CSS to de-emphasize the password section.
		?>
		<style>
			.user-pass-wrap {
				opacity: 0.6;
			}
			.user-pass-wrap::before {
				content: "<?php echo esc_js( __( 'Service accounts should authenticate via Application Passwords instead.', 'ai' ) ); ?>";
				display: block;
				margin-bottom: 10px;
				padding: 8px 12px;
				background: #f0f6fc;
				border-left: 3px solid #2271b1;
				font-style: italic;
				color: #50575e;
			}
		</style>
		<?php
	}
}
