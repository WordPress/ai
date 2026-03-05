/**
 * Extended Providers – WP 7.0 Connectors Integration
 *
 * Registers additional AI provider connectors on the Settings > Connectors page.
 * Provider data is injected by PHP into window.wpAiExtendedConnectors.
 *
 * @since 0.4.0
 * @package WordPress\AI\Experiments\Extended_Providers
 */

import {
	__experimentalRegisterConnector as registerConnector,
	__experimentalConnectorItem as ConnectorItem,
	__experimentalDefaultConnectorSettings as DefaultConnectorSettings,
} from '@wordpress/connectors';

const { createElement: h, useState, useEffect, useCallback } = window.wp.element;
const {
	Button,
	TextControl,
	__experimentalHStack: HStack,
	__experimentalVStack: VStack,
	ExternalLink,
} = window.wp.components;
const apiFetch = window.wp.apiFetch;
const { __, sprintf } = window.wp.i18n;

/**
 * Provider data injected from PHP.
 *
 * @type {Array<{id: string, label: string, description: string, settingName: string, helpUrl: string, helpLabel: string, extraFields: Array}>}
 */
const providers = window.wpAiExtendedConnectors || [];

/* ──────────────────────────────────────────────
 * Provider SVG icons (40×40, matching core style)
 * Extracted from feature/providers branch TSX.
 * ────────────────────────────────────────────── */

function svg( viewBox, ...children ) {
	return h(
		'svg',
		{
			width: '40',
			height: '40',
			viewBox,
			fill: 'currentColor',
			xmlns: 'http://www.w3.org/2000/svg',
		},
		...children
	);
}

function path( d, extra ) {
	return h( 'path', { d, ...extra } );
}

const ICONS = {
	cloudflare: () =>
		h(
			'svg',
			{
				width: '40',
				height: '40',
				viewBox: '0 0 24 24',
				fill: 'none',
				xmlns: 'http://www.w3.org/2000/svg',
			},
			path(
				'M16.493 17.4c.135-.52.08-.983-.161-1.338-.215-.328-.592-.519-1.05-.519l-8.663-.109a.148.148 0 01-.135-.082c-.027-.054-.027-.109-.027-.163.027-.082.108-.164.189-.164l8.744-.11c1.05-.054 2.153-.9 2.556-1.937l.511-1.31c.027-.055.027-.11.027-.164C17.92 8.91 15.66 7 12.942 7c-2.503 0-4.628 1.638-5.381 3.903a2.432 2.432 0 00-1.803-.491c-1.21.109-2.153 1.092-2.287 2.32-.027.328 0 .628.054.9C1.56 13.688 0 15.326 0 17.319c0 .19.027.355.027.545 0 .082.08.137.161.137h15.983c.08 0 .188-.055.215-.164l.107-.437',
				{ fill: '#F38020' }
			),
			path(
				'M19.238 11.75h-.242c-.054 0-.108.054-.135.109l-.35 1.2c-.134.52-.08.983.162 1.338.215.328.592.518 1.05.518l1.855.11c.054 0 .108.027.135.082.027.054.027.109.027.163-.027.082-.108.164-.188.164l-1.91.11c-1.05.054-2.153.9-2.557 1.937l-.134.355c-.027.055.026.137.107.137h6.592c.081 0 .162-.055.162-.137.107-.41.188-.846.188-1.31-.027-2.62-2.153-4.777-4.762-4.777',
				{ fill: '#FCAD32' }
			)
		),

	cohere: () =>
		h(
			'svg',
			{
				width: '40',
				height: '40',
				viewBox: '0 0 24 24',
				fill: 'none',
				xmlns: 'http://www.w3.org/2000/svg',
			},
			path(
				'M8.128 14.099c.592 0 1.77-.033 3.398-.703 1.897-.781 5.672-2.2 8.395-3.656 1.905-1.018 2.74-2.366 2.74-4.18A4.56 4.56 0 0018.1 1H7.549A6.55 6.55 0 001 7.55c0 3.617 2.745 6.549 7.128 6.549z',
				{ fill: '#39594D', fillRule: 'evenodd', clipRule: 'evenodd' }
			),
			path(
				'M9.912 18.61a4.387 4.387 0 012.705-4.052l3.323-1.38c3.361-1.394 7.06 1.076 7.06 4.715a5.104 5.104 0 01-5.105 5.104l-3.597-.001a4.386 4.386 0 01-4.386-4.387z',
				{ fill: '#D18EE2', fillRule: 'evenodd', clipRule: 'evenodd' }
			),
			path(
				'M4.776 14.962A3.775 3.775 0 001 18.738v.489a3.776 3.776 0 007.551 0v-.49a3.775 3.775 0 00-3.775-3.775z',
				{ fill: '#FF7759' }
			)
		),

	deepseek: () =>
		svg(
			'0 0 24 24',
			path(
				'M23.748 4.482c-.254-.124-.364.113-.512.234-.051.039-.094.09-.137.136-.372.397-.806.657-1.373.626-.829-.046-1.537.214-2.163.848-.133-.782-.575-1.248-1.247-1.548-.352-.156-.708-.311-.955-.65-.172-.241-.219-.51-.305-.774-.055-.16-.11-.323-.293-.35-.2-.031-.278.136-.356.276-.313.572-.434 1.202-.422 1.84.027 1.436.633 2.58 1.838 3.393.137.093.172.187.129.323-.082.28-.18.552-.266.833-.055.179-.137.217-.329.14a5.526 5.526 0 01-1.736-1.18c-.857-.828-1.631-1.742-2.597-2.458a11.365 11.365 0 00-.689-.471c-.985-.957.13-1.743.388-1.836.27-.098.093-.432-.779-.428-.872.004-1.67.295-2.687.684a3.055 3.055 0 01-.465.137 9.597 9.597 0 00-2.883-.102c-1.885.21-3.39 1.102-4.497 2.623C.082 8.606-.231 10.684.152 12.85c.403 2.284 1.569 4.175 3.36 5.653 1.858 1.533 3.997 2.284 6.438 2.14 1.482-.085 3.133-.284 4.994-1.86.47.234.962.327 1.78.397.63.059 1.236-.03 1.705-.128.735-.156.684-.837.419-.961-2.155-1.004-1.682-.595-2.113-.926 1.096-1.296 2.746-2.642 3.392-7.003.05-.347.007-.565 0-.845-.004-.17.035-.237.23-.256a4.173 4.173 0 001.545-.475c1.396-.763 1.96-2.015 2.093-3.517.02-.23-.004-.467-.247-.588zM11.581 18c-2.089-1.642-3.102-2.183-3.52-2.16-.392.024-.321.471-.235.763.09.288.207.486.371.739.114.167.192.416-.113.603-.673.416-1.842-.14-1.897-.167-1.361-.802-2.5-1.86-3.301-3.307-.774-1.393-1.224-2.887-1.298-4.482-.02-.386.093-.522.477-.592a4.696 4.696 0 011.529-.039c2.132.312 3.946 1.265 5.468 2.774.868.86 1.525 1.887 2.202 2.891.72 1.066 1.494 2.082 2.48 2.914.348.292.625.514.891.677-.802.09-2.14.11-3.054-.614zm1-6.44a.306.306 0 01.415-.287.302.302 0 01.2.288.306.306 0 01-.31.307.303.303 0 01-.304-.308zm3.11 1.596c-.2.081-.399.151-.59.16a1.245 1.245 0 01-.798-.254c-.274-.23-.47-.358-.552-.758a1.73 1.73 0 01.016-.588c.07-.327-.008-.537-.239-.727-.187-.156-.426-.199-.688-.199a.559.559 0 01-.254-.078c-.11-.054-.2-.19-.114-.358.028-.054.16-.186.192-.21.356-.202.767-.136 1.146.016.352.144.618.408 1.001.782.391.451.462.576.685.914.176.265.336.537.445.848.067.195-.019.354-.25.452z'
			)
		),

	fal: () =>
		svg(
			'0 0 24 24',
			path(
				'M15.477 0c.415 0 .749.338.788.752a7.775 7.775 0 006.985 6.984c.413.04.752.373.752.788v6.952c0 .415-.338.748-.752.788a7.775 7.775 0 00-6.985 6.984c-.04.414-.373.752-.788.752H8.525c-.416 0-.749-.338-.789-.752a7.775 7.775 0 00-6.984-6.984c-.414-.04-.752-.373-.752-.788V8.524c0-.415.338-.748.752-.788A7.775 7.775 0 007.736.752C7.776.338 8.11 0 8.526 0h6.95zM4.819 11.98a7.226 7.226 0 007.223 7.23 7.226 7.226 0 007.223-7.23c0-3.994-3.234-7.23-7.223-7.23a7.227 7.227 0 00-7.223 7.23z',
				{ fillRule: 'evenodd', clipRule: 'evenodd' }
			)
		),

	grok: () =>
		svg(
			'0 0 24 24',
			path(
				'M9.27 15.29l7.978-5.897c.391-.29.95-.177 1.137.272.98 2.369.542 5.215-1.41 7.169-1.951 1.954-4.667 2.382-7.149 1.406l-2.711 1.257c3.889 2.661 8.611 2.003 11.562-.953 2.341-2.344 3.066-5.539 2.388-8.42l.006.007c-.983-4.232.242-5.924 2.75-9.383.06-.082.12-.164.179-.248l-3.301 3.305v-.01L9.267 15.292M7.623 16.723c-2.792-2.67-2.31-6.801.071-9.184 1.761-1.763 4.647-2.483 7.166-1.425l2.705-1.25a7.808 7.808 0 00-1.829-1A8.975 8.975 0 005.984 5.83c-2.533 2.536-3.33 6.436-1.962 9.764 1.022 2.487-.653 4.246-2.34 6.022-.599.63-1.199 1.259-1.682 1.925l7.62-6.815'
			)
		),

	groq: () =>
		svg(
			'0 0 24 24',
			path(
				'M12.036 2c-3.853-.035-7 3-7.036 6.781-.035 3.782 3.055 6.872 6.908 6.907h2.42v-2.566h-2.292c-2.407.028-4.38-1.866-4.408-4.23-.029-2.362 1.901-4.298 4.308-4.326h.1c2.407 0 4.358 1.915 4.365 4.278v6.305c0 2.342-1.944 4.25-4.323 4.279a4.375 4.375 0 01-3.033-1.252l-1.851 1.818A7 7 0 0012.029 22h.092c3.803-.056 6.858-3.083 6.879-6.816v-6.5C18.907 4.963 15.817 2 12.036 2z'
			)
		),

	huggingface: () =>
		h(
			'svg',
			{
				width: '40',
				height: '40',
				viewBox: '0 0 24 24',
				fill: 'none',
				xmlns: 'http://www.w3.org/2000/svg',
			},
			path(
				'M2.25 11.535c0-3.407 1.847-6.554 4.844-8.258a9.822 9.822 0 019.687 0c2.997 1.704 4.844 4.851 4.844 8.258 0 5.266-4.337 9.535-9.687 9.535S2.25 16.8 2.25 11.535z',
				{ fill: '#FF9D0B' }
			),
			path(
				'M11.938 20.086c4.797 0 8.687-3.829 8.687-8.551 0-4.722-3.89-8.55-8.687-8.55-4.798 0-8.688 3.828-8.688 8.55 0 4.722 3.89 8.55 8.688 8.55z',
				{ fill: '#FFD21E' }
			),
			path(
				'M11.875 15.113c2.457 0 3.25-2.156 3.25-3.263 0-.576-.393-.394-1.023-.089-.582.283-1.365.675-2.224.675-1.798 0-3.25-1.693-3.25-.586 0 1.107.79 3.263 3.25 3.263h-.003z',
				{ fill: '#FF323D' }
			),
			path(
				'M14.76 9.21c.32.108.445.753.767.585.447-.233.707-.708.659-1.204a1.235 1.235 0 00-.879-1.059 1.262 1.262 0 00-1.33.394c-.322.384-.377.92-.14 1.36.153.283.638-.177.925-.079l-.002.003zm-5.887 0c-.32.108-.448.753-.768.585a1.226 1.226 0 01-.658-1.204c.048-.495.395-.913.878-1.059a1.262 1.262 0 011.33.394c.322.384.377.92.14 1.36-.152.283-.64-.177-.925-.079l.003.003zm1.12 5.34a2.166 2.166 0 011.325-1.106c.07-.02.144.06.219.171l.192.306c.069.1.139.175.209.175.074 0 .15-.074.223-.172l.205-.302c.08-.11.157-.188.234-.165.537.168.986.536 1.25 1.026.932-.724 1.275-1.905 1.275-2.633 0-.508-.306-.426-.81-.19l-.616.296c-.52.24-1.148.48-1.824.48-.676 0-1.302-.24-1.823-.48l-.589-.283c-.52-.248-.838-.342-.838.177 0 .703.32 1.831 1.187 2.56l.18.14z',
				{ fill: '#3A3B45' }
			)
		),

	ollama: () =>
		svg(
			'0 0 24 24',
			path(
				'M7.905 1.09c.216.085.411.225.588.41.295.306.544.744.734 1.263.191.522.315 1.1.362 1.68a5.054 5.054 0 012.049-.636l.051-.004c.87-.07 1.73.087 2.48.474.101.053.2.11.297.17.05-.569.172-1.134.36-1.644.19-.52.439-.957.733-1.264a1.67 1.67 0 01.589-.41c.257-.1.53-.118.796-.042.401.114.745.368 1.016.737.248.337.434.769.561 1.287.23.934.27 2.163.115 3.645l.053.04.026.019c.757.576 1.284 1.397 1.563 2.35.435 1.487.216 3.155-.534 4.088l-.018.021.002.003c.417.762.67 1.567.724 2.4l.002.03c.064 1.065-.2 2.137-.814 3.19l-.007.01.01.024c.472 1.157.62 2.322.438 3.486l-.006.039a.651.651 0 01-.747.536.648.648 0 01-.54-.742c.167-1.033.01-2.069-.48-3.123a.643.643 0 01.04-.617l.004-.006c.604-.924.854-1.83.8-2.72-.046-.779-.325-1.544-.8-2.273a.644.644 0 01.18-.886l.009-.006c.243-.159.467-.565.58-1.12a4.229 4.229 0 00-.095-1.974c-.205-.7-.58-1.284-1.105-1.683-.595-.454-1.383-.673-2.38-.61a.653.653 0 01-.632-.371c-.314-.665-.772-1.141-1.343-1.436a3.288 3.288 0 00-1.772-.332c-1.245.099-2.343.801-2.67 1.686a.652.652 0 01-.61.425c-1.067.002-1.893.252-2.497.703-.522.39-.878.935-1.066 1.588a4.07 4.07 0 00-.068 1.886c.112.558.331 1.02.582 1.269l.008.007c.212.207.257.53.109.785-.36.622-.629 1.549-.673 2.44-.05 1.018.186 1.902.719 2.536l.016.019a.643.643 0 01.095.69c-.576 1.236-.753 2.252-.562 3.052a.652.652 0 01-1.269.298c-.243-1.018-.078-2.184.473-3.498l.014-.035-.008-.012a4.339 4.339 0 01-.598-1.309l-.005-.019a5.764 5.764 0 01-.177-1.785c.044-.91.278-1.842.622-2.59l.012-.026-.002-.002c-.293-.418-.51-.953-.63-1.545l-.005-.024a5.352 5.352 0 01.093-2.49c.262-.915.777-1.701 1.536-2.269.06-.045.123-.09.186-.132-.159-1.493-.119-2.73.112-3.67.127-.518.314-.95.562-1.287.27-.368.614-.622 1.015-.737.266-.076.54-.059.797.042zm4.116 9.09c.936 0 1.8.313 2.446.855.63.527 1.005 1.235 1.005 1.94 0 .888-.406 1.58-1.133 2.022-.62.375-1.451.557-2.403.557-1.009 0-1.871-.259-2.493-.734-.617-.47-.963-1.13-.963-1.845 0-.707.398-1.417 1.056-1.946.668-.537 1.55-.849 2.485-.849zm0 .896a3.07 3.07 0 00-1.916.65c-.461.37-.722.835-.722 1.25 0 .428.21.829.61 1.134.455.347 1.124.548 1.943.548.799 0 1.473-.147 1.932-.426.463-.28.7-.686.7-1.257 0-.423-.246-.89-.683-1.256-.484-.405-1.14-.643-1.864-.643zm.662 1.21l.004.004c.12.151.095.37-.056.49l-.292.23v.446a.375.375 0 01-.376.373.375.375 0 01-.376-.373v-.46l-.271-.218a.347.347 0 01-.052-.49.353.353 0 01.494-.051l.215.172.22-.174a.353.353 0 01.49.051zm-5.04-1.919c.478 0 .867.39.867.871a.87.87 0 01-.868.871.87.87 0 01-.867-.87.87.87 0 01.867-.872zm8.706 0c.48 0 .868.39.868.871a.87.87 0 01-.868.871.87.87 0 01-.867-.87.87.87 0 01.867-.872zM7.44 2.3l-.003.002a.659.659 0 00-.285.238l-.005.006c-.138.189-.258.467-.348.832-.17.692-.216 1.631-.124 2.782.43-.128.899-.208 1.404-.237l.01-.001.019-.034c.046-.082.095-.161.148-.239.123-.771.022-1.692-.253-2.444-.134-.364-.297-.65-.453-.813a.628.628 0 00-.107-.09L7.44 2.3zm9.174.04l-.002.001a.628.628 0 00-.107.09c-.156.163-.32.45-.453.814-.29.794-.387 1.776-.23 2.572l.058.097.008.014h.03a5.184 5.184 0 011.466.212c.086-1.124.038-2.043-.128-2.722-.09-.365-.21-.643-.349-.832l-.004-.006a.659.659 0 00-.285-.239h-.004z',
				{ fillRule: 'evenodd' }
			)
		),

	openrouter: () =>
		svg(
			'0 0 24 24',
			path(
				'M16.804 1.957l7.22 4.105v.087L16.73 10.21l.017-2.117-.821-.03c-1.059-.028-1.611.002-2.268.11-1.064.175-2.038.577-3.147 1.352L8.345 11.03c-.284.195-.495.336-.68.455l-.515.322-.397.234.385.23.53.338c.476.314 1.17.796 2.701 1.866 1.11.775 2.083 1.177 3.147 1.352l.3.045c.694.091 1.375.094 2.825.033l.022-2.159 7.22 4.105v.087L16.589 22l.014-1.862-.635.022c-1.386.042-2.137.002-3.138-.162-1.694-.28-3.26-.926-4.881-2.059l-2.158-1.5a21.997 21.997 0 00-.755-.498l-.467-.28a55.927 55.927 0 00-.76-.43C2.908 14.73.563 14.116 0 14.116V9.888l.14.004c.564-.007 2.91-.622 3.809-1.124l1.016-.58.438-.274c.428-.28 1.072-.726 2.686-1.853 1.621-1.133 3.186-1.78 4.881-2.059 1.152-.19 1.974-.213 3.814-.138l.02-1.907z',
				{ fillRule: 'evenodd' }
			)
		),
};

/* ──────────────────────────────────────────────
 * Shared UI helpers
 * ────────────────────────────────────────────── */

function ConnectedBadge() {
	return h(
		'span',
		{
			style: {
				color: '#345b37',
				backgroundColor: '#eff8f0',
				padding: '4px 12px',
				borderRadius: '2px',
				fontSize: '13px',
				fontWeight: 500,
				whiteSpace: 'nowrap',
			},
		},
		__( 'Connected' )
	);
}

/* ──────────────────────────────────────────────
 * Cloudflare custom settings (Account ID + API Key)
 * ────────────────────────────────────────────── */

function CloudflareConnectorSettings( {
	onSave,
	onRemove,
	initialApiKey = '',
	initialAccountId = '',
	helpUrl,
	helpLabel,
	readOnly = false,
	accountIdSettingName,
} ) {
	const [ apiKey, setApiKey ] = useState( initialApiKey );
	const [ accountId, setAccountId ] = useState( initialAccountId );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );

	const helpLinkLabel = helpLabel || helpUrl?.replace( /^https?:\/\//, '' );

	const handleSave = async () => {
		setSaveError( null );
		setIsSaving( true );
		try {
			await onSave?.( apiKey, accountId );
		} catch ( error ) {
			setSaveError(
				error instanceof Error
					? error.message
					: __(
							'It was not possible to connect to the provider using this key.'
					  )
			);
		} finally {
			setIsSaving( false );
		}
	};

	const getHelp = () => {
		if ( readOnly ) {
			return h(
				window.wp.element.Fragment,
				null,
				__(
					'Your API key is stored securely. You can reset it at'
				),
				' ',
				helpUrl
					? h( ExternalLink, { href: helpUrl }, helpLinkLabel )
					: null
			);
		}
		if ( saveError ) {
			return h(
				'span',
				{ style: { color: '#cc1818' } },
				saveError
			);
		}
		if ( helpUrl ) {
			return h(
				window.wp.element.Fragment,
				null,
				__( 'Get your API key at' ),
				' ',
				h( ExternalLink, { href: helpUrl }, helpLinkLabel )
			);
		}
		return undefined;
	};

	return h(
		VStack,
		{
			spacing: 4,
			className: 'connector-settings',
			style: readOnly
				? { '--wp-components-color-background': '#f0f0f0' }
				: undefined,
		},
		h( TextControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: __( 'Account ID' ),
			value: accountId,
			onChange: ( v ) => {
				if ( ! readOnly ) {
					setSaveError( null );
					setAccountId( v );
				}
			},
			placeholder: 'YOUR_ACCOUNT_ID',
			disabled: readOnly || isSaving,
			help: __(
				'Found in the Cloudflare dashboard under Workers & Pages.'
			),
		} ),
		h( TextControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: __( 'API Key' ),
			value: apiKey,
			onChange: ( v ) => {
				if ( ! readOnly ) {
					setSaveError( null );
					setApiKey( v );
				}
			},
			placeholder: 'YOUR_API_KEY',
			disabled: readOnly || isSaving,
			help: getHelp(),
		} ),
		readOnly
			? h(
					Button,
					{
						variant: 'link',
						isDestructive: true,
						onClick: onRemove,
					},
					__( 'Remove and replace' )
			  )
			: h(
					HStack,
					{ justify: 'flex-start' },
					h(
						Button,
						{
							__next40pxDefaultSize: true,
							variant: 'primary',
							disabled: ! apiKey || ! accountId || isSaving,
							accessibleWhenDisabled: true,
							isBusy: isSaving,
							onClick: handleSave,
						},
						__( 'Save' )
					)
			  )
	);
}

/* ──────────────────────────────────────────────
 * Ollama custom settings (Endpoint URL only, no API key)
 * ────────────────────────────────────────────── */

function OllamaConnectorSettings( {
	onSave,
	onRemove,
	initialEndpoint = '',
	helpUrl,
	helpLabel,
	readOnly = false,
} ) {
	const [ endpoint, setEndpoint ] = useState( initialEndpoint );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );

	const handleSave = async () => {
		setSaveError( null );
		setIsSaving( true );
		try {
			await onSave?.( endpoint );
		} catch ( error ) {
			setSaveError(
				error instanceof Error
					? error.message
					: __( 'Could not save the endpoint.' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	const getHelp = () => {
		if ( readOnly ) {
			return __( 'Your endpoint is configured.' );
		}
		if ( saveError ) {
			return h( 'span', { style: { color: '#cc1818' } }, saveError );
		}
		return __(
			'Enter the URL where Ollama is running. Default is http://localhost:11434'
		);
	};

	return h(
		VStack,
		{
			spacing: 4,
			className: 'connector-settings',
			style: readOnly
				? { '--wp-components-color-background': '#f0f0f0' }
				: undefined,
		},
		h( TextControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: __( 'Endpoint URL' ),
			value: endpoint,
			onChange: ( v ) => {
				if ( ! readOnly ) {
					setSaveError( null );
					setEndpoint( v );
				}
			},
			placeholder: 'http://localhost:11434',
			disabled: readOnly || isSaving,
			help: getHelp(),
		} ),
		readOnly
			? h(
					Button,
					{
						variant: 'link',
						isDestructive: true,
						onClick: onRemove,
					},
					__( 'Remove and replace' )
			  )
			: h(
					HStack,
					{ justify: 'flex-start' },
					h(
						Button,
						{
							__next40pxDefaultSize: true,
							variant: 'primary',
							disabled: ! endpoint || isSaving,
							accessibleWhenDisabled: true,
							isBusy: isSaving,
							onClick: handleSave,
						},
						__( 'Save' )
					)
			  )
	);
}

/* ──────────────────────────────────────────────
 * Generic extended provider connector component
 * ────────────────────────────────────────────── */

function ExtendedProviderConnector( { label, description, slug } ) {
	const provider = providers.find(
		( p ) => 'ai-experiments/' + p.id === slug
	);
	if ( ! provider ) {
		return null;
	}

	const { id, settingName, helpUrl, helpLabel, type } = provider;
	const isCloudflare = id === 'cloudflare';
	const isEndpoint = type === 'endpoint';
	const accountIdSetting = isCloudflare ? 'ai_cloudflare_account_id' : null;

	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ currentValue, setCurrentValue ] = useState( '' );
	const [ currentAccountId, setCurrentAccountId ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );

	const isConnected =
		currentValue !== '' && currentValue !== 'invalid_key';

	const fetchValue = useCallback( async () => {
		try {
			let fields = settingName;
			if ( accountIdSetting ) {
				fields += ',' + accountIdSetting;
			}
			const settings = await apiFetch( {
				path: '/wp/v2/settings?_fields=' + fields,
			} );
			const val = settings[ settingName ] || '';
			setCurrentValue( val === 'invalid_key' ? '' : val );
			if ( accountIdSetting ) {
				setCurrentAccountId( settings[ accountIdSetting ] || '' );
			}
		} catch ( e ) {
			// Setting may not be registered yet.
		}
		setIsLoading( false );
	}, [ settingName, accountIdSetting ] );

	useEffect( () => {
		fetchValue();
	}, [ fetchValue ] );

	const saveValue = async ( value, accountId ) => {
		const data = { [ settingName ]: value };
		let fields = settingName;
		if ( accountIdSetting && accountId !== undefined ) {
			data[ accountIdSetting ] = accountId;
			fields += ',' + accountIdSetting;
		}
		const result = await apiFetch( {
			method: 'POST',
			path: '/wp/v2/settings?_fields=' + fields,
			data,
		} );
		// If the key was submitted but the response is empty, the save failed.
		if ( ! isEndpoint && value && ! result[ settingName ] ) {
			throw new Error(
				__(
					'It was not possible to save the API key.'
				)
			);
		}
		setCurrentValue( result[ settingName ] || '' );
		if ( accountIdSetting ) {
			setCurrentAccountId( result[ accountIdSetting ] || '' );
		}
	};

	const removeValue = async () => {
		const data = { [ settingName ]: '' };
		let fields = settingName;
		if ( accountIdSetting ) {
			data[ accountIdSetting ] = '';
			fields += ',' + accountIdSetting;
		}
		await apiFetch( {
			method: 'POST',
			path: '/wp/v2/settings?_fields=' + fields,
			data,
		} );
		setCurrentValue( '' );
		setCurrentAccountId( '' );
	};

	const handleButtonClick = () => setIsExpanded( ! isExpanded );

	const getButtonLabel = () => {
		if ( isLoading ) {
			return __( 'Checking\u2026' );
		}
		if ( isExpanded ) {
			return __( 'Cancel' );
		}
		if ( isConnected ) {
			return __( 'Edit' );
		}
		return __( 'Set up' );
	};

	const IconComponent = ICONS[ id ];

	const renderSettings = () => {
		if ( ! isExpanded ) {
			return null;
		}

		if ( isEndpoint ) {
			return h( OllamaConnectorSettings, {
				key: isConnected ? 'connected' : 'setup',
				initialEndpoint: currentValue,
				helpUrl,
				helpLabel,
				readOnly: isConnected,
				onRemove: removeValue,
				onSave: async ( endpoint ) => {
					await saveValue( endpoint );
					setIsExpanded( false );
				},
			} );
		}

		if ( isCloudflare ) {
			return h( CloudflareConnectorSettings, {
				key: isConnected ? 'connected' : 'setup',
				initialApiKey: currentValue,
				initialAccountId: currentAccountId,
				helpUrl,
				helpLabel,
				readOnly: isConnected,
				accountIdSettingName: accountIdSetting,
				onRemove: removeValue,
				onSave: async ( apiKey, accountId ) => {
					await saveValue( apiKey, accountId );
					setIsExpanded( false );
				},
			} );
		}

		return h( DefaultConnectorSettings, {
			key: isConnected ? 'connected' : 'setup',
			initialValue: currentValue,
			helpUrl,
			helpLabel,
			readOnly: isConnected,
			onRemove: removeValue,
			onSave: async ( apiKey ) => {
				await saveValue( apiKey );
				setIsExpanded( false );
			},
		} );
	};

	return h(
		ConnectorItem,
		{
			icon: IconComponent ? h( IconComponent ) : undefined,
			name: label,
			description,
			actionArea: h(
				HStack,
				{ spacing: 3, expanded: false },
				isConnected && h( ConnectedBadge ),
				h(
					Button,
					{
						variant:
							isExpanded || isConnected
								? 'tertiary'
								: 'secondary',
						size:
							isExpanded || isConnected
								? undefined
								: 'compact',
						onClick: handleButtonClick,
						disabled: isLoading,
						'aria-expanded': isExpanded,
					},
					getButtonLabel()
				)
			),
		},
		renderSettings()
	);
}

/* ──────────────────────────────────────────────
 * Registration – deferred so core's 3 defaults register first.
 * ────────────────────────────────────────────── */

/**
 * Defer registration until after core's route modules have loaded.
 *
 * Core's connectors-home/content.js registers the 3 default connectors
 * (OpenAI, Claude, Gemini) during route module initialisation. We use
 * wp.domReady + requestAnimationFrame to ensure the Redux store already
 * contains the defaults, so our extended providers appear below them
 * in insertion order.
 */
window.wp.domReady( () => {
	requestAnimationFrame( () => {
		providers.forEach( ( provider ) => {
			registerConnector( 'ai-experiments/' + provider.id, {
				label: provider.label,
				description: provider.description,
				render: ExtendedProviderConnector,
			} );
		} );
	} );
} );
