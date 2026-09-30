<?php
$root = dirname( __DIR__ );
$status = 0;
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $entry ) {
	if ( 'php' !== $entry->getExtension() || strpos( $entry->getPathname(), $root . '/vendor/' ) === 0 || strpos( $entry->getPathname(), $root . '/dist/' ) === 0 ) {
		continue;
	}
	$lines = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $entry->getPathname() ) . ' 2>&1', $lines, $exit );
	if ( $exit ) {
		echo implode( "\n", $lines ) . "\n";
		$status = 1;
	}
}
echo $status ? "Syntax checks failed.\n" : "All PHP syntax checks passed.\n";
exit( $status );
