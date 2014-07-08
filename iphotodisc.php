<?php

require "lib/CFPropertyList/CFPropertyList.php";
require "lib/PhotoLibrary/Album.php";
require "lib/PhotoLibrary/Library.php";
require "lib/PhotoLibrary/Photo.php";
require "lib/PhotoLibrary/Face.php";

$library_path = $argv[1];
$export_path = $argv[2];

if ( empty( $library_path ) || empty( $export_path ) ) {
	die( "Usage: php iphotodisc.php [librarypath] [exportpath]\n" );
}

if ( ! file_exists( $library_path ) ) {
	die( "Error: Library does not exist (" . $library_path . ")\n" );
}

function sort_photos_by_date( $a, $b ) {
	return $a->getDateTime()->format( "U" ) < $b->getDateTime()->format( "U" ) ? -1 : 1;
}

function get_export_folder_name( $date, $title ) {
	global $export_path;
	
	$title = str_replace( "/", "-", $title );
	
	$folder_basis = $date;
	
	if ( ! empty( $title ) ) {
		$folder_basis .= " - " . $title;
	}
	
	if ( ! file_exists( $export_path . $folder_basis ) ) {
		return $export_path . $folder_basis . "/";
	}
	else {
		$suffix = 2;
		
		while ( file_exists( $export_path . $folder_basis . " - " . str_pad( $suffix, 2, "0", STR_PAD_LEFT ) ) ) {
			$suffix++;
		}
		
		return $export_path . $folder_basis . " - " . str_pad( $suffix, 2, "0", STR_PAD_LEFT ) . "/";
	}
}

function get_export_thumb_folder_name( $event_folder ) {
	global $export_path;
	
	return $export_path . str_replace( $export_path, "thumbnails/", $event_folder );
}

// Ensure the export path ends with a slash.
$export_path = rtrim( $export_path, '/' ) . '/';

// Don't allow an export to a directory that exists.
if ( ! is_dir( $export_path ) ) {
	mkdir( $export_path );
}
else {
	die( "Export path already exists: $export_path\n" );
}

// Copy over the HTML/JS/CSS for the website.
shell_exec( "cp -r site/* " . escapeshellarg( $export_path ) );

$original_export_path = $export_path;
$export_path .= 'photos/';

mkdir( $export_path );
mkdir( $export_path . "thumbnails/" );

$library = new \PhotoLibrary\Library( $library_path );

$json_events = array();
$json_photos = array();

$photo_idx = 1;

// Get all the events and sort them.
$all_events = $library->getAlbumsOfType( 'Event' );

function get_event_date( $event ) {
	$photos = $event->getPhotos();
	
	usort( $photos, 'sort_photos_by_date' );
	
	return $photos[0]->getDateTime()->format( "Y-m-d" );
}

function sort_events( $a, $b ) {
	return ( get_event_date( $a ) < get_event_date( $b ) ? -1 : 1 );
}

usort( $all_events, 'sort_events' );

$start_date = false;

if ( $start_date_arg = array_search( '--start-date', $argv ) ) {
	$start_date = date( 'Y-m-d', strtotime( $argv[$start_date_arg + 1] ) );
}

foreach ( $all_events as $event ) {
	$event_idx = count( $json_events ) + 1;
	$event_photos = array();
	
	// For each event, generate a folder with the date, name, and index.
	$photos = $event->getPhotos();
	usort( $photos, 'sort_photos_by_date' );
	
	$event_date = get_event_date( $event );
	
	if ( $start_date && $event_date < $start_date ) {
		continue;
	}
	
	$event_name = $event->getName();
	
	// Ignore event names that are defaults from the event date: Feb 3, 1995
	if ( preg_match( "/^[a-z]{3} [0-9]{1,2}, [0-9]{4}$/i", $event_name ) ) {
		$event_name = '';
	}
	
	// Ignore event titles that are just Scan 123.jpg
	if ( preg_match( "/^Scan /", $event_name ) ) {
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
			
			if ( in_array( '--optimize', $argv ) ) {
				shell_exec( "jpegrescan " . escapeshellarg( $event_folder . $photo_filename ) . " " . escapeshellarg( $event_folder . $photo_filename ) . " > /dev/null 2>&1" );
			}
			
			shell_exec( "touch -mt " . escapeshellarg( date( "YmdHi.s", $localPhotoTimestamp ) ) . " " . escapeshellarg( $event_folder . $photo_filename ) . " > /dev/null 2>&1" );
			
		}
		
		if ( ! file_exists( $thumb_folder . "thumb_" . $photo_filename ) ) {
			shell_exec( "sips -Z 300 " . escapeshellarg( $event_folder . $photo_filename ) . " --out " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " 2> /dev/null" );
			
			if ( in_array( '--optimize', $argv ) ) {
				shell_exec( "jpegrescan " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " > /dev/null 2>&1"  );
			}
			
			shell_exec( "touch -mt " . escapeshellarg( date( "YmdHi.s", $localPhotoTimestamp ) ) . " " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " > /dev/null 2>&1" );
		}
		
		$idx++;
		
		$json_photos[ $photo_idx ] = array( 'id' => $photo_idx, 'path' => str_replace( $export_path, '', $event_folder . $photo_filename ), 'thumb_path' => str_replace( $export_path, '', $thumb_folder . "thumb_" . $photo_filename ), 'event_id' => $event_idx, 'title' => $title, 'description' => trim( $photo->getDescription() ), 'faces' => $face_names, 'date' => date( "Y-m-d", $photoTimestamp ), 'dateFriendly' => date( "F j, Y", $photoTimestamp ) );
		$event_photos[] = $photo_idx;
		
		$photo_idx++;
	}
	
	$json_events[ $event_idx ] = array( 'id' => $event_idx, 'title' => trim( $event_name ), 'date' => $event_date, 'dateFriendly' => date( "F j, Y", strtotime( $event_date ) ), 'photos' => $event_photos );
}

file_put_contents( $original_export_path . "/data.js", "var events = " . json_encode( $json_events, JSON_PRETTY_PRINT ) . ";\n\nvar photos = " . json_encode( $json_photos, JSON_PRETTY_PRINT ) . ";\n\n" );
