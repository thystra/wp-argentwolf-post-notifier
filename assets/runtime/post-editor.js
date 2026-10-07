/**
 * File: assets/runtime/post-editor.js
 *
 * Human-readable post-editor sidebar for notification configuration.
 */

/**
 * Register the post-notification editor sidebar.
 *
 * @param {Object}   plugins    WordPress plugins package.
 * @param {Object}   editor     WordPress editor package.
 * @param {Object}   components WordPress components package.
 * @param {Object}   data       WordPress data package.
 * @param {Object}   element    WordPress element package.
 * @param {Object}   i18n       WordPress internationalization package.
 * @param {Function} apiFetch   WordPress REST API fetch helper.
 * @param {Object}   settings   Server-provided persistence contract.
 */
( function (
	plugins,
	editor,
	components,
	data,
	element,
	i18n,
	apiFetch,
	settings
) {
	if (
		! settings ||
		! settings.metaKeys ||
		! settings.values ||
		! settings.audience ||
		! settings.contactLookup
	) {
		return;
	}

	const { registerPlugin } = plugins;
	const { PluginSidebar } = editor;
	const { Button, CheckboxControl, PanelBody, SelectControl, TextControl } =
		components;
	const { useDispatch, useSelect } = data;
	const { createElement, useEffect, useState } = element;
	const { __, sprintf } = i18n;
	const {
		metaKeys,
		values,
		audience: audienceChoices,
		contactLookup,
	} = settings;

	/**
	 * Count one saved audience-selection array defensively.
	 *
	 * @param {*} value Candidate array value.
	 * @return {number} Number of selected identifiers.
	 */
	function selectionCount( value ) {
		return Array.isArray( value ) ? value.length : 0;
	}

	/**
	 * Return one audience-selection array defensively.
	 *
	 * @param {*} value Candidate selection value.
	 * @return {Array} Canonical array-like editor value.
	 */
	function selectionValues( value ) {
		return Array.isArray( value ) ? value : [];
	}

	/**
	 * Normalize the six canonical audience-selection buckets.
	 *
	 * @param {*} audience Saved audience configuration.
	 * @return {Object} Canonical editor audience object.
	 */
	function normalizedAudience( audience ) {
		const config = audience && typeof audience === 'object' ? audience : {};

		return {
			role_slugs: selectionValues( config.role_slugs ),
			named_list_ids: selectionValues( config.named_list_ids ),
			included_user_ids: selectionValues( config.included_user_ids ),
			included_subscriber_ids: selectionValues(
				config.included_subscriber_ids
			),
			excluded_user_ids: selectionValues( config.excluded_user_ids ),
			excluded_subscriber_ids: selectionValues(
				config.excluded_subscriber_ids
			),
		};
	}

	/**
	 * Toggle one selected role or list value without mutating editor state.
	 *
	 * @param {Array}   current Existing selection.
	 * @param {*}       value   Selection value.
	 * @param {boolean} checked Whether the value should be selected.
	 *
	 * @return {Array} Updated selection.
	 */
	function toggledSelection( current, value, checked ) {
		const next = selectionValues( current ).filter(
			( candidate ) => candidate !== value
		);
		if ( checked ) {
			next.push( value );
		}

		return next;
	}

	/**
	 * Render one checkbox group for an audience-source type.
	 *
	 * @param {string}   title     Group label.
	 * @param {string}   emptyText Empty-state copy.
	 * @param {Array}    choices   Server-provided choices.
	 * @param {Array}    selected  Currently selected values.
	 * @param {Function} onToggle  Selection callback.
	 * @param {string}   keyPrefix React key prefix.
	 * @return {Object} Fieldset element.
	 */
	function choiceFieldset(
		title,
		emptyText,
		choices,
		selected,
		onToggle,
		keyPrefix
	) {
		const controls = choices.map( ( choice ) =>
			createElement( CheckboxControl, {
				key: `${ keyPrefix }-${ choice.value }`,
				label: choice.label,
				checked: selected.includes( choice.value ),
				onChange: ( checked ) => onToggle( choice.value, checked ),
			} )
		);

		return createElement(
			'fieldset',
			null,
			createElement( 'legend', null, title ),
			controls.length > 0
				? controls
				: createElement( 'p', null, emptyText )
		);
	}

	/**
	 * Return one contact-lookup request path.
	 *
	 * @param {number} postId Editor post ID.
	 * @param {string} type   Contact type.
	 * @param {string} email  Optional exact email.
	 * @param {Array}  ids    Optional contact IDs to hydrate.
	 * @return {string} REST request path.
	 */
	function contactRequestPath( postId, type, email = '', ids = [] ) {
		let path = `${ contactLookup.path }?post_id=${ encodeURIComponent(
			postId
		) }&type=${ encodeURIComponent( type ) }`;

		if ( email !== '' ) {
			path += '&lookup=email';
		}
		if ( ids.length > 0 ) {
			path += '&lookup=include';
		}

		return path;
	}

	/**
	 * Return deterministic unique positive IDs from two selection buckets.
	 *
	 * @param {Array} first  First ID bucket.
	 * @param {Array} second Second ID bucket.
	 * @return {Array} Unique sorted IDs.
	 */
	function combinedIds( first, second ) {
		return Array.from(
			new Set(
				[ ...selectionValues( first ), ...selectionValues( second ) ]
					.map( ( value ) => Number( value ) )
					.filter(
						( value ) => Number.isInteger( value ) && value > 0
					)
			)
		).sort( ( left, right ) => left - right );
	}

	/**
	 * Render an exact-email selector for one typed individual-contact source.
	 *
	 * Directory browsing is intentionally unavailable. Exact-email lookup returns
	 * only a masked address and display label. Saved IDs are hydrated in bounded
	 * batches so a sender can review and remove existing selections after reload.
	 *
	 * @param {Object} props Selector properties.
	 * @return {Object} Contact-selector element.
	 */
	function ContactSelector( props ) {
		const {
			title,
			type,
			postId,
			includeField,
			excludeField,
			audience,
			updateAudience,
		} = props;
		const [ email, setEmail ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const [ labels, setLabels ] = useState( {} );
		const [ message, setMessage ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const included = selectionValues( audience[ includeField ] );
		const excluded = selectionValues( audience[ excludeField ] );
		const selectedKey = combinedIds( included, excluded ).join( ',' );

		useEffect( () => {
			if ( postId < 1 || selectedKey === '' ) {
				setLabels( {} );
				return undefined;
			}

			let cancelled = false;
			const ids = selectedKey
				.split( ',' )
				.map( ( value ) => Number( value ) )
				.filter( ( value ) => Number.isInteger( value ) && value > 0 );
			const batches = [];

			for ( let offset = 0; offset < ids.length; offset += 100 ) {
				batches.push( ids.slice( offset, offset + 100 ) );
			}

			Promise.all(
				batches.map( ( batch ) =>
					apiFetch( {
						path: contactRequestPath( postId, type, '', batch ),
						method: 'POST',
						data: { include: batch.join( ',' ) },
					} )
				)
			)
				.then( ( responses ) => {
					if ( cancelled ) {
						return;
					}

					const next = {};
					responses.forEach( ( response ) => {
						const contacts = Array.isArray( response.contacts )
							? response.contacts
							: [];
						contacts.forEach( ( contact ) => {
							next[ contact.id ] = contact;
						} );
					} );
					setLabels( next );
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setLabels( {} );
					}
				} );

			return () => {
				cancelled = true;
			};
		}, [ postId, selectedKey, type ] );

		const lookup = () => {
			const candidate = email.trim();
			if ( postId < 1 || candidate === '' ) {
				return;
			}

			setBusy( true );
			setResult( null );
			setMessage( '' );

			apiFetch( {
				path: contactRequestPath( postId, type, candidate ),
				method: 'POST',
				data: { email: candidate },
			} )
				.then( ( response ) => {
					const contacts = Array.isArray( response.contacts )
						? response.contacts
						: [];
					const contact = contacts[ 0 ] || null;
					setResult( contact );
					setMessage(
						contact
							? ''
							: __(
									'No eligible contact matched that exact email address.',
									'argentwolf-post-notifier'
							  )
					);
				} )
				.catch( () => {
					setResult( null );
					setMessage(
						__(
							'Contact lookup failed. Reload the editor and try again.',
							'argentwolf-post-notifier'
						)
					);
				} )
				.finally( () => setBusy( false ) );
		};

		const moveContact = ( contact, destination ) => {
			const id = Number( contact.id );
			if ( ! Number.isInteger( id ) || id < 1 ) {
				return;
			}

			const other =
				destination === includeField ? excludeField : includeField;
			updateAudience( {
				[ destination ]: toggledSelection(
					audience[ destination ],
					id,
					true
				),
				[ other ]: toggledSelection( audience[ other ], id, false ),
			} );
			setLabels( ( current ) => ( {
				...current,
				[ id ]: contact,
			} ) );
		};

		const removeContact = ( field, id ) => {
			updateAudience( {
				[ field ]: toggledSelection( audience[ field ], id, false ),
			} );
		};

		const selectedRows = ( heading, ids, field ) =>
			createElement(
				'div',
				{ className: 'argentwolf-post-notifier-contact-selection' },
				createElement( 'strong', null, heading ),
				ids.length === 0
					? createElement(
							'p',
							null,
							__( 'None selected.', 'argentwolf-post-notifier' )
					  )
					: ids.map( ( id ) => {
							const contact = labels[ id ];
							const label = contact
								? `${ contact.label }${
										contact.detail
											? ` — ${ contact.detail }`
											: ''
								  }`
								: sprintf(
										/* translators: %d: stored contact ID. */
										__(
											'Contact #%d',
											'argentwolf-post-notifier'
										),
										id
								  );

							return createElement(
								'p',
								{ key: `${ field }-${ id }` },
								createElement( 'span', null, label ),
								' ',
								createElement(
									Button,
									{
										variant: 'link',
										onClick: () =>
											removeContact( field, id ),
									},
									__( 'Remove', 'argentwolf-post-notifier' )
								)
							);
					  } )
			);

		return createElement(
			'fieldset',
			{ className: 'argentwolf-post-notifier-contact-selector' },
			createElement( 'legend', null, title ),
			createElement( TextControl, {
				label: __( 'Exact email address', 'argentwolf-post-notifier' ),
				type: 'email',
				value: email,
				onChange: setEmail,
				help: __(
					'Exact-email lookup prevents browsing the contact directory.',
					'argentwolf-post-notifier'
				),
			} ),
			createElement(
				Button,
				{
					variant: 'secondary',
					disabled: busy || postId < 1 || email.trim() === '',
					onClick: lookup,
				},
				busy
					? __( 'Looking up…', 'argentwolf-post-notifier' )
					: __( 'Find contact', 'argentwolf-post-notifier' )
			),
			postId < 1
				? createElement(
						'p',
						null,
						__(
							'Save the draft before looking up individual contacts.',
							'argentwolf-post-notifier'
						)
				  )
				: null,
			message === '' ? null : createElement( 'p', null, message ),
			result
				? createElement(
						'div',
						{
							className:
								'argentwolf-post-notifier-contact-result',
						},
						createElement(
							'p',
							null,
							`${ result.label }${
								result.detail ? ` — ${ result.detail }` : ''
							}`
						),
						createElement(
							Button,
							{
								variant: 'secondary',
								disabled: result.selectable === false,
								onClick: () =>
									moveContact( result, includeField ),
							},
							__( 'Include', 'argentwolf-post-notifier' )
						),
						' ',
						createElement(
							Button,
							{
								variant: 'secondary',
								onClick: () =>
									moveContact( result, excludeField ),
							},
							__( 'Exclude', 'argentwolf-post-notifier' )
						)
				  )
				: null,
			selectedRows(
				__( 'Included', 'argentwolf-post-notifier' ),
				included,
				includeField
			),
			selectedRows(
				__( 'Excluded', 'argentwolf-post-notifier' ),
				excluded,
				excludeField
			)
		);
	}

	/**
	 * Build a privacy-safe summary of canonical audience selections.
	 *
	 * @param {*} audience Saved audience configuration.
	 * @return {string} Human-readable count summary.
	 */
	function audienceSummary( audience ) {
		const config = normalizedAudience( audience );
		const roles = selectionCount( config.role_slugs );
		const lists = selectionCount( config.named_list_ids );
		const inclusions =
			selectionCount( config.included_user_ids ) +
			selectionCount( config.included_subscriber_ids );
		const exclusions =
			selectionCount( config.excluded_user_ids ) +
			selectionCount( config.excluded_subscriber_ids );

		if ( roles + lists + inclusions + exclusions === 0 ) {
			return __(
				'No explicit audience selections saved.',
				'argentwolf-post-notifier'
			);
		}

		return sprintf(
			/* translators: 1: role count, 2: named-list count, 3: inclusion count, 4: exclusion count. */
			__(
				'%1$d role sources, %2$d named lists, %3$d explicit inclusions, %4$d exclusions.',
				'argentwolf-post-notifier'
			),
			roles,
			lists,
			inclusions,
			exclusions
		);
	}

	/**
	 * Render the persistent post-notification sidebar.
	 *
	 * @return {Object|null} Sidebar element or null outside supported posts.
	 */
	function PostNotificationSidebar() {
		const state = useSelect( ( select ) => {
			const editorStore = select( 'core/editor' );

			return {
				postId: Number( editorStore.getCurrentPostId() ) || 0,
				postType: editorStore.getCurrentPostType(),
				meta: editorStore.getEditedPostAttribute( 'meta' ) || {},
			};
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );

		if ( settings.postType !== state.postType ) {
			return null;
		}

		const meta = state.meta;
		const sendIntent =
			typeof meta[ metaKeys.sendIntent ] === 'string'
				? meta[ metaKeys.sendIntent ]
				: values.sendIntent.siteDefault;
		const contentMode =
			typeof meta[ metaKeys.contentMode ] === 'string'
				? meta[ metaKeys.contentMode ]
				: values.contentMode.siteDefault;
		const ctaText =
			typeof meta[ metaKeys.ctaText ] === 'string'
				? meta[ metaKeys.ctaText ]
				: '';
		const audience = normalizedAudience( meta[ metaKeys.audienceConfig ] );
		const roleChoices = Array.isArray( audienceChoices.roles )
			? audienceChoices.roles
			: [];
		const listChoices = Array.isArray( audienceChoices.lists )
			? audienceChoices.lists
			: [];

		const updateMeta = ( key, value ) => {
			editPost( { meta: { [ key ]: value } } );
		};
		const updateAudience = ( changes ) => {
			updateMeta( metaKeys.audienceConfig, {
				...audience,
				...changes,
			} );
		};
		const updateAudienceField = ( field, value ) => {
			updateAudience( { [ field ]: value } );
		};

		return createElement(
			PluginSidebar,
			{
				name: 'argentwolf-post-notifier-settings',
				title: __( 'Post Notifications', 'argentwolf-post-notifier' ),
				icon: 'email-alt',
			},
			createElement(
				PanelBody,
				{
					title: __( 'Delivery', 'argentwolf-post-notifier' ),
					initialOpen: true,
				},
				createElement( SelectControl, {
					label: __(
						'Notification intent',
						'argentwolf-post-notifier'
					),
					value: sendIntent,
					options: [
						{
							label: __(
								'Site default',
								'argentwolf-post-notifier'
							),
							value: values.sendIntent.siteDefault,
						},
						{
							label: __(
								'Send notification',
								'argentwolf-post-notifier'
							),
							value: values.sendIntent.send,
						},
						{
							label: __(
								'Do not send',
								'argentwolf-post-notifier'
							),
							value: values.sendIntent.doNotSend,
						},
					],
					onChange: ( value ) =>
						updateMeta( metaKeys.sendIntent, value ),
					help: __(
						'This setting records editorial intent only. It does not send email from the editor.',
						'argentwolf-post-notifier'
					),
				} )
			),
			createElement(
				PanelBody,
				{
					title: __( 'Email content', 'argentwolf-post-notifier' ),
					initialOpen: true,
				},
				createElement( SelectControl, {
					label: __( 'Content mode', 'argentwolf-post-notifier' ),
					value: contentMode,
					options: [
						{
							label: __(
								'Site default',
								'argentwolf-post-notifier'
							),
							value: values.contentMode.siteDefault,
						},
						{
							label: __( 'Excerpt', 'argentwolf-post-notifier' ),
							value: values.contentMode.excerpt,
						},
						{
							label: __(
								'Full post',
								'argentwolf-post-notifier'
							),
							value: values.contentMode.full,
						},
					],
					onChange: ( value ) =>
						updateMeta( metaKeys.contentMode, value ),
				} ),
				createElement( TextControl, {
					label: __(
						'Call-to-action override',
						'argentwolf-post-notifier'
					),
					value: ctaText,
					onChange: ( value ) =>
						updateMeta( metaKeys.ctaText, value ),
					help: __(
						'Leave blank to use the site default call-to-action text.',
						'argentwolf-post-notifier'
					),
				} )
			),
			createElement(
				PanelBody,
				{
					title: __( 'Audience', 'argentwolf-post-notifier' ),
					initialOpen: false,
				},
				createElement( 'p', null, audienceSummary( audience ) ),
				choiceFieldset(
					__( 'WordPress roles', 'argentwolf-post-notifier' ),
					__(
						'No selectable roles are available.',
						'argentwolf-post-notifier'
					),
					roleChoices,
					audience.role_slugs,
					( value, checked ) =>
						updateAudienceField(
							'role_slugs',
							toggledSelection(
								audience.role_slugs,
								value,
								checked
							)
						),
					'role'
				),
				choiceFieldset(
					__( 'Named lists', 'argentwolf-post-notifier' ),
					__(
						'No named lists are available.',
						'argentwolf-post-notifier'
					),
					listChoices,
					audience.named_list_ids,
					( value, checked ) =>
						updateAudienceField(
							'named_list_ids',
							toggledSelection(
								audience.named_list_ids,
								value,
								checked
							)
						),
					'list'
				),
				createElement( ContactSelector, {
					title: __(
						'Individual WordPress users',
						'argentwolf-post-notifier'
					),
					type: contactLookup.types.user,
					postId: state.postId,
					includeField: 'included_user_ids',
					excludeField: 'excluded_user_ids',
					audience,
					updateAudience,
				} ),
				createElement( ContactSelector, {
					title: __(
						'Individual standalone subscribers',
						'argentwolf-post-notifier'
					),
					type: contactLookup.types.subscriber,
					postId: state.postId,
					includeField: 'included_subscriber_ids',
					excludeField: 'excluded_subscriber_ids',
					audience,
					updateAudience,
				} )
			)
		);
	}

	registerPlugin( 'argentwolf-post-notifier', {
		render: PostNotificationSidebar,
		icon: 'email-alt',
	} );
} )(
	window.wp.plugins,
	window.wp.editor,
	window.wp.components,
	window.wp.data,
	window.wp.element,
	window.wp.i18n,
	window.wp.apiFetch,
	window.argentWolfPostNotifierEditor
);

// EOF: assets/runtime/post-editor.js
