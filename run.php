<?php
// run.php
// script to extract album art from amazon product database
// http://magnetikonline.com/fetchalbumart/



require('config.php');



$scanalbumfolder = new scanalbumfolder($argv);
$scanalbumfolder->execute();



class scanalbumfolder {

	private $argv = array();



	public function __construct(array $inputargv) {

		$this->argv = $inputargv;
	}

	public function execute() {

		if (sizeof($this->argv) != 2) {
			// album path not given
			die('Error: please specify an album path to scan');
		}

		if (!is_dir($sourcefolder = $this->argv[1])) {
			// invalid album path
			die('Error: \'' . $sourcefolder . '\' is not a valid path');
		}

		$sourcefolder = rtrim($sourcefolder,'\\/') . '\*';

		$amazonfindalbumimage = new amazonfindalbumimage();
		$extractartistalbum = new extractartistalbum();

		while ($albumfolderlist = glob($sourcefolder,GLOB_ONLYDIR)) {
			foreach ($albumfolderlist as $albumfolder) {
				// check for mp3's in found folder
				if (!glob($albumfolder . '\\*.mp3')) continue;

				// generate save image path and check if image already exists, skip to next album if so
				$saveimagepath = $albumfolder . '\\' . TARGETIMAGE_FILENAME;
				if (is_file($saveimagepath)) continue;

				// skip if can't extract artist & album data
				if (!($artistalbumlist = $extractartistalbum->extract($albumfolder))) continue;

				$amazonfindalbumimage->execute(
					$artistalbumlist[0],
					$artistalbumlist[1],
					$saveimagepath
				);
			}

			// try one folder deeper
			$sourcefolder .= '\*';
		}

		// all done
		$amazonfindalbumimage->finish();
	}
}



class amazonfindalbumimage {

	const amazon_requesthost = 'ecs.amazonaws.com';
	const amazon_requestpath = '/onca/xml';

	private $logfp;
	private $logerrorfp;

	private $amazonresultpathlist = array();
	private $amazonresultlist = array();
	private $amazonresultcurrent = array();



	public function __construct() {

		$this->logfp = fopen(LOG_FILE,'a');
		$this->logerrorfp = fopen(LOGERROR_FILE,'a');
	}

	public function finish() {

		$this->writelog('Finished');

		fclose($this->logfp);
		fclose($this->logerrorfp);
	}

	public function execute($inputartistname,$inputalbumname,$inputtargetfile) {

		$artistname = $this->removenoisewords($inputartistname);
		$albumname = $this->removenoisewords($inputalbumname);

		// make request to Amazon products API
		$this->writeline('Searching for: [' . $artistname . ' / ' . $albumname . ']');

		$responsexml = $this->fetchurl(
			$this->buildamazonrequesturl(array(
				'Keywords' => $artistname . ' ' . $albumname,
				'Operation' => 'ItemSearch',
				'ResponseGroup' => 'ItemAttributes,Images',
				'SearchIndex' => 'Music',
				'salesrank' => 'Bestselling'
			)),
			TRUE
		);

		// if $responsexml is null then error in fetching product details URL from amazon API
		if (is_null($responsexml)) return FALSE;

		// work over XML response and extract required data
		$this->amazonresultlist = array();

		$xmlparser = xml_parser_create();
		xml_set_element_handler($xmlparser,array($this,'xmlparserstartelement'),array($this,'xmlparserendelement'));
		xml_set_character_data_handler($xmlparser,array($this,'xmlparserdata'));

		while ($responsexml) {
			if (!xml_parse($xmlparser,array_shift($responsexml),!$responsexml)) {
				die(
					sprintf('XML error: %s at line %d' . self::crlf,
					xml_error_string(xml_get_error_code($xmlparser)),
					xml_get_current_line_number($xmlparser))
				);
			}
		}

		xml_parser_free($xmlparser);

		// add final item to list
		$this->addamazoncurrentitemtolist();

		if (!$this->amazonresultlist) {
			// no results/valid results - exit
			$this->writeerrorlog('Unable to find album results for: [' . $artistname . ' / ' . $albumname . ']');
			return FALSE;
		}

		// find the best item from our results
		$lowestdistance = 9999;
		$bestitemkey = 0;
		foreach ($this->amazonresultlist as $key => $resultitem) {
			$curdistance =
				levenshtein($artistname,$this->removenoisewords($resultitem['artist'])) +
				levenshtein($albumname,$this->removenoisewords($resultitem['album']));

			if ($curdistance < $lowestdistance) {
				// found a better item
				$lowestdistance = $curdistance;
				$bestitemkey = $key;
			}
		}

		// save the best result item found
		$resultitem = $this->amazonresultlist[$bestitemkey];

		// download album image to temp location
		$albumimagedata = $this->fetchurl($resultitem['url']);
		// if $albumimagedata is null then error in fetching the album image from amazon
		if (is_null($albumimagedata)) return FALSE;

		file_put_contents(TEMP_IMAGE_PATH,$albumimagedata);
		list($imagewidth,$imageheight) = getimagesize(TEMP_IMAGE_PATH);

		// work out which axis to resize on
		$resizewidth = ceil($imagewidth * (TARGETIMAGE_HEIGHT / $imageheight));
		if ($resizewidth >= TARGETIMAGE_WIDTH) {
			// resize source amazon image by width
			$exec = $this->buildimagemagikexec($resizewidth,$inputtargetfile);

		} else {
			// resize source amazon image by height
			$exec = $this->buildimagemagikexec(
				'x' . (ceil($imageheight * (TARGETIMAGE_WIDTH / $imagewidth))),
				$inputtargetfile
			);
		}

		// execute image resize, putting image into its target location
		exec($exec);

		$this->writelog('Found album result for: [' . $artistname . ' / ' . $albumname . ']');
		$this->writelog('Image resize cmd: ' . $exec);
		$this->writelog('Saved image to: ' . $inputtargetfile);

		// success!
		return TRUE;
	}

	private function removenoisewords($inputstring) {

		$text = $inputstring;

		// remove some common noise words/symbols that will get in the way
		$text = str_replace(
			array(
				' a ',
				' and ',
				' the ',
				'&',
				'-',
				':',
			),
			' ',
			' ' . $text . ' '
		);

		// remove single quotes
		$text = str_replace('\'','',$text);

		// remove all redundant spaces from the string
		$text = preg_replace('/ +/',' ',$text);

		// trim result, lowercase and return
		return strtolower(trim($text));
	}

	private function fetchurl($inputurl,$inputchunked = FALSE) {

		$fp = fopen($inputurl,'r');
		if ($fp === FALSE) {
			// error fetching URL, most likely invalid characters in request URI
			$this->writeerrorlog('Unable to open URL: [' . $inputurl . ']');
			return NULL;
		}

		$response = array();
		while (!feof($fp)) $response[] .= fread($fp,1024);
		fclose($fp);

		return ($inputchunked) ? $response : implode('',$response);
	}

	function buildamazonrequesturl(array $inputparameterlist,$inputawsversion = '2009-06-01') {

		$query = array(
			'Service' => 'AWSECommerceService',
			'AWSAccessKeyId' => AMAZON_ACCESSKEY,
			'Timestamp' => date('Y-m-d\TH:i:s\Z'),
			'Version' => $inputawsversion,
		);

		$querylist = array_merge($query,$inputparameterlist);

		// do a case-insensitive, natural order sort on the array keys
		ksort($querylist);

		// create the signable string
		$templist = array();
		foreach ($querylist as $k => $v) {
			$templist[] = str_replace('%7E','~',rawurlencode($k) . '=' .rawurlencode($v));
		}

		// hash the AWS secret key and generate a signature for the request
		$generatedhash = hash_hmac(
			'sha256',
			"GET\n" . self::amazon_requesthost . "\n" . self::amazon_requestpath . "\n" . implode('&',$templist),
			AMAZON_SECRETKEY
		);

		$signature = '';
		$generatedhashlength = strlen($generatedhash);
		for ($i = 0;$i < $generatedhashlength;$i += 2) {
			$signature .= chr(hexdec(substr($generatedhash,$i,2)));
		}

		$querylist['Signature'] = base64_encode($signature);
		ksort($querylist);

		$templist = array();
		foreach ($querylist as $k => $v) {
			$templist[] = rawurlencode($k) . '=' . rawurlencode($v);
		}

		// return final request URL
		return 'http://' . self::amazon_requesthost . self::amazon_requestpath . '?' . implode('&',$templist);
	}

	private function xmlparserstartelement($inputparser,$inputname,$inputattributelist) {

		array_push($this->amazonresultpathlist,$inputname);
	}

	private function xmlparserendelement($inputparser,$inputname) {

		array_pop($this->amazonresultpathlist);
	}

	private function xmlparserdata($inputparser,$inputdata) {

		$xmlpath = implode('/',$this->amazonresultpathlist);

		if ($xmlpath == 'ITEMSEARCHRESPONSE/ITEMS/ITEM/ASIN') {
			// found start of a new item

			// validate item, if all required data found add to list
			$this->addamazoncurrentitemtolist();
			$this->amazonresultcurrent = array();

			return;
		}

		if ($xmlpath == 'ITEMSEARCHRESPONSE/ITEMS/ITEM/LARGEIMAGE/URL') {
			// save item image url
			$this->amazonresultcurrent['url'] = $inputdata;
			return;
		}

		if ($xmlpath == 'ITEMSEARCHRESPONSE/ITEMS/ITEM/LARGEIMAGE/WIDTH') {
			// save item image width
			$this->amazonresultcurrent['width'] = intval($inputdata);
			return;
		}

		if ($xmlpath == 'ITEMSEARCHRESPONSE/ITEMS/ITEM/LARGEIMAGE/HEIGHT') {
			// save item image height
			$this->amazonresultcurrent['height'] = intval($inputdata);
			return;
		}

		if ($xmlpath == 'ITEMSEARCHRESPONSE/ITEMS/ITEM/ITEMATTRIBUTES/ARTIST') {
			// save item album artist
			$this->amazonresultcurrent['artist'] = trim($inputdata);
			return;
		}

		if ($xmlpath == 'ITEMSEARCHRESPONSE/ITEMS/ITEM/ITEMATTRIBUTES/BINDING') {
			// ensure item is an Audio CD
			if ($inputdata == 'Audio CD') $this->amazonresultcurrent['isaudiocd'] = TRUE;
			return;
		}

		if ($xmlpath == 'ITEMSEARCHRESPONSE/ITEMS/ITEM/ITEMATTRIBUTES/TITLE') {
			// save item album title
			$this->amazonresultcurrent['album'] = trim($inputdata);
			return;
		}
	}

	private function addamazoncurrentitemtolist() {

		// does all data exist for item?
		foreach (array('isaudiocd','url','width','height','artist','album') as $item) {
			if (!isset($this->amazonresultcurrent[$item])) return;
		}

		// all data exists check image width/height are large enough
		if (
			($this->amazonresultcurrent['width'] < TARGETIMAGE_WIDTH) ||
			($this->amazonresultcurrent['height'] < TARGETIMAGE_HEIGHT)
		) return;

		// save to $this->amazonresultlist
		$this->amazonresultlist[] = $this->amazonresultcurrent;
	}

	private function buildimagemagikexec($inputresizeparameter,$inputtargetfile) {

		return
			IMAGEMAGICK_CONVERT . ' ' . TEMP_IMAGE_PATH . ' ' .
			'-resize ' . $inputresizeparameter . ' ' .
			'-gravity center ' .
			'-crop ' . TARGETIMAGE_WIDTH . 'x' . TARGETIMAGE_HEIGHT . '+0+0 ' .
			'-quality ' . IMAGEMAGICK_SAVE_QUALITY . ' ' .
			'"' . $inputtargetfile . '"';
	}

	private function writeline($inputmessage) {

		echo($inputmessage . "\r\n");
	}

	private function writelog($inputmessage) {

		$line = '[' . date('Y-m-d H:i:s') . '] ' . $inputmessage . "\r\n";
		fwrite($this->logfp,$line);
		echo($line);
	}

	private function writeerrorlog($inputmessage) {

		$line = '[' . date('Y-m-d H:i:s') . '] ' . $inputmessage . "\r\n";
		fwrite($this->logerrorfp,$line);
		echo('Error: ' . $line);
	}
}
