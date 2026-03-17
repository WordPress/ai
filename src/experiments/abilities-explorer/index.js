/**
 * Ability Explorer Admin JavaScript
 */

/**
 * Global dependencies
 */
const { aiAbilityExplorer, navigator } = window;

/**
 * Internal dependencies
 */
import './index.scss';

( function () {
	'use strict';

	/**
	 * Main AI Ability Explorer object
	 */
	const AiAbilityExplorer = {
		/**
		 * Initialize
		 */
		init() {
			this.initTestRunner();
			this.initCopyButtons();
			this.initValidation();
		},

		/**
		 * Initialize Test Runner
		 */
		initTestRunner() {
			const self = this;

			// Invoke ability button
			const invokeButton = document.getElementById(
				'ability-test-invoke'
			);
			if ( invokeButton ) {
				invokeButton.addEventListener( 'click', function () {
					const abilitySlug = this.dataset.ability;
					const payload = document.getElementById(
						'ability-test-payload'
					);
					const input = payload ? payload.value : '';

					self.invokeAbility( abilitySlug, input );
				} );
			}

			// Validate button
			const validateButton = document.getElementById(
				'ability-test-validate'
			);
			if ( validateButton ) {
				validateButton.addEventListener( 'click', function () {
					self.validateInput();
				} );
			}

			// Clear result button
			const clearButton = document.getElementById( 'ability-test-clear' );
			if ( clearButton ) {
				clearButton.addEventListener( 'click', function () {
					const resultContainer = document.getElementById(
						'ability-test-result-container'
					);
					const result = document.getElementById(
						'ability-test-result'
					);
					const validation = document.getElementById(
						'ability-test-validation'
					);

					if ( resultContainer ) {
						resultContainer.style.display = 'none';
					}
					if ( result ) {
						result.innerHTML = '';
					}
					if ( validation ) {
						validation.style.display = 'none';
					}
				} );
			}

			// Auto-format JSON on blur
			const payload = document.getElementById( 'ability-test-payload' );
			if ( payload ) {
				payload.addEventListener( 'blur', function () {
					self.formatJSON( this );
				} );
			}
		},

		/**
		 * Initialize Copy Buttons
		 */
		initCopyButtons() {
			const self = this;
			const copyButtons =
				document.querySelectorAll( '.ability-copy-btn' );

			copyButtons.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					const targetId = this.dataset.copy;
					const target = document.getElementById( targetId );

					if ( target ) {
						self.copyToClipboard( target.textContent, this );
					}
				} );
			} );
		},

		/**
		 * Initialize Validation
		 */
		initValidation() {
			// Real-time JSON validation
			const payload = document.getElementById( 'ability-test-payload' );
			if ( payload ) {
				payload.addEventListener( 'input', function () {
					const value = this.value.trim();

					// Clear previous validation styling
					this.classList.remove( 'json-valid', 'json-invalid' );

					if ( value ) {
						try {
							JSON.parse( value );
							this.classList.add( 'json-valid' );
						} catch ( e ) {
							this.classList.add( 'json-invalid' );
						}
					}
				} );
			}
		},

		/**
		 * Invoke an ability via AJAX
		 *
		 * @param {string} abilitySlug - The ability slug to invoke.
		 * @param {string} inputString - The input data as JSON string.
		 */
		invokeAbility( abilitySlug, inputString ) {
			const self = this;

			// Validate JSON
			try {
				JSON.parse( inputString );
			} catch ( e ) {
				self.showValidation( false, [
					aiAbilityExplorer.strings.invalidJson,
				] );
				return;
			}

			// Show loading state
			const button = document.getElementById( 'ability-test-invoke' );
			if ( button ) {
				button.disabled = true;
				const originalText =
					button.dataset.originalText || button.textContent;
				if ( ! button.dataset.originalText ) {
					button.dataset.originalText = originalText;
				}
				button.innerHTML =
					aiAbilityExplorer.strings.invoking +
					'<span class="ability-loading"></span>';
			}

			// Make AJAX request using fetch
			const formData = new FormData();
			formData.append( 'action', 'ai_ability_explorer_invoke' );
			formData.append( 'nonce', aiAbilityExplorer.nonce );
			formData.append( 'ability', abilitySlug );
			formData.append( 'input', inputString );

			fetch( aiAbilityExplorer.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( response ) {
					if ( response.success ) {
						self.showResult( true, response.data );
					} else {
						self.showResult( false, response.data );
					}
				} )
				.catch( function ( error ) {
					self.showResult( false, {
						message: error.message,
						error: 'AJAX request failed',
					} );
				} )
				.finally( function () {
					// Reset button
					if ( button ) {
						button.disabled = false;
						button.textContent = button.dataset.originalText;
					}
				} );

			// Hide validation message
			const validation = document.getElementById(
				'ability-test-validation'
			);
			if ( validation ) {
				validation.style.display = 'none';
			}
		},

		/**
		 * Validate input against schema
		 */
		validateInput() {
			const payload = document.getElementById( 'ability-test-payload' );
			const inputString = payload ? payload.value.trim() : '';

			// Validate JSON syntax
			let input;
			try {
				input = JSON.parse( inputString );
			} catch ( e ) {
				this.showValidation( false, [
					aiAbilityExplorer.strings.invalidJson + ': ' + e.message,
				] );
				return;
			}

			// If no schema, just validate JSON syntax
			const schemaElement = document.getElementById(
				'ability-input-schema'
			);
			if ( ! schemaElement ) {
				this.showValidation( true, [ 'JSON syntax is valid' ] );
				return;
			}

			// Parse schema
			let schema;
			try {
				schema = JSON.parse( schemaElement.textContent );
			} catch ( e ) {
				this.showValidation( false, [
					'Failed to parse input schema',
				] );
				return;
			}

			// Validate against schema
			const errors = this.validateAgainstSchema( input, schema );

			if ( errors.length === 0 ) {
				this.showValidation( true, [
					'Input is valid according to the schema',
				] );
			} else {
				this.showValidation( false, errors );
			}
		},

		/**
		 * Validate input against JSON schema
		 *
		 * @param {Object} input  - The input data to validate.
		 * @param {Object} schema - The JSON schema to validate against.
		 * @return {Array} Array of error messages.
		 */
		validateAgainstSchema( input, schema ) {
			const errors = [];

			// Check required fields
			if ( schema.required && Array.isArray( schema.required ) ) {
				schema.required.forEach( function ( field ) {
					if ( ! ( field in input ) ) {
						errors.push(
							'Required field "' + field + '" is missing'
						);
					}
				} );
			}

			// Check property types
			if ( schema.properties ) {
				Object.keys( schema.properties ).forEach(
					function ( propName ) {
						if ( propName in input ) {
							const propSchema = schema.properties[ propName ];
							const value = input[ propName ];

							if ( propSchema.type ) {
								const isValid = this.validateType(
									value,
									propSchema.type
								);
								if ( ! isValid ) {
									errors.push(
										'Field "' +
											propName +
											'" should be of type "' +
											propSchema.type +
											'"'
									);
								}
							}
						}
					}.bind( this )
				);
			}

			return errors;
		},

		/**
		 * Validate value type
		 *
		 * @param {*}      value        - The value to validate.
		 * @param {string} expectedType - The expected type.
		 * @return {boolean} True if valid, false otherwise.
		 */
		validateType( value, expectedType ) {
			switch ( expectedType ) {
				case 'string':
					return typeof value === 'string';
				case 'number':
				case 'integer':
					return typeof value === 'number';
				case 'boolean':
					return typeof value === 'boolean';
				case 'array':
					return Array.isArray( value );
				case 'object':
					return (
						typeof value === 'object' && ! Array.isArray( value )
					);
				default:
					return true;
			}
		},

		/**
		 * Show validation result
		 *
		 * @param {boolean} isValid  - Whether validation passed.
		 * @param {Array}   messages - Array of validation messages.
		 */
		showValidation( isValid, messages ) {
			const validation = document.getElementById(
				'ability-test-validation'
			);
			if ( ! validation ) {
				return;
			}

			const iconHtml = isValid ? '✓' : '✗';
			const titleText = isValid ? 'Valid' : 'Validation Errors';
			const className = isValid
				? 'validation-success'
				: 'validation-error';

			let html = '<h4>' + iconHtml + ' ' + titleText + '</h4>';

			if ( messages.length > 0 ) {
				html += '<ul>';
				messages.forEach(
					function ( message ) {
						html += '<li>' + this.escapeHtml( message ) + '</li>';
					}.bind( this )
				);
				html += '</ul>';
			}

			validation.innerHTML = html;
			validation.classList.remove(
				'validation-success',
				'validation-error'
			);
			validation.classList.add( className );
			validation.style.display = 'block';
		},

		/**
		 * Show result
		 *
		 * @param {boolean} isSuccess - Whether the request was successful.
		 * @param {Object}  data      - The result data.
		 */
		showResult( isSuccess, data ) {
			const resultContainer = document.getElementById(
				'ability-test-result-container'
			);
			const result = document.getElementById( 'ability-test-result' );

			if ( ! resultContainer || ! result ) {
				return;
			}

			const className = isSuccess
				? 'ability-test-result-success'
				: 'ability-test-result-error';
			const titleText = isSuccess
				? aiAbilityExplorer.strings.success
				: aiAbilityExplorer.strings.error;

			let html = '<h4>' + titleText + '</h4>';
			html +=
				'<pre>' +
				this.escapeHtml( JSON.stringify( data, null, 2 ) ) +
				'</pre>';

			result.innerHTML = html;
			result.classList.remove(
				'ability-test-result-success',
				'ability-test-result-error'
			);
			result.classList.add( className );

			resultContainer.style.display = 'block';

			// Scroll to result
			const rect = resultContainer.getBoundingClientRect();
			const scrollTop =
				window.pageYOffset ||
				document.documentElement.scrollTop ||
				document.body.scrollTop ||
				0;
			const targetPosition = rect.top + scrollTop - 50;

			window.scrollTo( {
				top: targetPosition,
				behavior: 'smooth',
			} );
		},

		/**
		 * Format JSON in textarea
		 *
		 * @param {HTMLElement} textarea - The textarea element.
		 */
		formatJSON( textarea ) {
			const value = textarea.value.trim();

			if ( ! value ) {
				return;
			}

			try {
				const parsed = JSON.parse( value );
				const formatted = JSON.stringify( parsed, null, 2 );
				textarea.value = formatted;
			} catch ( e ) {
				// Invalid JSON, don't format
			}
		},

		/**
		 * Copy text to clipboard
		 *
		 * @param {string}      text   - The text to copy.
		 * @param {HTMLElement} button - The button element.
		 */
		copyToClipboard( text, button ) {
			// Modern clipboard API
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then(
					function () {
						this.showCopyFeedback( button, true );
					}.bind( this ),
					function () {
						this.showCopyFeedback( button, false );
					}.bind( this )
				);
			} else {
				// Fallback for older browsers
				const temp = document.createElement( 'textarea' );
				document.body.appendChild( temp );
				temp.value = text;
				temp.select();

				try {
					const successful = document.execCommand( 'copy' );
					this.showCopyFeedback( button, successful );
				} catch ( err ) {
					this.showCopyFeedback( button, false );
				}

				document.body.removeChild( temp );
			}
		},

		/**
		 * Show copy feedback
		 *
		 * @param {HTMLElement} button  - The button element.
		 * @param {boolean}     success - Whether copy was successful.
		 */
		showCopyFeedback( button, success ) {
			const originalHTML = button.innerHTML;
			const feedbackText = success
				? aiAbilityExplorer.strings.copySuccess
				: aiAbilityExplorer.strings.copyError;
			const feedbackIcon = success
				? '<span class="dashicons dashicons-yes"></span>'
				: '<span class="dashicons dashicons-no-alt"></span>';

			button.innerHTML =
				feedbackIcon + ' ' + this.escapeHtml( feedbackText );

			setTimeout( function () {
				button.innerHTML = originalHTML;
			}, 1500 );
		},

		/**
		 * Escape HTML
		 *
		 * @param {string} text - The text to escape.
		 * @return {string} The escaped text.
		 */
		escapeHtml( text ) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			};

			return text.replace( /[&<>"']/g, function ( m ) {
				return map[ m ];
			} );
		},
	};

	// Don't render if disabled.
	if ( ! aiAbilityExplorer?.enabled ) {
		return null;
	}

	// Initialize on document ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			AiAbilityExplorer.init();
		} );
	} else {
		AiAbilityExplorer.init();
	}
} )();
