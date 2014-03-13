<?php
/* 
	Name:		  MySQL Backup Job
	Author:		Ryan Gillett
	Source: 	http://ryangillett.me/blog/2014/03/mysql-backup-using-php/
	Purpose:	To routinely backup all MySQL databases on a server to a Windows network share, this script is expected to be executed by cron job.

*/

/* ############################################################################################ */
/* START OF CONFIGURATION SETTINGS */

/* Database Server credentials */
$dbhost = 'localhost';		  // Only change this if your database server is not on the same server as the web server. 
$dbuser = 'backup_job';	    // This should be a dedicated MySQL user for the purpose of backup.
$dbpass = 'yourSecurePass';	// Change this to the STRONG PASSWORD you used for the backup user.
$dbname = 'system';			    // This is the database that is used by this script for logging history and checking for excluded databases

/* Compression, Retention and file settings */
$compressed 		  = true;	  // When set to true, the database backup file is gzipped (compressed).
$retention 		  	= true;	  // When set to false, an unlimited number of database backups are kept (ignoring the limit set below).
$retention_limit 	= 30;   	// When retention is TRUE, this option allows you to set the number of days old a backup can be before it's deleted.
$backup_location 	= '/media/backups/sql'; // Change this to the Location that the backup files should be stored in.
$safe_mode			  = true;   // Leave this set as true unless you're altering the script itself.

/* END OF CONFIGURATION SETTINGS - EDITING BELOW THIS LINE CHANGES THE OPERATION OF THIS SCRIPT */
/* ############################################################################################ */

	function returnFatalError($e) { print($e); exit(1);	}

	function stripExtension($file,$ext) {
		$ext = (substr($ext,0,1) == '.') ? $ext:'.'.$ext;
		$filelen = strlen($file);
		$extlen = strlen($ext);
		if ( substr( $file, $filelen-($extlen),$filelen ) == $ext ) { 
			$file = substr( $file, 0, $filelen-($extlen) );
		} 
		return $file;
	}	

/* Try and catch some issues before they occur */

	if ($safe_mode) {

		# Validate/Sanitise configuration settings
		$dbhost = (!empty($dbhost)) ? $dbhost:'localhost';
		$compressed = (is_bool($compressed)) ? $compressed:true;
		$retention = (is_bool($retention)) ? $retention:true;
		$retention_limit = (is_numeric($retention_limit)) ? $retention_limit:30;
		$backup_location = (substr($backup_location, -1) == '/') ? $backup_location:$backup_location.'/';

		# Errors and Warnings
		try { if (empty($backup_location)) { throw new Exception("Configuration setting 'Backup location' cannot be empty."); } } catch(Exception $e) { returnFatalError($e); }
		try { if (!is_dir($backup_location)) { throw new Exception("Configuration setting 'Backup location' doesn't appear to exist."); }} catch(Exception $e) { returnFatalError($e); }
		try { if (empty($dbuser)) { throw new Exception("Configuration setting 'Database User' cannot be empty."); }} catch(Exception $e) { returnFatalError($e); }
		if (empty($dbpass) || strlen($dbpass) < 6) { trigger_error("Configuration setting 'Database Password' is empty or short, consider setting a stronger password.",E_USER_WARNING); }
	
	}

	try { 
		$dbcon = new mysqli($dbhost, $dbuser, $dbpass, $dbname); 
		if(mysqli_connect_errno()) { throw new Exception("Database Connection Failed - Check server and credentials (" . mysqli_connect_errno() .")" ); }
	} catch(Exception $e) { returnFatalError($e); }

/* Get list of all databases on the instance */

	$sql = "SHOW DATABASES;";
	$stmt = $dbcon->prepare($sql);
	$stmt->execute();
	$stmt->bind_result( $database );
	while ($stmt -> fetch()) { 
		$databases[] = $database;
	}
	$stmt->free_result();
	$stmt->close();

/* Get list of excluded databases on the instance */

	$sql = "SELECT DISTINCT Name FROM backup_exclude;";
	$stmt = $dbcon->prepare($sql);
	$stmt->execute();
	$stmt->bind_result( $database );
	while ($stmt -> fetch()) { 
		$databases_excluded[] = $database;
	}
	$stmt->free_result();
	$stmt->close();

/* Remove excluded databases from the all databases list */

	$databases_forbackup = array_diff($databases, $databases_excluded);

/* Cycle through the resulting database list and mysqldump to [gzipped] file */

	if (count($databases_forbackup > 0)) {

		foreach($databases_forbackup as $key=>$value) {
			$writepath = '"' . $backup_location . date("Ymd-His") . '_' . $value . '.sql' . ( ($compressed)?'.gz':'' ) . '"';
			$command = 'mysqldump --opt -h '. $dbhost .' -u '. $dbuser .' -p'. $dbpass .' '. $value  .(($compressed)?' | gzip > ' . $writepath:' > '. $writepath) .' && stat -c %s '. $writepath;
			exec($command,$output);
			$size = $output[0];

			// Write to the history table
			$sql = "INSERT INTO backup_history (Name, TakenAt, Size, Compressed) VALUES (?,?,?,?);";
			$stmt = $dbcon->prepare($sql);
			$stmt->bind_param("ssss", $value, date('Y-m-d H:i:s'), $size, $compressed);
			$stmt->execute();
			$stmt->close();

			unset($output);

		}

	}

/* Backup Retention */

	if ($retention) {

	    $files = glob($backup_location."*");

	    foreach($files as $file) {

	        if(is_file($file) && time() - filemtime($file) >= $retention_limit*24*60*60) {

	            unlink($file);

	            $filename = str_ireplace($backup_location, '', $file);

	            $TakenAt = 
	            		 substr($filename,0,4)
	            	.'-'.substr($filename,4,2)
	            	.'-'.substr($filename,6,2)
	            	.' '.substr($filename,9,2)
	            	.':'.substr($filename,11,2)
	            	.':'.substr($filename,13,2);

	           	$DatabaseName = stripExtension( 
	           						stripExtension( 
	           							substr($filename,16,strlen($filename))
	           							,'.gz')
	           						,'.sql');

				// Update the history table
				$sql = "UPDATE backup_history SET Retained = 0 WHERE Name = ? and TakenAt = ?;";
				$stmt = $dbcon->prepare($sql);
				$stmt->bind_param("ss", $DatabaseName, $TakenAt);
				$stmt->execute();
				$stmt->close();
				unset($DatabaseName, $TakenAt);

	        }

	    }

	}

?>
