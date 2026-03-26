import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const data = window.ContentProvenanceData || {};

// ── Badge component ──────────────────────────────────────────────────────────

const BADGE_CONFIG = {
	verified: {
		color: '#00a32a',
		fill: '#d7f0de',
		icon: '✓',
		label: __( 'Signed — Identity Verified', 'ai' ),
	},
	local_signed: {
		color: '#2271b1',
		fill: '#e8f3fb',
		icon: '◈',
		label: __( 'Signed — Content Integrity Verified', 'ai' ),
	},
	modified: {
		color: '#dba617',
		fill: '#fcf9e8',
		icon: '⚠',
		label: __( 'Modified Since Signing', 'ai' ),
	},
	tampered: {
		color: '#cc1818',
		fill: '#fce8e8',
		icon: '✗',
		label: __( 'Tamper Detected', 'ai' ),
	},
	unsigned: {
		color: '#8c8f94',
		fill: '#f6f7f7',
		icon: '○',
		label: __( 'Not Signed', 'ai' ),
	},
	loading: {
		color: '#8c8f94',
		fill: '#f6f7f7',
		icon: '…',
		label: __( 'Checking…', 'ai' ),
	},
};

const ShieldBadge = ( { status } ) => {
	const cfg = BADGE_CONFIG[ status ] || BADGE_CONFIG.unsigned;
	return (
		<div
			style={ {
				display: 'flex',
				alignItems: 'center',
				gap: '8px',
				padding: '10px 12px',
				marginBottom: '12px',
				background: cfg.fill,
				border: `1px solid ${ cfg.color }`,
				borderRadius: '4px',
				width: '100%',
				boxSizing: 'border-box',
			} }
		>
			<svg
				viewBox="0 0 24 24"
				width="20"
				height="20"
				fill="none"
				stroke={ cfg.color }
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
				aria-hidden="true"
			>
				<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
				{ status === 'verified' && <path d="M9 12l2 2 4-4" /> }
				{ status === 'tampered' && (
					<>
						<line x1="15" y1="9" x2="9" y2="15" />
						<line x1="9" y1="9" x2="15" y2="15" />
					</>
				) }
			</svg>
			<span
				style={ {
					fontSize: '13px',
					fontWeight: '500',
					color: cfg.color,
				} }
			>
				{ cfg.label }
			</span>
		</div>
	);
};

// ── Trust tier notice ────────────────────────────────────────────────────────

const TrustTierNotice = ( { tier, settingsUrl } ) => {
	if ( 'local' !== tier ) {
		return null;
	}
	return (
		<Notice
			status="info"
			isDismissible={ false }
			style={ { marginBottom: '12px' } }
		>
			{ __(
				'Signed with local key. Content integrity is verifiable but signer identity is not on the C2PA Trust List.',
				'ai'
			) }
			{ settingsUrl && (
				<a
					href={ settingsUrl }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Connect a signing service →', 'ai' ) }
				</a>
			) }
		</Notice>
	);
};

// ── Main panel ───────────────────────────────────────────────────────────────

const ContentProvenancePanel = () => {
	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const [ status, setStatus ] = useState( 'loading' );
	const [ signedAt, setSignedAt ] = useState( null );
	const [ signerTier, setSignerTier ] = useState(
		data.signerTier || 'local'
	);
	const [ verifyResult, setVerifyResult ] = useState( null );
	const [ isSigning, setIsSigning ] = useState( false );
	const [ isVerifying, setIsVerifying ] = useState( false );
	const [ error, setError ] = useState( '' );

	const fetchStatus = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setStatus( 'loading' );
		apiFetch( {
			url: `${ data.restUrl }/status?post_id=${ postId }`,
			headers: { 'X-WP-Nonce': data.nonce },
		} )
			.then( ( res ) => {
				setStatus( res.status || 'unsigned' );
				setSignedAt( res.signed_at || null );
				setSignerTier( res.signer_tier || data.signerTier || 'local' );
				setError( '' );
			} )
			.catch( () => {
				setStatus( 'unsigned' );
				setError( '' );
			} );
	}, [ postId ] );

	useEffect( () => {
		fetchStatus();
	}, [ fetchStatus ] );

	const handleSign = () => {
		if ( isSigning ) {
			return;
		}
		setIsSigning( true );
		setError( '' );
		apiFetch( {
			path: `wp-abilities/v1/abilities/ai/content-provenance/run`,
			method: 'POST',
			headers: { 'X-WP-Nonce': data.nonce },
			data: { post_id: postId },
		} )
			.then( () => {
				setIsSigning( false );
				fetchStatus();
			} )
			.catch( ( err ) => {
				setIsSigning( false );
				setError( err?.message || __( 'Signing failed.', 'ai' ) );
			} );
	};

	const handleVerify = () => {
		if ( isVerifying ) {
			return;
		}
		setIsVerifying( true );
		setVerifyResult( null );
		apiFetch( {
			url: `${ data.restUrl }/status?post_id=${ postId }`,
			headers: { 'X-WP-Nonce': data.nonce },
		} )
			.then( ( res ) => {
				setVerifyResult( res );
				setIsVerifying( false );
				// Update badge based on verification.
				if ( res.status ) {
					setStatus( res.status );
				}
			} )
			.catch( () => {
				setIsVerifying( false );
				setError( __( 'Verification failed.', 'ai' ) );
			} );
	};

	if ( ! data.enabled ) {
		return (
			<PluginDocumentSettingPanel
				name="content-provenance-panel"
				title={ __( 'Content Provenance', 'ai' ) }
				className="content-provenance-panel"
				initialOpen={ false }
			>
				<p style={ { fontSize: '13px', color: '#646970' } }>
					{ __(
						'Enable the Content Provenance experiment in AI Experiments settings to add C2PA provenance to published content.',
						'ai'
					) }
				</p>
			</PluginDocumentSettingPanel>
		);
	}

	const chainInfo = signedAt ? (
		<p
			style={ {
				fontSize: '12px',
				color: '#646970',
				margin: '8px 0 0',
			} }
		>
			{ __( 'Last signed:', 'ai' ) } { signedAt }
			{ signerTier && <> &middot; { signerTier }</> }
		</p>
	) : null;

	return (
		<PluginDocumentSettingPanel
			name="content-provenance-panel"
			title={ __( 'Content Provenance', 'ai' ) }
			className="content-provenance-panel"
			initialOpen={ true }
		>
			<ShieldBadge status={ status === 'loading' ? 'loading' : status } />
			<TrustTierNotice
				tier={ signerTier }
				settingsUrl={ data.settingsUrl }
			/>
			{ chainInfo }
			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					style={ { marginTop: '8px' } }
				>
					{ error }
				</Notice>
			) }
			{ verifyResult && (
				<Notice
					status={ verifyResult.verified ? 'success' : 'warning' }
					isDismissible
					onRemove={ () => setVerifyResult( null ) }
					style={ { marginTop: '8px' } }
				>
					{ verifyResult.verified
						? __(
								'Content integrity confirmed — no tampering detected.',
								'ai'
						  )
						: __( 'Verification result:', 'ai' ) +
						  ( verifyResult.status || 'unknown' ) }
				</Notice>
			) }
			<div
				style={ {
					display: 'flex',
					gap: '8px',
					marginTop: '12px',
				} }
			>
				<Button
					variant="primary"
					isSmall
					onClick={ handleSign }
					disabled={ isSigning }
				>
					{ isSigning ? <Spinner /> : __( 'Sign Now', 'ai' ) }
				</Button>
				<Button
					variant="secondary"
					isSmall
					onClick={ handleVerify }
					disabled={ isVerifying || 'unsigned' === status }
				>
					{ isVerifying ? <Spinner /> : __( 'Verify', 'ai' ) }
				</Button>
			</div>
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'content-provenance', {
	render: ContentProvenancePanel,
	icon: 'shield',
} );
