#!/usr/bin/env php
<?php

require "lib/CFPropertyList/CFPropertyList.php";
require "lib/PhotoLibrary/Album.php";
require "lib/PhotoLibrary/Library.php";
require "lib/PhotoLibrary/Photo.php";
require "lib/PhotoLibrary/Face.php";

$cli_options = getopt( "l::o::js:e:ut:", array( 'library::', 'output-dir::', 'jpegrescan', 'start_date:', 'end_date:', 'update_site', 'timezone:' ) );

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
	$cli_options['start_date'] = $cli_options['s'];
}

if ( isset( $cli_options['t'] ) ) {
	$cli_options['timezone'] = $cli_options['t'];
}

if ( isset( $cli_options['start_date'] ) ) {
	$cli_options['start_date'] = date( 'Y-m-d', strtotime( $cli_options['start_date'] ) );
}

if ( isset( $cli_options['end_date'] ) ) {
	$cli_options['end_date'] = date( 'Y-m-d', strtotime( $cli_options['end_date'] ) );
}

if ( ! isset( $cli_options['end_date'] ) || ! $cli_options['end_date'] ) {
	$cli_options['end_date'] = false;
}

if ( ! isset( $cli_options['start_date'] ) || ! $cli_options['start_date'] ) {
	$cli_options['start_date'] = false;
}

if ( isset( $cli_options['u'] ) ) {
	$cli_options['update_site'] = true;
}

if ( isset( $cli_options['update_site'] ) ) {
	$cli_options['update_site'] = true;
}
else {
	$cli_options['update_site'] = false;
}

if ( isset( $cli_options['timezone'] ) ) {
//	date_default_timezone_set( $cli_options['timezone'] );
}

if ( empty( $cli_options['library'] ) || empty( $cli_options['output-dir'] ) ) {
	file_put_contents('php://stderr', "Usage: ./iphotodisc.php --library=/path/to/photo/library --output-dir=/path/for/exported/files [--jpegrescan --start_date=1950-01-01 --end_date=1955-01-01]\n" );
	die;
}

if ( ! is_array( $cli_options['library'] ) ) {
	$cli_options['library'] = array( $cli_options['library'] );
}

$cli_options['library'] = array_unique( $cli_options['library'] );

foreach ( $cli_options['library'] as $idx => $library ) {
	if ( ! file_exists( $library ) ) {
		file_put_contents('php://stderr', "Error: Library does not exist (" . $library . ")\n" );
		die;
	}

	// Ensure the paths end with a slash.
	$cli_options['library'][$idx] = rtrim( $library, '/' ) . '/';
}

// Ensure the output dir path ends with a slash.
$cli_options['output-dir'] = rtrim( $cli_options['output-dir'], '/' ) . '/';

function sort_photos_by_date( $a, $b ) {
	return $a->getDateTime()->format( "U" ) < $b->getDateTime()->format( "U" ) ? -1 : 1;
}

function get_export_folder_name( $date, $title, $folders ) {
	global $cli_options;
	
	$title = str_replace( "/", "-", $title );
	
	$folder_basis = $date;
	
	if ( ! empty( $title ) ) {
		$folder_basis .= " - " . $title;
	}
	
	$folder_basis = preg_replace( '/[^a-zA-Z0-9 \(\)\.,\-]/', '', $folder_basis );
	
	if ( ! in_array( $cli_options['output-dir'] . $folder_basis . "/", $folders ) ) {
		return $cli_options['output-dir'] . $folder_basis . "/";
	}
	else {
		$suffix = 2;
		
		while ( in_array( $cli_options['output-dir'] . $folder_basis . " - " . str_pad( $suffix, 2, "0", STR_PAD_LEFT ) . "/", $folders ) ) {
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

echo "Copying website structure...\n";

// Copy over the HTML/JS/CSS for the website.
shell_exec( "cp -r site/* " . escapeshellarg( $cli_options['output-dir'] ) );

if ( $cli_options['update_site'] ) {
	exit;
}

$original_export_path = $cli_options['output-dir'];
$cli_options['output-dir'] .= 'photos/';

if ( ! file_exists( $cli_options['output-dir'] ) ) {
	mkdir( $cli_options['output-dir'] );
}

if ( ! file_exists( $cli_options['output-dir'] . 'thumbnails/' ) ) {
	mkdir( $cli_options['output-dir'] . "thumbnails/" );
}

$json_events = array();
$json_photos = array();
$json_faces = array();

$photo_idx = 1;

$timezone = new \DateTimeZone( date_default_timezone_get() );
$time_right_now = new \DateTime( 'now', $timezone );
$timezone_offset = $timezone->getOffset( $time_right_now );

foreach ( $cli_options['library'] as $library_path ) {
	echo "Processing " . $library_path . "...\n";

	$library = new \PhotoLibrary\Library( $library_path );

	echo "Finding events...\n";

	// Get all the events and sort them.
	$all_events = $library->getAlbumsOfType( 'Event' );

	echo "Found " . count( $all_events ) . " events\n";

	usort( $all_events, 'sort_events' );

	$folders = array();

	foreach ( $all_events as $event_counter => $event ) {
		$event_idx = count( $json_events ) + 1;

		echo "Processing event #" . ( $event_counter + 1 ) . "/" . count( $all_events ) . ": " . $event->getName() . "...\n";

		$event_photos = array();

		// For each event, generate a folder with the date, name, and index.
		$photos = $event->getPhotos();
		usort( $photos, 'sort_photos_by_date' );

		$event_date = get_event_date( $event );

		if ( $cli_options['start_date'] && $event_date < $cli_options['start_date'] ) {
			continue;
		}

		if ( $cli_options['end_date'] && $event_date > $cli_options['end_date'] ) {
			continue;
		}

		$event_name = $event->getName();

		// Ignore event names that are defaults from the event date: Feb 3, 1995
		if ( preg_match( "/^[a-z]{3} [0-9]{1,2}, [0-9]{4}$/i", $event_name ) ) {
			$event_name = '';
		}

		$event_folder = get_export_folder_name( $event_date, $event_name, $folders );

		$folders[] = $event_folder;

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
			$photo_path = $photo->getPath();

			$localPhotoTimestamp = (int) $photo->getDateTime()->format( "U" ) + $timezone_offset;

			$photo_date = date( "Y-m-d H-i-s", $localPhotoTimestamp );
			$photo_filename = $photo_date . " - " . str_pad( $idx, strlen( (string) $photo_count ), "0", STR_PAD_LEFT );

			$title = trim( $photo->getCaption() );

			if ( $title ) {
				$photo_filename .= " - " . str_replace( "/", "-", $title );
			}

			$face_names = array();

			$photo_faces = $photo->getFaces();

			foreach ( $photo_faces as $face ) {
				if ( $name = $face->getName() ) {
					if ( ! in_array( $name, $face_names ) ) {
						$face_names[] = $name;

						if ( ! isset( $json_faces[ $name ] ) ) {
							$json_faces[ $name ] = array( 'photos' => array() );
							$json_faces[ $name ]['face_key'] = $face->getKey();
						}

						$json_faces[ $name ]['photos'][$photo_idx] = $face->getCoordinates();
					}
				}
				else {
					file_put_contents('php://stderr', "Couldn't find face #" . $face->getKey() . " for photo " . $photo->getCaption() . " (" . $photo->getDateTime()->format( "F j, Y" ) . ")\n" );
				}
			}

			$tmp = explode( ".", $photo_path );
			$photo_extension = array_pop( $tmp );

			$photo_filename = preg_replace( '/[^a-zA-Z0-9 \(\)\.,\-]/', '', $photo_filename );

			$photo_filename .= "." . $photo_extension;

			$utcPhotoTimestamp = $localPhotoTimestamp - $timezone_offset; // Mac OS expects this to be in UTC

			if ( ! file_exists( $event_folder . $photo_filename ) ) {
				copy( $photo->getPath(), $event_folder . $photo_filename );

				if ( isset( $cli_options['jpegrescan'] ) ) {
					shell_exec( "jpegrescan " . escapeshellarg( $event_folder . $photo_filename ) . " " . escapeshellarg( $event_folder . $photo_filename ) . " > /dev/null 2>&1" );
				}

				shell_exec( "touch -mt " . escapeshellarg( date( "YmdHi.s", $utcPhotoTimestamp ) ) . " " . escapeshellarg( $event_folder . $photo_filename ) . " > /dev/null 2>&1" );

			}

			if ( ! file_exists( $thumb_folder . "thumb_" . $photo_filename ) ) {
				shell_exec( "sips -Z 300 " . escapeshellarg( $event_folder . $photo_filename ) . " --out " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " 2> /dev/null" );

				if ( isset( $cli_options['jpegrescan'] ) ) {
					shell_exec( "jpegrescan " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " > /dev/null 2>&1"  );
				}

				shell_exec( "touch -mt " . escapeshellarg( date( "YmdHi.s", $utcPhotoTimestamp ) ) . " " . escapeshellarg( $thumb_folder . "thumb_" . $photo_filename ) . " > /dev/null 2>&1" );
			}

			$idx++;

			$json_photos[ $photo_idx ] = array( 'id' => $photo_idx, 'path' => str_replace( $cli_options['output-dir'], '', $event_folder . $photo_filename ), 'thumb_path' => str_replace( $cli_options['output-dir'], '', $thumb_folder . "thumb_" . $photo_filename ), 'event_id' => $event_idx, 'title' => $title, 'description' => $title/* . "\n\n" . trim( $photo->getDescription() )*/, 'faces' => $face_names, 'date' => date( "Y-m-d", $localPhotoTimestamp ), 'dateFriendly' => date( "F j, Y g:i A", $localPhotoTimestamp ) );
			$event_photos[] = $photo_idx;

			$photo_idx++;
		}

		$json_events[ $event_idx ] = array( 'id' => $event_idx, 'title' => trim( $event_name ), 'date' => $event_date, 'dateFriendly' => date( "F j, Y", strtotime( $event_date ) ), 'photos' => $event_photos );
	}
}

echo "Writing JS for website...\n";

ksort( $json_faces );

file_put_contents( $original_export_path . "/inc/data.js", "var events = {", FILE_APPEND );
write_json_object_without_using_so_much_memory( $original_export_path . "/inc/data.js", $json_events );
file_put_contents( $original_export_path . "/inc/data.js", "};\n\n", FILE_APPEND );
file_put_contents( $original_export_path . "/inc/data.js", "var photos = {", FILE_APPEND );
write_json_object_without_using_so_much_memory( $original_export_path . "/inc/data.js", $json_photos );
file_put_contents( $original_export_path . "/inc/data.js", "};\n\n", FILE_APPEND );
file_put_contents( $original_export_path . "/inc/data.js", "var faces = {", FILE_APPEND );
write_json_object_without_using_so_much_memory( $original_export_path . "/inc/data.js", $json_faces );
file_put_contents( $original_export_path . "/inc/data.js", "};\n\n", FILE_APPEND );

function write_json_object_without_using_so_much_memory( $path, $obj ) {
	foreach ( $obj as $idx => $member ) {
		$comma = ",";

		if ( next( $obj ) === false ) {
			$comma = "";
		}

		file_put_contents( $path, json_encode( (string) $idx ) . ': ' . json_encode( $member, JSON_PRETTY_PRINT ) . $comma . "\n", FILE_APPEND );
	}
}

echo "Done.\n";

function get_event_date( $event ) {
	global $timezone_offset;
	
	$photos = $event->getPhotos();

	usort( $photos, 'sort_photos_by_date' );

	return date( "Y-m-d", (int) $photos[0]->getDateTime()->format( "U" ) + $timezone_offset );
}

function get_event_timestamp( $event ) {
	global $timezone_offset;
	
	$photos = $event->getPhotos();

	usort( $photos, 'sort_photos_by_date' );

	return $photos[0]->getDateTime()->format( "U" ) + $timezone_offset;
}

function sort_events( $a, $b ) {
	return ( get_event_timestamp( $a ) < get_event_timestamp( $b ) ? -1 : 1 );
}