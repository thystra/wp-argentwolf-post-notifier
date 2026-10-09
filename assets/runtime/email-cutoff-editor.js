/**
 * File: assets/runtime/email-cutoff-editor.js
 *
 * Register the editor-only marker. Public rendering is always empty.
 */

/**
 * @param {Object} blocks      WordPress blocks package.
 * @param {Object} blockEditor WordPress block editor package.
 * @param {Object} components  WordPress components package.
 * @param {Object} element     WordPress element package.
 * @param {Object} i18n        WordPress internationalization package.
 */
( function ( blocks, blockEditor, components, element, i18n ) {
	const { registerBlockType } = blocks;
	const { useBlockProps } = blockEditor;
	const { Placeholder } = components;
	const { createElement } = element;
	const { __ } = i18n;

	registerBlockType( 'argentwolf-post-notifier/email-cutoff', {
		apiVersion: 3,
		title: __( 'Email Cutoff', 'argentwolf-post-notifier' ),
		description: __(
			'Mark where a future excerpt email should stop; never shown to readers.',
			'argentwolf-post-notifier'
		),
		icon: 'editor-break',
		category: 'design',
		supports: { html: false, multiple: false },
		edit: function Edit() {
			return createElement( Placeholder, {
				...useBlockProps(),
				label: __( 'Email Cutoff', 'argentwolf-post-notifier' ),
				instructions: __(
					'This marker is invisible on the website. Email excerpt rendering is not enabled yet.',
					'argentwolf-post-notifier'
				),
			} );
		},
		save: () => null,
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n
);

// EOF: assets/runtime/email-cutoff-editor.js
