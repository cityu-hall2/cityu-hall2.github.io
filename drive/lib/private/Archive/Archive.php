<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Christopher Schäpers <kondou@ts.unde.re>
 * @author Felix Moeller <mail@felixmoeller.de>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Archive;

abstract class Archive{
	/**
	 * Open any of the supported archive types
	 *
	 * @param string $path
	 * @return Archive|void
	 */
	public static function open($path) {
		$mime = \OC::$server->getMimeTypeDetector()->detect($path);

		switch($mime) {
			case 'application/zip':
				return new ZIP($path);
			case 'application/x-gzip':
				return new TAR($path);
			case 'application/x-bzip2':
				return new TAR($path);
		}
	}

	/**
	 * @param $source
	 */
	abstract function __construct($source);
	/**
	 * add an empty folder to the archive
	 * @param string $path
	 * @return bool
	 */
	abstract function addFolder($path);
	/**
	 * add a file to the archive
	 * @param string $path
	 * @param string $source either a local file or string data
	 * @return bool
	 */
	abstract function addFile($path, $source='');
	/**
	 * rename a file or folder in the archive
	 * @param string $source
	 * @param string $dest
	 * @return bool
	 */
	abstract function rename($source, $dest);
	/**
	 * get the uncompressed size of a file in the archive
	 * @param string $path
	 * @return int
	 */
	abstract function filesize($path);
	/**
	 * get the last modified time of a file in the archive
	 * @param string $path
	 * @return int
	 */
	abstract function mtime($path);
	/**
	 * get the files in a folder
	 * @param string $path
	 * @return array
	 */
	abstract function getFolder($path);
	/**
	 * get all files in the archive
	 * @return array
	 */
	abstract function getFiles();
	/**
	 * get the content of a file
	 * @param string $path
	 * @return string
	 */
	abstract function getFile($path);
	/**
	 * extract a single file from the archive
	 * @param string $path
	 * @param string $dest
	 * @return bool
	 */
	abstract function extractFile($path, $dest);
	/**
	 * extract the archive
	 * @param string $dest
	 * @return bool
	 */
	abstract function extract($dest);
	/**
	 * check if a file or folder exists in the archive
	 * @param string $path
	 * @return bool
	 */
	abstract function fileExists($path);
	/**
	 * remove a file or folder from the archive
	 * @param string $path
	 * @return bool
	 */
	abstract function remove($path);
	/**
	 * get a file handler
	 * @param string $path
	 * @param string $mode
	 * @return resource
	 */
	abstract function getStream($path, $mode);
	/**
	 * add a folder and all its content
	 * @param string $path
	 * @param string $source
	 * @return boolean|null
	 */
	function addRecursive($path, $source) {
		$dh = opendir($source);
		if(is_resource($dh)) {
			$this->addFolder($path);
			while (($file = readdir($dh)) !== false) {
				if($file=='.' or $file=='..') {
					continue;
				}
				if(is_dir($source.'/'.$file)) {
					$this->addRecursive($path.'/'.$file, $source.'/'.$file);
				}else{
					$this->addFile($path.'/'.$file, $source.'/'.$file);
				}
			}
		}
	}
}
