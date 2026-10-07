/**
 * File: tests/js/scaffold.test.js
 */

import { PLUGIN_SLUG, RUNTIME_EDITOR_ASSET } from '../../assets/src/editor';

describe( 'editor tooling contract', () => {
	it( 'exports canonical editor identifiers', () => {
		expect( PLUGIN_SLUG ).toBe( 'argentwolf-post-notifier' );
		expect( RUNTIME_EDITOR_ASSET ).toBe( 'assets/runtime/post-editor.js' );
	} );
} );

// EOF: tests/js/scaffold.test.js
