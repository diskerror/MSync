<?php

namespace Logic;

use Ds\Queue;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SFTP;

/**
 * Xfer class.
 */
class Xfer
{
	protected Options $opts;
	protected SFTP    $remote;

	protected Queue $remoteDirsToUpdate;
	protected Queue $localDirsToUpdate;


	public function __construct(Options $opts)
	{
		$this->opts = $opts;

		$this->remoteDirsToUpdate = new Queue();
		$this->localDirsToUpdate  = new Queue();

		if ($this->opts->localPath !== getcwd()) {
			chdir($this->opts->localPath);
		}

		$key = PublicKeyLoader::load(file_get_contents($this->opts->sshKeyPath));

		$this->remote = new SFTP($this->opts->host);
		if (!$this->remote->login($this->opts->user, $key)) {
			throw new UnableToConnectException('Login failed');
		}

		$this->remote->enableDatePreservation();
		$this->remote->chdir($this->opts->remotePath);
	}

	public function __destruct()
	{
		$this->updateDirectories();
	}


	public function __get($name)
	{
		return $this->$name;
	}

	public function pullFile(string $remoteFname, array $remoteInfo, bool $toConflict = false): void
	{
		$localFname = ($toConflict ? $this->opts->conflictPath : '') . $remoteFname;
		$tempName   = $localFname . Options::TEMP_SUFFIX;

		if (strpos($tempName, '/') !== false) {
			$dname = preg_replace('@^(.*?)[^/]+$@', '$1', $tempName);
			if (!file_exists($dname) || !is_dir($dname)) {
				mkdir($dname, 0775, true);
			}
		}

		switch ($remoteInfo['ftype']) {
			case 'f':
//				$this->remote->get($remoteFname, $tempName);
				file_put_contents($tempName, gzdecode($this->remote->exec("gzip -cn '{$this->opts->remotePath}/{$remoteFname}'")));
				rename($tempName, $localFname);
			break;

			case 'l':
				$target = $this->remote->readlink($remoteFname);
				symlink($target, $tempName);
				rename($tempName, $localFname);
			break;

			case 'd':
				$remoteInfo['fname'] = $remoteFname;
				$this->localDirsToUpdate->push($remoteInfo);
			break;
		}
	}

	public function pushFile(string $localFname, array $localInfo): void
	{
		$tempName = $localFname . Options::TEMP_SUFFIX;

		if (strpos($tempName, '/') !== false) {
			$dname = preg_replace('@^(.*?)[^/]+$@', '$1', $tempName);
			if (!isset($remoteList[$dname])) {
				$this->remote->mkdir($dname, 0775, true);
			}
		}

		switch ($localInfo['ftype']) {
			case 'f':
				$this->remote->put($tempName, $localFname, SFTP::SOURCE_LOCAL_FILE);
				$this->remote->chmod($tempName, 0664);
				$this->remote->chgrp($tempName, $this->opts->group);
				$this->remote->delete($localFname);
				$this->remote->rename($tempName, $localFname);
			break;

			case 'l':
				$target = readlink($localFname);
				$this->remote->symlink($target, $tempName);
				$this->remote->touch($tempName, $localInfo['mtime'], $localInfo['mtime']);
				$this->remote->chmod($tempName, 0664);
				$this->remote->exec("chgrp -f {$this->opts->group} '{$this->opts->remotePath}/{$tempName}'\n");
				$this->remote->delete($localFname);
				$this->remote->rename($tempName, $localFname);
			break;

			case 'd':
				$localInfo['fname'] = $localFname;
				$this->remoteDirsToUpdate->push($localInfo);
			break;
		}
	}

	/**
	 * Directories must be done after placing files so that mod times are transferred.
	 * Empty directories have not been created so they don't get updated.
	 */
	protected function updateDirectories(): void
	{
		while (!$this->localDirsToUpdate->isEmpty()) {
			$d = $this->localDirsToUpdate->pop();
			if (file_exists($d['fname'])) {
				touch($d['fname'], $d['mtime']);
			}
		}

		while (!$this->remoteDirsToUpdate->isEmpty()) {
			$d = $this->remoteDirsToUpdate->pop();
			if ($this->remote->file_exists($d['fname'])) {
				$this->remote->touch($d['fname'], $d['mtime'], $d['mtime']);
			}
		}
	}

}
