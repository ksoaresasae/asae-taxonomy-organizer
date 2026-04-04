<?php
/**
 * Builds the release zip with forward slashes for WordPress compatibility.
 *
 * Run from the plugin root directory:
 *   php build-zip.php
 *
 * Output: releases/asae-taxonomy-organizer.zip
 *
 * IMPORTANT: Do NOT use PowerShell's Compress-Archive — it writes backslash
 * path separators which break the WordPress plugin installer.
 *
 * @package ASAE_Taxonomy_Organizer
 */

// Resolve paths relative to this script's location (the plugin root).
$pluginDir = __DIR__;
$zipPath   = $pluginDir . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'asae-taxonomy-organizer.zip';

// Ensure releases directory exists.
if ( ! is_dir( dirname( $zipPath ) ) ) {
	mkdir( dirname( $zipPath ), 0755, true );
}

// Directories and files to exclude from the zip.
$exclude = [ '.git', '.github', '.claude', 'releases', 'instructions', 'node_modules', 'vendor', 'composer.json', 'composer.lock', '.gitignore', 'build-zip.php', 'CLAUDE.md' ];

$zip = new ZipArchive();
if ( $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	echo "ERROR: Could not create zip at: $zipPath\n";
	exit( 1 );
}

// Walk the plugin directory tree.
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $pluginDir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $file ) {
	// Build a relative path with forward slashes, prefixed with the plugin folder name.
	$relative = 'asae-taxonomy-organizer/' . str_replace( '\\', '/', $iterator->getSubPathname() );
	$parts    = explode( '/', $relative );

	// Check exclusions.
	$skip = false;
	foreach ( $parts as $part ) {
		if ( in_array( $part, $exclude, true ) ) {
			$skip = true;
			break;
		}
	}
	if ( $skip ) {
		continue;
	}

	if ( $file->isDir() ) {
		$zip->addEmptyDir( $relative . '/' );
	} else {
		$zip->addFile( $file->getPathname(), $relative );
	}
}

echo 'Files in zip: ' . $zip->numFiles . "\n";
$zip->close();
echo "Release zip built: $zipPath\n";
