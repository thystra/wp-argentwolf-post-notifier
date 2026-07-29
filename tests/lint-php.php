<?php
/**
 * Dependency-free PHP syntax runner.
 *
 * File: tests/lint-php.php
 */

$root      = dirname( __DIR__ );
$iterator  = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$root,
		FilesystemIterator::SKIP_DOTS
	)
);
$failures  = array();
$skip_dirs = array( '/vendor/', '/node_modules/', '/dist/' );

foreach ( $iterator as $file ) {
	if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
		continue;
	}

	$path       = $file->getPathname();
	$normalized = str_replace( DIRECTORY_SEPARATOR, '/', $path );

	foreach ( $skip_dirs as $skip_dir ) {
		if ( str_contains( $normalized, $skip_dir ) ) {
			continue 2;
		}
	}

	$command = escapeshellarg( PHP_BINARY )
		. ' -l '
		. escapeshellarg( $path )
		. ' 2>&1';

	exec( $command, $output, $status );

	if ( 0 !== $status ) {
		$failures[] = implode( PHP_EOL, $output );
	}

	$output = array();
}

if ( array() !== $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, "All PHP files passed syntax validation.\n" );

// EOF: tests/lint-php.php
