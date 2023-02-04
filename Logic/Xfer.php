<?php

namespace Logic;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SFTP;

/**
 * Xfer class.
 *
 * @param array $remoteList
 * @param array $localList
 */
class Xfer
{
	/**
	 * Settings from command line and config file.
	 */
	protected Options $opts;

	/**
	 * Functional classes.
	 */
	protected SFTP $sftp;

	public function __construct(Options $opts)
	{
		$this->opts = $opts;

		if ($this->opts->localPath !== getcwd()) {
			chdir($this->opts->localPath);
		}

		$key = PublicKeyLoader::load(file_get_contents($this->opts->sshKeyPath));

		$this->sftp = new SFTP($this->opts->host);
		if (!$this->sftp->login($this->opts->user, $key)) {
			throw new UnableToConnectException('Login failed');
		}

		$this->sftp->enableDatePreservation();
		$this->sftp->chdir($this->opts->remotePath);
	}

	public function __get($name)
	{
		return $this->$name;
	}

	public function pullFile(string $remotefname, array $remoteInfo, bool $toConflict = false): void
	{
		if ($remoteInfo['ftype'] === 'd') {
			return;
		}

		$localfname = ($toConflict ? $this->opts->conflictPath : '') . $remotefname;
		$tempName   = $localfname . Options::TEMP_SUFFIX;

		$dname = preg_replace('@^(.*)/[^/]+$@', '$1', $tempName);
		if (!file_exists($dname)) {
			mkdir($dname, 0775, true);
		}

		switch ($remoteInfo['ftype']) {
			case 'f':
				$this->sftp->get($remotefname, $tempName);
			break;

			case 'l':
				$target = $this->sftp->readlink($remotefname);
				symlink($target, $tempName);
			break;
		}

		rename($tempName, $localfname);
	}

	public function pushFile(string $localfname, array $localInfo): void
	{
		if ($localInfo['ftype'] === 'd') {
			return;
		}

		$tempName = $localfname . Options::TEMP_SUFFIX;

		$dname = preg_replace('@^(.*)/[^/]+$@', '$1', $localfname);
		if (!isset($remoteList[$dname])) {
			$this->sftp->mkdir($dname, 0775, true);
		}

		switch ($localInfo['ftype']) {
			case 'f':
				$this->sftp->put($tempName, $localfname, SFTP::SOURCE_LOCAL_FILE);

//				$this->sftp->exec(<<<HDOC
//					chmod -f 664 "{$this->opts->remotePath}/{$tempName}"
//					chgrp -f {$this->opts->group} "{$this->opts->remotePath}/{$tempName}"
//					mv -f "{$this->opts->remotePath}/{$tempName}" "{$this->opts->remotePath}/{$localfname}"
//
//					HDOC
//				);
			break;

			case 'l':
				$target = readlink($localfname);
				$this->sftp->symlink($target, $tempName);
				$this->sftp->touch($tempName, $localInfo['mtime'], $localInfo['mtime']);

//				$this->sftp->exec('ln -fs "' . $target . '" "' . $tempName . '"');
//				$this->sftp->exec(<<<HDOC
//					touch -m -d @{$localInfo['mtime']} "{$this->opts->remotePath}/{$tempName}"
//					chmod -f 664 "{$this->opts->remotePath}/{$tempName}"
//					chgrp -f {$this->opts->group} "{$this->opts->remotePath}/{$tempName}"
//					mv -f "{$this->opts->remotePath}/{$tempName}" "{$this->opts->remotePath}/{$localfname}"
//
//					HDOC
//				);
		}

		$this->sftp->chmod($tempName, 0664);
		$this->sftp->exec("chgrp -f {$this->opts->group} '{$this->opts->remotePath}/{$tempName}'\n");
		$this->sftp->delete($localfname);
		$this->sftp->rename($tempName, $localfname);
	}

	/**
	 * Directories must be done after placing files so that mod times aren't changed.
	 * Empty directories will not be copied.
	 */
	public function updateLocalDirectories(FileList $fileList): void
	{
		foreach ($fileList as $fname => $finfo) {
			if ($finfo['ftype'] === 'd' && file_exists($fname)) {
				touch($fname, $finfo['mtime']);
			}
		}
	}

	/**
	 * Directories must be done after placing files so that mod times aren't changed.
	 * Empty directories will not be copied.
	 */
	public function updateRemoteDirectories(array $fileList)
	{
		foreach ($fileList as $fname => $finfo) {
			if ($finfo['ftype'] === 'd' && $this->sftp->file_exists($fname)) {
				$this->sftp->touch($fname, $finfo['mtime'], $finfo['mtime']);
			}
		}
	}

}
