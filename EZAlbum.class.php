<?php

/**
 * Created by PhpStorm.
 * User: bplentl
 * Date: 8/18/2016
 * Time: 4:30 PM
 */

define("ALBUM_DIRECTORY", "PhotoAlbums/");
define("BACKUPS_DIRECTORY", ALBUM_DIRECTORY."__backups/");
define("UPLOADS_DIRECTORY", "uploads/");
define("PROCESSING_DIRECTORY", UPLOADS_DIRECTORY. "processing/");
define("COMPLETED_DIRECTORY", UPLOADS_DIRECTORY. "completed/");


class EZAlbum
{
	protected $arr_valid_image_types = array('png','jpe','jpeg','jpg');
	protected $subDirectoryRecursion=0;
	protected $sendToProcessing = FALSE;
	protected $albumName = "UNSET";
	protected $ZipFile="";
	protected $albumOverride = TRUE;

    function __construct($myZipFile,$albumOverride=TRUE)
    {
		// Build an Array of the Directories that will
		//        need to exist for the Program to run
        $arr_RequiredDirectories = array(
										ALBUM_DIRECTORY,
										BACKUPS_DIRECTORY,
										UPLOADS_DIRECTORY,
										PROCESSING_DIRECTORY,
										COMPLETED_DIRECTORY
									);
		# Create any required Directories
        foreach ($arr_RequiredDirectories as $checkDirectory) {
            if (file_exists($checkDirectory) == FALSE) {
                mkdir($checkDirectory, 0777, TRUE);
            }
        }

		$this->setZipFile($myZipFile);             // Using the given ZipFile name, set the class property '$ZipFile' to it
		$this->setAlbumOverride($albumOverride);   // FUTURE ENHANCEMENT: Eventually, the user will have the option to just add new files to an album. Right now, the album is forced to rebuild every time
		$this->unzip();                            ## UNZIP and PROCESS the contents of the zip file
    }

	function __destruct(){

		// The following methods are fired in the destructor to clean out any files that don't need to be there for the next execution

		$this->cleanAndDelete(PROCESSING_DIRECTORY);   // This will clean up any files and folders that may remain in the uploads folder leaving it clean for the next process
		       # Possible Issue: The cleanup works fine in a single user case, but if multiple people are working at the same time, there is a small chance of cleanup collision

		$this->cleanAndDelete(COMPLETED_DIRECTORY);    // This also cleans out the Completed directory, but I think I may change this to SAVE for a certain amount of time
	}

	private function unzip(){

		$myZipFile = $this->getZipFile();    // Get the class property and set it to the local variable $myZipFile

        ######### ZIP EXTRACTION   #############
        $zip = new ZipArchive;   // Using the PHP ZipArchive Class. http://php.net/manual/en/class.ziparchive.php
        if ($zip->open(UPLOADS_DIRECTORY . $myZipFile) === TRUE) {
			
			$tempFileName = "temp_" . date("Ymds") . "_" . substr($myZipFile,0,-4);   // This will remove the .zip off the end and it is also the temporary name used while processing
            $extracted_location = PROCESSING_DIRECTORY . $tempFileName;

            $zip->extractTo($extracted_location);   // Extracts the zipfile
            $zip->close();                          // Close opened or created archive

			$this->setAlbumName(substr($myZipFile,0,-4));   // This will set the Album Name to whatever the name of the zip file is.
			         # FUTURE ENHANCEMENT: Allow the USER to name the Album versus using the default name from the Zip File

            echo 'Files Extracted...' . PHP_EOL;

			$extracted_location = $this->scanForSubDirectories($extracted_location);   // After the files have been extracted, scan for sub directories.

			// Once the class property $sendToProcessing is TRUE, Run containsFiles()
			if($this->isSendToProcessing()===TRUE){
				$this->containsFiles($extracted_location);
			}else{
				echo "An ERROR Occurred\n";
			}
		// Rename and Move the uploaded zip file to the Completed directory.
		rename(UPLOADS_DIRECTORY.$myZipFile, COMPLETED_DIRECTORY. date("Ymds") . "_" . $myZipFile);
		echo 'Zip File Moved to Completed...' . PHP_EOL;

        } else {
            echo 'Cannot find '. $myZipFile . " in the " . UPLOADS_DIRECTORY . " folder.";
        }
    }

	private function scanForSubDirectories($extracted_location){
		// Check to make sure the photos are not several folders deep
		// Currently set to allow only 1 Subdirectory deep
		if($this->getSubDirectoryCount()<=1) {
			## Scan the $extracted_location and create an array list of all of the files and directories
			$arrDocs = array_diff(scandir($extracted_location), array('..', '.'));  //Scan the directory and pull the files different than '..', and '.'
			natcasesort($arrDocs);  //Sort the File List

			## Scan, Rename, and Remove ##
			if (isset($arrDocs) && is_array($arrDocs) && $this->isSendToProcessing()===FALSE) {
				echo "Checking for Sub Directories | " . $extracted_location . PHP_EOL;
				return $this->containsSubDirectory($arrDocs, $extracted_location);   // Check to see if there are folders in the array of files. Basically, make sure that the folder is clean and only contains files.
			}

			// Just a log message
			if($this->isSendToProcessing()===TRUE){
				echo "Send to Processing!". PHP_EOL;
			}
		} else {
			echo "Your Photos are buried too deep. Move them to the Top level or down in ONE sub Directory. Remove any extra files or folders.". PHP_EOL;
		}

		return $extracted_location;
	}

    private function containsSubDirectory($arrDocs, $extracted_location){
		$sub_directory="";
		$dir_counter = 0;
		foreach ($arrDocs as $a)   //For each document in the current document array
		{
			// Directory search and count
			if (is_dir($extracted_location . "/" . $a) && $a != "." && $a != ".." && substr($a, strlen($a) - 3, 3) != ".db")      //The "." and ".." are directories.  "." is the current and ".." is the parent
			{
				$dir_counter++;
				$sub_directory = $extracted_location . "/" . $a;
			}
		}

		if ($dir_counter==0)
		{
			echo "Has NO Sub Directory".PHP_EOL;
			$this->setSendToProcessing(TRUE);   // No folders exist; Set $sendToProcessing to TRUE and recall scanForSubDirectories
			return $this->scanForSubDirectories($extracted_location);

		}
		else if($dir_counter==1)
		{
			echo "Has ONE Sub Directory".PHP_EOL;
			$this->setSubDirectoryCount();   // Only One directory exists. Dig in and re-scan.
			return $this->scanForSubDirectories($sub_directory);

		}
		else
		{
			echo "Has TOO MANY Sub Directories".PHP_EOL;   // Where there are multiple folders found, return an ERROR and break out.
			return "ERROR";
		}
	}
	
	private function containsFiles($extracted_location){

		$arrDocs = array_diff(scandir($extracted_location), array('..', '.'));   //Scan the directory and pull the files different than '..', and '.'
		natcasesort($arrDocs);  //Sort the File List

		echo "Contains ". count($arrDocs) . " Files".PHP_EOL;

		$ctrPadding = strlen(count($arrDocs));   // When numerically naming the files, this creates the LEFT Padding on the integer, ie. 001, 002, 003, etc.
		$fileCtr=0;                              // Initializes the file counter for renaming
		foreach( $arrDocs as $a )               // For each document in the current document array
		{
			echo "Processing - " . $a . PHP_EOL;
			// File search and count
			if( is_file($extracted_location . "/" . $a) && $a != "." && $a != ".." && substr($a,strlen($a)-3,3) != ".db" )      //The "." and ".." are directories.  "." is the current and ".." is the parent
			{
				$image_file = $extracted_location . "/" . $a;

				// Validate files to make sure they are of a validate image type
				if(!in_array(pathinfo($a, PATHINFO_EXTENSION),$this->getArrValidImageTypes())){
					echo "DELETE - " . $a . PHP_EOL;
					unlink($image_file);   //  DELETE any files that don't match the valid image array
				} else{
					$fileCtr++;

					## Create a new image name based off the Album Name
					$newName = $this->getAlbumName() . "-" . str_pad($fileCtr,$ctrPadding, "0", STR_PAD_LEFT) . "." . pathinfo($a, PATHINFO_EXTENSION);
					rename($image_file, $extracted_location . "/" . $newName);

					$this->process_image_upload($extracted_location, $newName);   // Process the image; create thumbnails
				}
			}
		}

		// Close out and move files
		if ($this->isAlbumOverride() == TRUE && file_exists(ALBUM_DIRECTORY.$this->getAlbumName())) {
			// Optional Clean and Delete or just back it up

			# FUTURE ENHANCEMENT: Add the ability for the USER to select whether to add new files or rebuild if Album exists
			// $this->cleanAndDelete(ALBUM_DIRECTORY.$this->getAlbumName());

			echo "Moving older version - " . ALBUM_DIRECTORY.$this->getAlbumName() . " to " . BACKUPS_DIRECTORY.$this->getAlbumName()."_backup_" . date("Ymds").PHP_EOL;
			rename(ALBUM_DIRECTORY.$this->getAlbumName(), BACKUPS_DIRECTORY.$this->getAlbumName()."_backup_" . date("Ymds"));   // Move existing Album to the backups directory and rename

		} else{
			// THIS WILL BE AN ENHANCEMENT FOR LATER FOR MERGING FILES
			//echo "MERGE";
		}

		# MOVE  Processed files to the Albums Directory
		rename($extracted_location, ALBUM_DIRECTORY.$this->getAlbumName());
	}

	private function process_image_upload( $extracted_location, $image_file ) {
		$UPLOADED_IMAGE_DESTINATION=$extracted_location;
		$THUMBNAIL_IMAGE_DESTINATION=$extracted_location . '/thumbnails/';

		if (file_exists($THUMBNAIL_IMAGE_DESTINATION) == FALSE) {
			mkdir($THUMBNAIL_IMAGE_DESTINATION, 0777, TRUE);
		}

		### Rename File ###
		//
		// Create the Process of taking a user input, finding a similarity in the file names, and renaming them
		//

		$temp_image_path = $UPLOADED_IMAGE_DESTINATION . "/" . $image_file;
		//$temp_image_name = $image_file;

		list(,,$temp_image_type) = getimagesize($temp_image_path);  // list($width, $height, $temp_image_type, $attr)
		if ($temp_image_type === NULL) {
			return false;
		}
		switch ($temp_image_type) {
			case IMAGETYPE_GIF:
				break;
			case IMAGETYPE_JPEG:
				break;
			case IMAGETYPE_PNG:
				break;
			default:
				return false;
		}

		$uploaded_image_path = $UPLOADED_IMAGE_DESTINATION . "/" . $image_file;
		$thumbnail_image_path = $THUMBNAIL_IMAGE_DESTINATION . preg_replace('{\\.[^\\.]+$}', '.' . pathinfo($image_file, PATHINFO_EXTENSION), "thumb_" . $image_file);
		$result = $this->generate_image_thumbnail($uploaded_image_path, $thumbnail_image_path);

		return $result ? array($uploaded_image_path, $thumbnail_image_path) : false;
	}

	private function generate_image_thumbnail($source_image_path, $thumbnail_image_path)
	{

		$THUMBNAIL_IMAGE_MAX_WIDTH=500;
		$THUMBNAIL_IMAGE_MAX_HEIGHT=500;
		$source_gd_image=false;

		list($source_image_width, $source_image_height, $source_image_type) = getimagesize($source_image_path);
		switch ($source_image_type) {
			case IMAGETYPE_GIF:
				$source_gd_image = imagecreatefromgif($source_image_path);
				break;
			case IMAGETYPE_JPEG:
				$source_gd_image = imagecreatefromjpeg($source_image_path);
				break;
			case IMAGETYPE_PNG:
				$source_gd_image = imagecreatefrompng($source_image_path);
				break;
		}
		if ($source_gd_image === false) {
			return false;
		}
		$source_aspect_ratio = $source_image_width / $source_image_height;
		$thumbnail_aspect_ratio = $THUMBNAIL_IMAGE_MAX_WIDTH / $THUMBNAIL_IMAGE_MAX_HEIGHT;
		if ($source_image_width <= $THUMBNAIL_IMAGE_MAX_WIDTH && $source_image_height <= $THUMBNAIL_IMAGE_MAX_HEIGHT) {
			$thumbnail_image_width = $source_image_width;
			$thumbnail_image_height = $source_image_height;
		} elseif ($thumbnail_aspect_ratio > $source_aspect_ratio) {
			$thumbnail_image_width = (int) ($THUMBNAIL_IMAGE_MAX_HEIGHT * $source_aspect_ratio);
			$thumbnail_image_height = $THUMBNAIL_IMAGE_MAX_HEIGHT;
		} else {
			$thumbnail_image_width = $THUMBNAIL_IMAGE_MAX_WIDTH;
			$thumbnail_image_height = (int) ($THUMBNAIL_IMAGE_MAX_WIDTH / $source_aspect_ratio);
		}
		$thumbnail_gd_image = imagecreatetruecolor($thumbnail_image_width, $thumbnail_image_height);
		imagecopyresampled($thumbnail_gd_image, $source_gd_image, 0, 0, 0, 0, $thumbnail_image_width, $thumbnail_image_height, $source_image_width, $source_image_height);
		imagejpeg($thumbnail_gd_image, $thumbnail_image_path, 90);
		imagedestroy($source_gd_image);
		imagedestroy($thumbnail_gd_image);
		return true;
	}

	# This Method simply runs the full process to DELETE a Directory; includes all files and sub-directories
	private function cleanAndDelete($dir) {
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (filetype($dir."/".$object) == "dir") $this->cleanAndDelete($dir."/".$object); else unlink($dir."/".$object);
				}
			}
		reset($objects);
		rmdir($dir);
		}
	}

	/**
	 * @return string
	 */
	public function getAlbumName()
	{
		return $this->albumName;
	}

	/**
	 * @param string $albumName
	 */
	public function setAlbumName($albumName)
	{
		$this->albumName = $albumName;
	}

	/**
	 * @return boolean
	 */
	public function isSendToProcessing()
	{
		return $this->sendToProcessing;
	}

	/**
	 * @param boolean $sendToProcessing
	 */
	public function setSendToProcessing($sendToProcessing)
	{
		$this->sendToProcessing = $sendToProcessing;
	}

	/**
	 * @return integer
	 */
	public function getSubDirectoryCount(){
		return $this->subDirectoryRecursion;
	}

	/**
	 * @param integer
	 */
	public function setSubDirectoryCount(){
		$this->subDirectoryRecursion = intval($this->getSubDirectoryCount())+1;
	}

	/**
	 * @return array
	 */
	public function getArrValidImageTypes()
	{
		return $this->arr_valid_image_types;
	}

	/**
	 * @return string
	 */
	private function getZipFile()
	{
		return $this->ZipFile;
	}

	/**
	 * @param string $ZipFile
	 */
	private function setZipFile($ZipFile)
	{
		$this->ZipFile = $ZipFile;
	}

	/**
	 * @return boolean
	 */
	public function isAlbumOverride()
	{
		return $this->albumOverride;
	}

	/**
	 * @param boolean $albumOverride
	 */
	public function setAlbumOverride($albumOverride)
	{
		$this->albumOverride = $albumOverride;
	}
}