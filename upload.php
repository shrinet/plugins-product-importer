<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$sep = DIRECTORY_SEPARATOR;
$messages = array();

$import_folder = "default";

if ( isset( $_POST[ "import_folder" ] ) ) {
	$import_folder = $_POST[ "import_folder" ];
}

function reArrayFiles(&$file_post) {
    $file_ary = array();
    $multiple = is_array( $file_post[ 'name' ] );
    $file_count = $multiple ? count( $file_post[ 'name' ] ) : 1;
    $file_keys = array_keys( $file_post );
    for ( $i = 0; $i < $file_count; $i++ ) {
        foreach ( $file_keys as $key ) {
            $file_ary[$i][$key] = $multiple ? $file_post[$key][$i] : $file_post[$key];
        }
    }
    return $file_ary;
}

$extraFolder = "";
if ( strlen($_POST["extra_folder"]) > 0 ) {
	$extraFolder = $_POST["extra_folder"] . $sep;
} 

$target_dir = __DIR__ . "{$sep}..{$sep}..{$sep}uploads{$sep}imports-new{$sep}{$import_folder}{$sep}queue{$sep}{$extraFolder}";

if (!file_exists($target_dir)) {
	mkdir($target_dir, 0777, true);
}

if (!file_exists(__DIR__ . "{$sep}..{$sep}..{$sep}uploads{$sep}imports-new{$sep}{$import_folder}{$sep}processed{$sep}{$extraFolder}")) {
	mkdir(__DIR__ . "{$sep}..{$sep}..{$sep}uploads{$sep}imports-new{$sep}{$import_folder}{$sep}processed{$sep}{$extraFolder}", 0777, true);
}			
if (!file_exists(__DIR__ . "{$sep}..{$sep}..{$sep}uploads{$sep}imports-new{$sep}{$import_folder}{$sep}logs{$sep}{$extraFolder}")) {
	mkdir(__DIR__ . "{$sep}..{$sep}..{$sep}uploads{$sep}imports-new{$sep}{$import_folder}{$sep}logs{$sep}{$extraFolder}", 0777, true);
}

$_FILES[ "fileToUpload" ] = reArrayFiles($_FILES[ "fileToUpload" ]);

if ( $_POST[ 'isZipFile' ] === 'on' ) {
	$target_file = $target_dir . basename( $_FILES[ "fileToUpload" ][ 0 ][ "name" ] );
	$success = move_uploaded_file( $_FILES[ "fileToUpload" ][ 0 ][ "tmp_name" ], $target_file );
	if ( $success ) {
		$zip = new ZipArchive;
		if ($zip->open($target_file) === TRUE) {
			$zip->extractTo( $target_dir );
			$zip->close();
			$messages = array( "ZIP UPLOAD SUCCESSFUL");
			$serialized = substr( implode( "; ", $messages ), 0, 150);
			header( "Location: " . $_SERVER[ 'HTTP_REFERER' ] . "&upload_message={$serialized}");
		}
	}	
}

if ( isset( $_POST[ 'shouldSplitFiles' ] ) ) {
	if ( $_POST[ 'shouldSplitFiles' ] === 'on' ) {
		$row_count = intval( $_POST[ 'splitFileRowCount' ] );
		if ( count( $_FILES[ "fileToUpload" ] ) > 0 ) {
			$target_file = $target_dir . basename( $_FILES[ "fileToUpload" ][ 0 ][ "name" ] );
			$success = move_uploaded_file( $_FILES[ "fileToUpload" ][ 0 ][ "tmp_name" ], $target_file );
			$messages[] = "Uploaded original file: $target_file";
			if ( $success ) {
				$row = 1;
				if (($handle = fopen( $target_file, "r" ) ) !== FALSE) {
					$file_row_counter = 0;
					$file_counter = 1;
					$row_counter = 0;
					$file_header = "";
					$file = "";
					$file_name = str_replace( '.csv', "-$file_counter.csv", $target_file );
					while(! feof($handle)) {
						$data = fgets($handle);
						if ( $row_counter === 0 ) {
							$file_header = $data;
							$file = $data;
						} else {
							if ( $file_row_counter === $row_count ) {
								$file_row_counter = 0;
								file_put_contents( $file_name, $file );
								$messages[] = "Uploaded: $file_name";
								$file_counter++;
								$file_name = str_replace( '.csv', "-$file_counter.csv", $target_file );
								$file = $file_header;
							}
							$file .= $data;
							$file_row_counter++;
						}
						$row_counter++;
					}
					if ( strlen( $file ) > 0 ) {
						file_put_contents( $file_name, $file );
						$messages[] = "Uploaded: $file_name";
					}
				}
				unlink( $target_file );
				$messages[] = "Deleted original file: $target_file";
			}
		}
	}
} else {
	foreach ( $_FILES["fileToUpload"] as $file_to_upload ) {
		if ( strlen( $file_to_upload["name"] ) === 0 ) {
			$message = "file not selected!";
		} else {
			$target_file = $target_dir . basename($file_to_upload["name"]);
			$message = "Upload Successful! Please click the import button to import the new data into the database.";
			if ( strpos( $_SERVER[ 'HTTP_REFERER' ], '/admin.php?page=maclean_product_importer' ) !== false ) {
				if ( pathinfo( $file_to_upload["name"] )[ 'extension' ] === 'csv' ) {
					if ( isset( $_POST[ "submit" ] ) ) {
						$files = glob( $target_dir . '*.csv' );
						if( !empty( $files ) ) {
							$processed_files = glob( $target_dir . "{$sep}..{$sep}processed{$sep}*.csv");
							if ( count( $processed_files ) > 9 ) {
								$processed_files_array = array();
								foreach ( $processed_files as $filename ) {
									$processed_files_array[$filename] = filemtime($filename);
								}
								arsort($processed_files_array);
								$most_recent_files = array_slice( $processed_files_array, 9, (count( $processed_files ) - 9 ));
								foreach ( array_keys( $most_recent_files ) as $file_unlink ) {
									unlink( $file_unlink );
								}
							}
						}
						if ( file_exists( $target_file ) ) {
							$target_file = str_replace(".csv", rand(1, 10000) . ".csv", $target_file );
						}
						$success = move_uploaded_file($file_to_upload["tmp_name"], $target_file);
						if ( $success === false ) {
							$message = "Upload unsuccessful, file could not be saved to server.";
						}
					}
				} else {
					$message = "Upload unsuccessful, file extension unsupported";
				}
			} else {
				$message = "unknown request denied";
			}
		}
		$messages[] = urlencode( $message );
	}
}
$serialized = substr( implode( "; ", $messages ), 0, 150);
header( "Location: " . $_SERVER[ 'HTTP_REFERER' ] . "&upload_message={$serialized}");