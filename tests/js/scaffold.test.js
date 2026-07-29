/**
 * File: tests/js/scaffold.test.js
 */

import { PLUGIN_SLUG, SCAFFOLD_VERSION } from '../../assets/src/editor';

describe( 'development scaffold', () => {
	it( 'exports canonical identifiers', () => {
		expect( PLUGIN_SLUG ).toBe( 'argentwolf-post-notifier' );
		expect( SCAFFOLD_VERSION ).toBe( '0.1.0-alpha.1' );
	} );
} );

// EOF: tests/js/scaffold.test.js
