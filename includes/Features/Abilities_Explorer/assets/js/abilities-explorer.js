/**
 * Ability Explorer Admin JavaScript
 *
 * @package WordPress\AI\Features\Abilities_Explorer
 * @since 0.1.0
 */

(function($) {
	'use strict';

	// Initialize on document ready
	$(document).ready(function() {
		AiAbilityExplorer.init();
	});

	/**
	 * Main AI Ability Explorer object
	 */
	const AiAbilityExplorer = {
		/**
		 * Initialize
		 */
		init: function() {
			this.initTestRunner();
			this.initCopyButtons();
			this.initValidation();
		},

		/**
		 * Initialize Test Runner
		 */
		initTestRunner: function() {
			const self = this;

			// Invoke ability button
			$('#ability-test-invoke').on('click', function() {
				const abilitySlug = $(this).data('ability');
				const input = $('#ability-test-payload').val();

				self.invokeAbility(abilitySlug, input);
			});

			// Validate button
			$('#ability-test-validate').on('click', function() {
				self.validateInput();
			});

			// Clear result button
			$('#ability-test-clear').on('click', function() {
				$('#ability-test-result-container').hide();
				$('#ability-test-result').html('');
				$('#ability-test-validation').hide();
			});

			// Auto-format JSON on blur
			$('#ability-test-payload').on('blur', function() {
				self.formatJSON($(this));
			});
		},

		/**
		 * Initialize Copy Buttons
		 */
		initCopyButtons: function() {
			const self = this;

			$('.ability-copy-btn').on('click', function() {
				const targetId = $(this).data('copy');
				const $target = $('#' + targetId);

				if ($target.length) {
					self.copyToClipboard($target.text(), $(this));
				}
			});
		},

		/**
		 * Initialize Validation
		 */
		initValidation: function() {
			// Real-time JSON validation
			$('#ability-test-payload').on('input', function() {
				const $textarea = $(this);
				const value = $textarea.val().trim();

				// Clear previous validation styling
				$textarea.removeClass('json-valid json-invalid');

				if (value) {
					try {
						JSON.parse(value);
						$textarea.addClass('json-valid');
					} catch (e) {
						$textarea.addClass('json-invalid');
					}
				}
			});
		},

		/**
		 * Invoke an ability via AJAX
		 */
		invokeAbility: function(abilitySlug, inputString) {
			const self = this;
			const $button = $('#ability-test-invoke');
			const $resultContainer = $('#ability-test-result-container');
			const $result = $('#ability-test-result');

			// Validate JSON
			let input;
			try {
				input = JSON.parse(inputString);
			} catch (e) {
				self.showValidation(false, [aiAbilityExplorer.strings.invalidJson]);
				return;
			}

			// Show loading state
			$button.prop('disabled', true);
			$button.html(aiAbilityExplorer.strings.invoking + '<span class="ability-loading"></span>');

			// Make AJAX request
			$.ajax({
				url: aiAbilityExplorer.ajaxUrl,
				type: 'POST',
				data: {
					action: 'ai_ability_explorer_invoke',
					nonce: aiAbilityExplorer.nonce,
					ability: abilitySlug,
					input: inputString
				},
				success: function(response) {
					if (response.success) {
						self.showResult(true, response.data);
					} else {
						self.showResult(false, response.data);
					}
				},
				error: function(xhr, status, error) {
					self.showResult(false, {
						message: error,
						error: 'AJAX request failed'
					});
				},
				complete: function() {
					// Reset button
					$button.prop('disabled', false);
					$button.html($button.data('original-text') || aiAbilityExplorer.strings.success);

					// Store original text if not already stored
					if (!$button.data('original-text')) {
						$button.data('original-text', $button.text());
					}
				}
			});

			// Hide validation message
			$('#ability-test-validation').hide();
		},

		/**
		 * Validate input against schema
		 */
		validateInput: function() {
			const inputString = $('#ability-test-payload').val().trim();
			const schemaElement = document.getElementById('ability-input-schema');

			// Validate JSON syntax
			let input;
			try {
				input = JSON.parse(inputString);
			} catch (e) {
				this.showValidation(false, [aiAbilityExplorer.strings.invalidJson + ': ' + e.message]);
				return;
			}

			// If no schema, just validate JSON syntax
			if (!schemaElement) {
				this.showValidation(true, ['JSON syntax is valid']);
				return;
			}

			// Parse schema
			let schema;
			try {
				schema = JSON.parse(schemaElement.textContent);
			} catch (e) {
				this.showValidation(false, ['Failed to parse input schema']);
				return;
			}

			// Validate against schema
			const errors = this.validateAgainstSchema(input, schema);

			if (errors.length === 0) {
				this.showValidation(true, ['Input is valid according to the schema']);
			} else {
				this.showValidation(false, errors);
			}
		},

		/**
		 * Validate input against JSON schema
		 */
		validateAgainstSchema: function(input, schema) {
			const errors = [];

			// Check required fields
			if (schema.required && Array.isArray(schema.required)) {
				schema.required.forEach(function(field) {
					if (!(field in input)) {
						errors.push('Required field "' + field + '" is missing');
					}
				});
			}

			// Check property types
			if (schema.properties) {
				Object.keys(schema.properties).forEach(function(propName) {
					if (propName in input) {
						const propSchema = schema.properties[propName];
						const value = input[propName];

						if (propSchema.type) {
							const isValid = this.validateType(value, propSchema.type);
							if (!isValid) {
								errors.push('Field "' + propName + '" should be of type "' + propSchema.type + '"');
							}
						}
					}
				}.bind(this));
			}

			return errors;
		},

		/**
		 * Validate value type
		 */
		validateType: function(value, expectedType) {
			switch (expectedType) {
				case 'string':
					return typeof value === 'string';
				case 'number':
				case 'integer':
					return typeof value === 'number';
				case 'boolean':
					return typeof value === 'boolean';
				case 'array':
					return Array.isArray(value);
				case 'object':
					return typeof value === 'object' && !Array.isArray(value);
				default:
					return true;
			}
		},

		/**
		 * Show validation result
		 */
		showValidation: function(isValid, messages) {
			const $validation = $('#ability-test-validation');
			const iconHtml = isValid ? '✓' : '✗';
			const titleText = isValid ? 'Valid' : 'Validation Errors';
			const className = isValid ? 'validation-success' : 'validation-error';

			let html = '<h4>' + iconHtml + ' ' + titleText + '</h4>';

			if (messages.length > 0) {
				html += '<ul>';
				messages.forEach(function(message) {
					html += '<li>' + this.escapeHtml(message) + '</li>';
				}.bind(this));
				html += '</ul>';
			}

			$validation
				.html(html)
				.removeClass('validation-success validation-error')
				.addClass(className)
				.show();
		},

		/**
		 * Show result
		 */
		showResult: function(isSuccess, data) {
			const $resultContainer = $('#ability-test-result-container');
			const $result = $('#ability-test-result');
			const className = isSuccess ? 'ability-test-result-success' : 'ability-test-result-error';
			const titleText = isSuccess ? aiAbilityExplorer.strings.success : aiAbilityExplorer.strings.error;

			let html = '<h4>' + titleText + '</h4>';
			html += '<pre>' + this.escapeHtml(JSON.stringify(data, null, 2)) + '</pre>';

			$result
				.html(html)
				.removeClass('ability-test-result-success ability-test-result-error')
				.addClass(className);

			$resultContainer.show();

			// Scroll to result
			$('html, body').animate({
				scrollTop: $resultContainer.offset().top - 50
			}, 500);
		},

		/**
		 * Format JSON in textarea
		 */
		formatJSON: function($textarea) {
			const value = $textarea.val().trim();

			if (!value) {
				return;
			}

			try {
				const parsed = JSON.parse(value);
				const formatted = JSON.stringify(parsed, null, 2);
				$textarea.val(formatted);
			} catch (e) {
				// Invalid JSON, don't format
			}
		},

		/**
		 * Copy text to clipboard
		 */
		copyToClipboard: function(text, $button) {
			// Modern clipboard API
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(function() {
					this.showCopyFeedback($button, true);
				}.bind(this), function() {
					this.showCopyFeedback($button, false);
				}.bind(this));
			} else {
				// Fallback for older browsers
				const $temp = $('<textarea>');
				$('body').append($temp);
				$temp.val(text).select();

				try {
					const successful = document.execCommand('copy');
					this.showCopyFeedback($button, successful);
				} catch (err) {
					this.showCopyFeedback($button, false);
				}

				$temp.remove();
			}
		},

		/**
		 * Show copy feedback
		 */
		showCopyFeedback: function($button, success) {
			const originalText = $button.text();
			const feedbackText = success ? aiAbilityExplorer.strings.copySuccess : aiAbilityExplorer.strings.copyError;

			$button.text(feedbackText);

			setTimeout(function() {
				$button.text(originalText);
			}, 2000);
		},

		/**
		 * Escape HTML
		 */
		escapeHtml: function(text) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};

			return text.replace(/[&<>"']/g, function(m) {
				return map[m];
			});
		}
	};

})(jQuery);
