/**
 * File: assets/runtime/post-editor.js
 *
 * Human-readable post-editor sidebar for notification configuration.
 */

/**
 * Register the post-notification editor sidebar.
 *
 * @param {Object} plugins    WordPress plugins package.
 * @param {Object} editor     WordPress editor package.
 * @param {Object} components WordPress components package.
 * @param {Object} data       WordPress data package.
 * @param {Object} element    WordPress element package.
 * @param {Object} i18n       WordPress internationalization package.
 * @param {Object} settings   Server-provided persistence contract.
 */
( function ( plugins, editor, components, data, element, i18n, settings ) {
	if ( ! settings || ! settings.metaKeys || ! settings.values ) {
		return;
	}

	const { registerPlugin } = plugins;
	const { PluginSidebar } = editor;
	const { PanelBody, SelectControl, TextControl } = components;
	const { useDispatch, useSelect } = data;
	const { createElement } = element;
	const { __, sprintf } = i18n;
	const { metaKeys, values } = settings;

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
	 * Build a privacy-safe summary of canonical audience selections.
	 *
	 * @param {*} audience Saved audience configuration.
	 * @return {string} Human-readable count summary.
	 */
	function audienceSummary( audience ) {
		const config = audience && typeof audience === 'object' ? audience : {};
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

		const updateMeta = ( key, value ) => {
			editPost( { meta: { [ key ]: value } } );
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
				createElement(
					'p',
					null,
					audienceSummary( meta[ metaKeys.audienceConfig ] )
				),
				createElement(
					'p',
					null,
					__(
						'Detailed audience editing will be added in a later Beta.1 tranche.',
						'argentwolf-post-notifier'
					)
				)
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
	window.argentWolfPostNotifierEditor
);

// EOF: assets/runtime/post-editor.js
