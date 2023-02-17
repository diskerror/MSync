<?php
/**
 * Find files in a directory by regular expression.
 * Returns name, type, size, modification time, and hash of file.
 *
 * The relative file path uses the leading slash to aid with regex matching
 * and is then removed in the final result.
 *
 * Requires these variables to be set before including:
 *
 * @param $path             string    Absolute path to directory, without trailing slash (/).
 * @param $plength          int       Character count of $path.
 * @param $regexIgnore      string    Regex pattern of file names to ignore.
 * @param $regexNoHash      string    Regex pattern of file names not to hash.
 * @param $hashName         int       Character count of $path.
 *
 * @sets  $ret				array     Array of name/value pairs (associative array) with info about each file.
 */

$ret = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $f) {
	$fn = $f->getPathname();        //	full path name
	$rn = substr($fn, $plength);    //	Leaves relative path name with leading slash.
	$t  = $f->getType()[0];            //	file type (d, f, or l)
	if (preg_match($regexIgnore, $rn) === 0) {
		$fname = ($t === "d") ? substr($rn, 1, -2) : substr($rn, 1);
		//	Sending as indexed array requires this order.
		$ret[$fname] = [
			"ftype"   => $t,
			"sizeb"   => ($t === "f") ? $f->getSize() : null,
			"mtime"   => ($t !== 'l') ? $f->getMTime() : null,
			"hashval" => ($t === "f" && preg_match($regexNoHash, $rn) === 0) ? hash_file($hashAlgo, $fn) : "",
			"owner"   => ($t !== 'l') ? posix_getpwuid($f->getOwner())["name"] : "",
		];
	}
}
