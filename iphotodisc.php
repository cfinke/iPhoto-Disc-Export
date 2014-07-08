#!/usr/bin/env php
<?php

require "lib/CFPropertyList/CFPropertyList.php";
require "lib/PhotoLibrary/Album.php";
require "lib/PhotoLibrary/Library.php";
require "lib/PhotoLibrary/Photo.php";
require "lib/PhotoLibrary/Face.php";

$cli_options = getopt( "l::o::js:", array( 'library::', 'output-dir::', 'jpegrescan', 'start-date:' ) );

if ( isset( $cli_options['l'] ) ) {
	$cli_options['library'] = $cli_options['l'];
}

if ( isset( $cli_options['o'] ) ) {
	$cli_options['output-dir'] = $cli_options['o'];
}

if ( isset( $cli_options['j'] ) || isset( $cli_options['jpegrescan'] ) ) {
	$cli_options['jpegrescan'] = true;
}

if ( isset( $cli_options['s'] ) ) {
	$cli_options['start-date'] = $cli_options['s'];
}

if ( isset( $cli_options['start-date'] ) ) {
	$cli_options['start-date'] = date( 'Y-m-d', strtotime( $cli_options['start-date'] ) );
}

if ( ! isset( $cli_options['start-date'] ) || ! $cli_options['start-date'] ) {
	$cli_options['start-date'] = false;
}

if ( empty( $cli_options['library'] ) || empty( $cli_options['output-dir'] ) ) {
	file_put_contents('php://stderr', "Usage: ./iphotodisc.php --library=/path/to/photo/library --output-dir=/path/for/exported/files [--jpegrescan --start-date=1950-01-01]\n" );
	die;
}

if ( ! file_exists( $cli_options['library'] ) ) {
	file_put_contents('php://stderr', "Error: Library does not exist (" . $cli_options['library'] . ")\n" );
	die;
}

// Ensure the paths ends with a slash.
$cli_options['output-dir'] = rtrim( $cli_options['output-dir'], '/' ) . '/';
$cli_options['library'] = rtrim( $cli_options['library'], '/' ) . '/';

function sort_photos_by_date( $a, $b ) {
	return $a->getDateTime()->format( "U" ) < $b->getDateTime()->format( "U" ) ? -1 : 1;
}

function get_export_folder_name( $date, $title ) {
	global $cli_options;
	
	$title = str_replace( "/", "-", $title );
	
	$folder_basis = $date;
	
	if ( ! empty( $title ) ) {
		$folder_basis .= " - " . $title;
	}
	
	if ( ! file_exists( $cli_options['output-dir'] . $folder_basis ) ) {
		return $cli_options['output-dir'] . $folder_basis . "/";
	}
	else {
		$suffix = 2;
		
		while ( file_exists( $cli_options['output-dir'] . $folder_basis . " - " . str_pad( $suffix, 2, "0", STR_PAD_LEFT ) ) ) {
			$suffix++;
		}
		
		return $cli_options['output-dir'] . $folder_basis . " - " . str_pad( $suffix, 2, "0", STR_PAD_LEFT ) . "/";
	}
}

function get_export_thumb_folder_name( $event_folder ) {
	global $cli_options;
	
	return $cli_options['output-dir'] . str_replace( $cli_options['output-dir'], "thumbnails/", $event_folder );
}

// Don't allow an export to a directory that exists.
if ( ! file_exists( $cli_options['output-dir'] ) ) {
	if ( ! mkdir( $cli_options['output-dir'] ) ) {
		file_put_contents('php://stderr', "Error: Could not create directory: " . $cli_options['output-dir'] . "\n" );
		die;
	}
}
else {
	file_put_contents('php://stderr', "Error: Output directory already exists: " . $cli_options['output-dir'] . "\n" );
	die;
}

echo "Copying website structure...\n";

// Copy over the HTML/JS/CSS for the website.
shell_exec( "cp -r site/* " . escapeshellarg( $cli_options['output-dir'] ) );

$original_export_path = $cli_options['output-dir'];
$cli_options['output-dir'] .= 'photos/';

mkdir( $cli_options['output-dir'] );
mkdir( $cli_options['output-dir'] . "thumbnails/" );

$library = new \PhotoLibrary\Library( $cli_options['library'] );

$json_events = array();
$json_photos = array();

$photo_idx = 1;

echo "Finding events...\n";

// Get all the events and sort them.
$all_events = $library->getAlbumsOfType( 'Event' );

echo "Found " . count( $all_events ) . " events\n";

function get_event_date( $event ) {
	$photos = $event->getPhotos();
	
	usort( $photos, 'sort_photos_by_date' );
	
	return $photos[0]->getDateTime()->format( "Y-m-d" );
}

function sort_events( $a, $b ) {
	return ( get_event_date( $a ) < get_event_date( $b ) ? -1 : 1 );
}

usort( $all_events, 'sort_events' );

foreach ( $all_events as $event ) {
	echo "Processing event: " . $event->getName() . "...\n";
	
	$event_idx = count( $json_events ) + 1;
	$event_photos = array();
	
	// For each event, generate a folder with the date, name, and index.
	$photos = $event->getPhotos();
	usort( $photos, 'sort_photos_by_date' );
	
	$event_date = get_event_date( $event );
	
	if ( $cli_options['start-date'] && $event_date < $cli_options['start-date'] ) {
		continue;
	}
	
	$event_name = $event->getName();
	
	// Ignore event names that are defaults from the event date: Feb 3, 1995
	if ( preg_match( "/^[a-z]{3} [0-9]{1,2}, [0-9]{4}$/i", $event_name ) ) {
		$event_name = '';
	}
	
	// Ignore event titles that are just scanner/camera defaults.
	if ( preg_match( "/^(Scan|PD_)/", $event_name ) ) {
		$event_name = '';
	}
	
	$event_folder = get_export_folder_name( $event_date, $event_name );
	$thumb_folder = get_export_thumb_folder_name( $event_folder );
	
	if ( ! is_dir( $event_folder ) ) {
		mkdir( $event_folder );
	}
	
	if ( ! is_dir( $thumb_folder ) ) {
		mkdir( $thumb_folder );
	}
	
	$idx = 1;
	$photo_count = count( $json_photos );
	
	foreach ( $photos as $photo ) {
		$photo_filename = $photo->getDateTime()->format( "Y-m-d H-i-s" );
		$photo_filename .= " - " . str_pad( $idx, strlen( (string) $photo_count ), "0", STR_PAD_LEFT );
		
		$title = trim( $photo->getCaption() );
		
		// Ignore photo titles that are just scanner/camera defaults.
		if ( preg_match( "/^(Scan|PD_)/", $title ) ) {
			$title = '';
		}
		
		if ( $title ) {
			$photo_filename .= " - " . str_replace( "/", "-", $title );
		}
		
		$face_names = array();
		
		$photo_faces = $photo->getFaces();
		
		foreach ( $photo_faces as $face ) {
			if ( $name = $face->getName() ) {
				$face_names[] = $name;
			}
			else {
				file_put_contents('php://stderr', "Couldn't find face #" . $face->getKey() . " for photo " . $photo->getCaption() . " (" . $photo->getDateTime()->format( "F j, Y" ) . ")\n" );
			}
		}
		
		$photo_path = $photo->getPath();
		$tmp = explode( ".", $photo_path );
		$photo_extension = array_pop( $tmp );
		
		$photo_filename .= "." . $photo_extension;
		
		$photoTimestamp = (int) $photo->getDateTime()->format( "U" );
		$localPhotoTimestamp = $photoTimestamp + (5 * 60 * 60);
		
		if ( ! file_exists( $event_folder . $photo_filename ) ) {
			copy( $photo->getPath(), $event_folder . $photo_filename );
			
			if ( isset( $cli_options['jpegrescan'] ) ) {
				shell_exec( "jpegrescan " . escapeshellarg( $event_folder . $photo_filename ) . " " . escapeshellarg( $event_folder . $photo_filename ) . " > /dev/null 2>&1" );
			}
			
			shell_exec( "touch -mt " . escapeshellarg( date( "YmdHi.s", $localPhotoTimestamp ) ) . " " . escapeshellarg( $event_folder . $photo_filename ) . " > /dev/null 2>&1" );
			
		}
		
		if ( ! file_exists( $thumb_folder . "thumb_" . $photo_filename ) ) {
			shell_exec( "sips -Z 300 " . escapeshellarg( $event_folder . $photo_filename ) . " --out " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " 2> /dev/null" );
			
			if ( isset( $cli_options['jpegrescan'] ) ) {
				shell_exec( "jpegrescan " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " > /dev/null 2>&1"  );
			}
			
			shell_exec( "touch -mt " . escapeshellarg( date( "YmdHi.s", $localPhotoTimestamp ) ) . " " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " > /dev/null 2>&1" );
		}
		
		$idx++;
		
		$json_photos[ $photo_idx ] = array( 'id' => $photo_idx, 'path' => str_replace( $cli_options['output-dir'], '', $event_folder . $photo_filename ), 'thumb_path' => str_replace( $cli_options['output-dir'], '', $thumb_folder . "thumb_" . $photo_filename ), 'event_id' => $event_idx, 'title' => $title, 'description' => trim( $photo->getDescription() ), 'faces' => $face_names, 'date' => date( "Y-m-d", $photoTimestamp ), 'dateFriendly' => date( "F j, Y", $photoTimestamp ) );
		$event_photos[] = $photo_idx;
		
		$photo_idx++;
	}
	
	$json_events[ $event_idx ] = array( 'id' => $event_idx, 'title' => trim( $event_name ), 'date' => $event_date, 'dateFriendly' => date( "F j, Y", strtotime( $event_date ) ), 'photos' => $event_photos );
}

echo "Writing JS for website...\n";

file_put_contents( $original_export_path . "/data.js", "var events = " . json_encode( $json_events, JSON_PRETTY_PRINT ) . ";\n\nvar photos = " . json_encode( $json_photos, JSON_PRETTY_PRINT ) . ";\n\n" );

echo "Done.\n";

