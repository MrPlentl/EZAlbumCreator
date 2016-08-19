<?php

/**
 * Created by PhpStorm.
 * User: bplentl
 * Date: 8/18/2016
 * Time: 4:30 PM
 */

define("ALBUM_DIRECTORY", "PhotoAlbums/");
define("UPLOADS_DIRECTORY", "uploads/");
define("EXTRACTED_DIRECTORY", UPLOADS_DIRECTORY. "extracted/");
define("PROCESSING_DIRECTORY", UPLOADS_DIRECTORY. "processing/");
define("COMPLETED_DIRECTORY", UPLOADS_DIRECTORY. "completed/");



class EZAlbum
{
	protected $arr_valid_image_types = array('png','jpe','jpeg','jpg');

	protected $subDirectoryRecursion=0;
	protected $sendToProcessing = FALSE;
	protected $albumName = "UNSET";

    function __construct()
    {
        $arr_RequiredDirectories = array(
										ALBUM_DIRECTORY,
										UPLOADS_DIRECTORY,
										EXTRACTED_DIRECTORY,
										PROCESSING_DIRECTORY,
										COMPLETED_DIRECTORY
									);

        foreach ($arr_RequiredDirectories as $checkDirectory) {
            if (file_exists($checkDirectory) == FALSE) {
                mkdir($checkDirectory, 0777, TRUE);
            }
        }
    }


    public function unzip ($myZipFile){
        ######### ZIP EXTRACTION   #############
        $zip = new ZipArchive;
        if ($zip->open(UPLOADS_DIRECTORY . $myZipFile) === TRUE) {
			
			$tempFileName = "temp_" . date("Ymds") . "_" . substr($myZipFile,0,-4);

            $extracted_location = PROCESSING_DIRECTORY . $tempFileName;

            $zip->extractTo($extracted_location);   //This will remove the .zip off the end
            $zip->close();

			$this->setAlbumName(substr($myZipFile,0,-4));

            echo 'Files Extracted...' . PHP_EOL;
            // Move ZIP File

            rename(UPLOADS_DIRECTORY.$myZipFile, COMPLETED_DIRECTORY. date("Ymds") . "_" . $myZipFile);
            echo 'Zip File Moved to Completed...' . PHP_EOL;

			$extracted_location = $this->scanForSubDirectories($extracted_location);

			if($this->isSendToProcessing()===TRUE){
				$this->containsFiles($extracted_location);
			}else{
				echo "An ERROR Occurred";
			}
        } else {
            echo 'failed';
        }
    }

	public function scanForSubDirectories($extracted_location){
		// Check to make sure the photos are not several folders deep
		if($this->getSubDirectoryCount()<2) {
			## Scan the $extracted_location and create an array list of all of the files and directories
			$arrDocs = array_diff(scandir($extracted_location), array('..', '.'));
			natcasesort($arrDocs);  //Sort the File List

			## Scan, Rename, and Remove ##
			if (isset($arrDocs) && is_array($arrDocs) && $this->isSendToProcessing()===FALSE) {
				echo "Checking for Sub Directories | " . $extracted_location . PHP_EOL;
				return $this->containsSubDirectory($arrDocs, $extracted_location);
			}

			if($this->isSendToProcessing()===TRUE){
				echo "Send to Processing!". PHP_EOL;
			}

		} else {
			echo "Your Photos are buried too deep. Move them to the Top level or down in ONE sub Directory. Remove any extra files or folders.". PHP_EOL;
		}

		return $extracted_location;
	}
	
	protected function containsSubDirectory($arrDocs,$extracted_location){
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

		if($dir_counter==0){
			echo "Has NO Sub Directory".PHP_EOL;
			//$hasFiles = $this->containsFiles($arrDocs);
			$this->setSendToProcessing(TRUE);
			//$this->setAlbumName();
			return $this->scanForSubDirectories($extracted_location);

		} else if($dir_counter==1){
			echo "Has ONE Sub Directory".PHP_EOL;
			$this->setSubDirectoryCount();
			return $this->scanForSubDirectories($sub_directory);

		} else {
			echo "Has TOO MANY Sub Directories".PHP_EOL;
			return "ERROR";
		}
	}
	
	protected function containsFiles($extracted_location){

		$arrDocs = array_diff(scandir($extracted_location), array('..', '.'));
		natcasesort($arrDocs);  //Sort the File List
		echo "Contains Files".PHP_EOL;
		foreach( $arrDocs as $a )   //For each document in the current document array
		{

			echo "Processing - " . $a . PHP_EOL;
			// File search and count
			if( is_file($extracted_location . "/" . $a) && $a != "." && $a != ".." && substr($a,strlen($a)-3,3) != ".db" )      //The "." and ".." are directories.  "." is the current and ".." is the parent
			{
				$image_file = $extracted_location . "/" . $a;
				if(!in_array(pathinfo($a, PATHINFO_EXTENSION),$this->getArrValidImageTypes())){
					echo "DELETE - " .$a . PHP_EOL;
					unlink($image_file);
				} else{
					$this->process_image_upload($extracted_location, $a);
				}

			}
		}
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
		$temp_image_name = $image_file;

		list($width, $height, $temp_image_type, $attr) = getimagesize($temp_image_path);
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
		$thumbnail_image_path = $THUMBNAIL_IMAGE_DESTINATION . preg_replace('{\\.[^\\.]+$}', '.jpg', "thumb_" . $image_file);
		$result = $this->generate_image_thumbnail($uploaded_image_path, $thumbnail_image_path);

		return $result ? array($uploaded_image_path, $thumbnail_image_path) : false;
	}

	private function generate_image_thumbnail($source_image_path, $thumbnail_image_path)
	{

		$THUMBNAIL_IMAGE_MAX_WIDTH=500;
		$THUMBNAIL_IMAGE_MAX_HEIGHT=500;
		//$source_gd_image=false;

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
	
	public function getSubDirectoryCount(){
		return $this->subDirectoryRecursion;
	}
	
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
}


#######
$demoZipFile ="Bethel-Pics.zip";
#######

$myAlbum = new EZAlbum();
$myAlbum->unzip($demoZipFile);