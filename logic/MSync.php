<?php

namespace Logic;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnderflowException;
use UnexpectedValueException;

class MSync
{
	public const NOT_INITIALIZED = <<<'NDOC'
		This is not an msync managed directory.
		Use ‘msync init’ to create local workspace.
		
		NDOC;
	
	protected Opts   $opts;
	protected Report $report;

	public function __construct(array $argv)
	{
		$this->opts   = new Opts($argv);
		$this->report = new Report($this->opts->verbose);
	}

	public function run(){
		switch ($this->opts->verb) {
			case 'help':
				throw new HelpException();

			case 'init':
				$this->doInit();
				break;

			case 'pull':
				$this->doPull();
				break;

			case 'push':
				$this->doPush();
				break;

			case 'resolve':
				$this->doResolve();
				break;

			default:
				throw new UnexpectedValueException('Unknown verb.');
		}
	}
	
	protected function _assertFileExists(string $file, string $message = '')
	{
		if (!file_exists($file)) {
			throw new RuntimeException($message == '' ? '"' . $file . '" does not exist.' : $message);
		}
	}

	
	protected function doInit(): void
	{
		if (file_exists($this->opts->manifestPath)) {
			$cont = 'no';   //  default reply
			$this->report->shout('This is an msync managed directory!');
			$this->report->shout('Do you wish to overwrite all working files? [y|N]: ', false);
			if ($rline = readline('')) {
				$cont = $rline;
			}

			if (substr(strtolower($cont), 0, 1) !== 'y') {
				$this->report->out('Canceled.' . PHP_EOL);
				return;
			}
		}

		$this->report->out('Opening connection to remote server.');
		$sync = new Sync($this->opts);

		$this->report->out('Retrieving remote file list.');
		$remoteList = $sync->getRemoteList();

		$this->report->out('Pulling non-existent or files that differ.');
		$sync->pullFiles($remoteList);

		$this->report->out('Writing file info to manifest.');
		(new Manifest($this->opts->manifestPath))->write($remoteList);

		$this->report->out('Initialization complete.');
	}


	protected function doPull(): void
	{
		$this->_assertFileExists($this->opts->manifestPath, self::NOT_INITIALIZED);

		//  Build list of remote files that have changed or are new.
		$this->report->out('Opening connection to remote server.');
		$sync     = new Sync($this->opts);
		$manifest = new Manifest($this->opts->manifestPath);

		$this->report->out('Retrieving remote file list.');
		$remoteList = $sync->getRemoteList();

		$this->report->out('Finding remote files that have changed.');
		$remoteChanged = $manifest->getChanged($remoteList);

		if (count($remoteChanged) === 0) {
			$this->report->shout('Nothing to do.');
			return;
		}

		//  Build list of local files that have changed.
		$this->report->out('Retrieving local files that have changed.');
		$localChanged = $manifest->getChanged($sync->getLocalList());

		//  Copy files from remote directory...
		//      If the local file has changed then
		//          put it in the conflict directory.
		//      Else,
		//          copy it in place.
		$conflicted = false;
		foreach ($remoteChanged as $rChangedFname => $rChangedInfo) {
			if (isset($localChanged[$rChangedFname]) && ($rChangedInfo['ftype'] === 'f')) {
				//  If a local file is changing from a link to a regular file then
				//      first delete the link or data will write to the wrong place.
				if (is_link($localChanged[$rChangedFname])) {
					unlink($rChangedFname);
				}

				$sync->pullToConflict($rChangedFname, $rChangedInfo);
				$conflicted = true;
			}
			else {
				$sync->pullFile($rChangedFname, $rChangedInfo);
			}
		}

		//  Write changed and new file stats into the manifest.
		$manifest->update($remoteChanged);

		//  If there are files in conflict then
		//      launch user's preferred "diff" engine to compare files.
		//      Or print paths to directories to compare.
		if ($conflicted) {
			exec('phpstorm diff "' . $this->opts->localPath . '" "' . $this->opts->conflictPath . '"');
		}
		else {
			$this->report->out('No conflicts.');
		}
	}


	protected function doPush(): void
	{
		$this->_assertFileExists($this->opts->manifestPath, self::NOT_INITIALIZED);

		if (!file_exists($this->opts->conflictPath) || !is_dir($this->opts->conflictPath)) {
			mkdir($this->opts->conflictPath, 0755, true);
		}

		//  Check conflict directory for files.
		//  If any, abort push and advise user.
		$rdi = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->opts->conflictPath),
			FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
		);
		foreach ($rdi as $f) {
			if ($f->getType() === 'file') {
				$this->report->shout('Files are still in conflict. Aborting.');
				return;
			}
		}

		//  Build list of remote files that have changed or are new.
		//  If any, abort push and advise user.
		$this->report->out('Opening connection to remote server.');
		$sync     = new Sync($this->opts);
		$manifest = new Manifest($this->opts->manifestPath);

		$this->report->out('Retrieving remote file list.');
		$remoteList = $sync->getRemoteList();

		$this->report->out('Finding remote files that have changed.');
		$remoteChanged = $manifest->getChanged($sync->getRemoteList());

		if (count($remoteChanged) > 0) {
			$this->report->shout('There are changed files in the remote directory.');
			$this->report->shout('Perform a "pull" and check changes. Aborting.');
			return;
		}

		//  Build list of local files that have changed or are new.
		$this->report->out('Retrieving local files that have changed or are new.');
		$localChanged = $manifest->getChanged($sync->getLocalList());

		if (count($localChanged) === 0) {
			$this->report->out('Nothing to do.');
			return;
		}

		//  Push changed and new files to remote directory.
		$sync->pushFiles($localChanged, $remoteList);

		//  Write changed and new file stats into the manifest.
		$manifest->update($localChanged);
	}


	protected function doResolve(): void
	{
		$this->_assertFileExists($this->opts->manifestPath, self::NOT_INITIALIZED);

		if (!isset($argv[$this->opts->restIndex + 1])) {
			throw new UnderflowException('Missing path to file.');
		}

		$relativePath = ltrim($argv[$this->opts->restIndex + 1]);
		$fullPath     = realpath($this->opts->conflictPath . '/' . $relativePath);

		//  Written as assertions.
		switch (false) {
			case (strpos($relativePath, '..') === false):
				throw new UnexpectedValueException('Cannot point to file outside project.');

			case ($relativePath[0] !== '/'):
				throw new UnexpectedValueException('Parameter must be a relative path to file.');

			case file_exists($fullPath):
				throw new UnexpectedValueException('File has been resolved or does not exist.');

			case is_file($fullPath):
				throw new UnexpectedValueException('Parameter must be a relative path to a regular file.');
		}

		unlink($fullPath);
		$this->report->out('"' . $relativePath . '" has been removed.');
	}

}