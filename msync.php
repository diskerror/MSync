#!php
<?php
/**
 * msync
 * Synchronize directories like rsync but with an additional manifest so that
 * changes on the remote system before damage is done.
 *
 * Written using MacOS 13.1 Ventura for the local workstation and
 * connecting to a server running Debian 10 (findutils).
 */

use Model\Exceptions\HelpException;
use Model\Exceptions\NotInitializedException;
use Model\MSync;

require 'vendor/diskerror/autoload/autoload.php';

define('USAGE', <<<'DEFINE_USAGE'
Usage:
	msync -H | --help
	msync [-d|--directory dir] [-h|--host hostname]
		[-p|--password passwd] [-u|--username uname]
		init | check | pull | push | resolve

DEFINE_USAGE
);

define('HELP_PAGE', <<<'DEFINE_HELP_PAGE'


DESCRIPTION

This program aids in the synchronizing of two changeable directories where
changes to both directories must be kept. This poses a problem for utilities
like rsync where there are three (afaik) choices from the remote directory.
1) skip the newer files and not know what was sent, 2) write over them (not
good), and 3) check a list of changes to be done from a change report, such
as using "rsync -in src dest"

This program also ignores files asymetrically.
For instance, files in a directory named "cache" in the remote directory might
need to be in the local directory so that a step debugger can see them and step
through them locally, but should not be pushed back to the remote directory as
any change to those files, possibly copied from another remote directory used
for development testing, would cause unpredictable behaviour.


VERBS

	init:
		Copies remote directory into the current or specified directory. Only
		new or changed files are copied. Nothing is deleted.
	
	check:
		Checks the non-ignored files on the remote directory to see if they
		have changed since last time a 'check', 'pull', or 'push' was
		performed.
	
	pull:
		Pull new files from remote directory. If a file has been changed local-
		ly then the changed file will be placed into a temporary directory for
		the user to check and merge changes and a conflict will be recorded for
		that file.
	
	push:
		Push changed files to remote directory. If any file in the remote
		directory has changed since 'check' was last run the file conflict has
		not been resolved then no files are copied and a warning message is
		displayed.

	resolve:
		Removes conflict mark from file. The local file will now overwrite the
		same file in the remote directory during the next push. This verb re-
		quires a relative file name.


OPTIONS:

	-H  This screen.
	
	-d, --directory
		Specify the local working directory instead of the current working
		directory.
	
	-h, --host
		Remote host name or IP address. This will override the setting in the
		config file.
	
	-p, --password
		[not working]
		Specify a password to log into the remote system. If there is no pass-
		word then the user's ssh key, "~/.ssh/id_rsa" is used.
	
	-u, --username
		Overrides the username set in the config file. 


CONFIG FILE
The configuration file is in INI style and kept in the local working directory
in a hidden directory named ".msync/config.ini". Their usage should be clear.

host       = 192.168.1.77
remotePath = /var/www/html
user       =
sshKeyPath = ~/.ssh/id_rsa
password   =

These will require explanation (soon).
IGNORE_REGEX
NEVER_HASH
NO_PUSH_REGEX


DEFINE_HELP_PAGE
);


$exit = 0;

////////////////////////////////////////////////////////////////////////////////////////////////////
//	MAIN

try {
	$msync  = new MSync();

	if (!isset($argv[$msync->restIndex])) {
		fprintf(STDERR, USAGE . PHP_EOL);
		exit(1);
	}

	ini_set('memory_limit', '512M');

	//  Do command verb.
	switch ($argv[$msync->restIndex]) {
		case 'init':
			if ($msync->assertInitialized()) {
				$cont = 'no';   //  default reply
				fprintf(STDOUT, 'This is an msync managed directory!' . PHP_EOL);
				fprintf(STDOUT, 'Do you wish to overwrite all working files? [y|N]:');
				if ($rline = readline(' ')) {
					$cont = $rline;
				}

				if (substr(strtolower($cont), 0, 1) !== 'y') {
					fprintf(STDOUT, 'Canceled.' . PHP_EOL);
					break;
				}

				unlink(MSync::MANIFEST_FILE);
			}

			$remoteArray = $msync->getRemoteList();
			$msync->pullFiles($remoteArray);
			$msync->initializeManifest($remoteArray);

			$msync->report->out(PHP_EOL . 'Initialization complete.');
			break;

		case 'check':
			if (!$msync->assertInitialized()) {
				throw new NotInitializedException();
			}
			$remoteArray = $msync->getRemoteList();
			$devArray    = $msync->getDevList();
			break;

		case 'push':
			if (!$msync->assertInitialized()) {
				throw new NotInitializedException();
			}
			//  TODO: finnish this
			break;

		case 'pull':
			if (!$msync->assertInitialized()) {
				throw new NotInitializedException();
			}

			$remoteArray = $msync->getRemoteList();
			$devArray    = $msync->getDevList();
			//  TODO: finnish this


//			$fp = fopen('/Users/reid/Desktop/ct_remote.txt', 'wb');
//			ftruncate($fp, 0);
//			foreach ($remoteArray as $e) {
//				fprintf($fp, "%s; size: %u\n",
//					$e['name'], $e['size']);
//			}
//			fclose($fp);
//
//			$fp = fopen('/Users/reid/Desktop/ct_dev.txt', 'wb');
//			ftruncate($fp, 0);
//			foreach ($devArray as $e) {
//				fprintf($fp, "%s; size: %u\n",
//					$e['name'], $e['size']);
//			}
//			fclose($fp);
//			return;


			reset($remoteArray);
			reset($devArray);

			$re = current($remoteArray);
			$de = current($devArray);

			$onlyOnRemote = [];
			$onlyOnDev    = [];
			$timeDiffers  = [];
			$sizeDiffers  = [];

			do {
				if ($re['fname'] < $de['fname']) {
					$onlyOnRemote[] = $re;
					//		printf("Only on remote: %s; size: %u; mod time: %s\n",
					//			$re['fname'], $re['sizeb'], date('Y-m-d H:i:s', $re['modts']));
					$re = next($remoteArray);
					continue;
				}
				elseif ($re['fname'] > $de['fname']) {
					$onlyOnDev[] = $de;
					//		printf("Only in dev: %s; size: %u; mod time: %s\n",
					//			$de['name'], $de['size'], date('Y-m-d H:i:s', $de['time']));
					$de = next($devArray);
					continue;
				}
				elseif ($re['modts'] !== $de['modts']) {
					$timeDiffers[] = [
						'file name'   => $de['fname'],
						'dev time'    => $de['modts'],
						'remote time' => $re['modts'],
					];
					//		printf("Different mod times: %s; dev mod time: %s; remote mod time: %s\n",
					//			$de['fname'], date('Y-m-d H:i:s', $de['modts']), date('Y-m-d H:i:s', $re['modts']));
				}
				elseif ($re['size'] !== $de['size']) {
					$sizeDiffers[] = [
						'file name'   => $de['fname'],
						'dev size'    => $de['sizeb'],
						'remote size' => $re['sizeb'],
					];
					//		printf("DIFFERENT SIZES: %s; dev size: %u; remote size: %u\n",
					//			$de['fname'], $de['sizeb'], $re['sizeb']);
				}

				$re = next($remoteArray);
				$de = next($devArray);
			} while ($re !== false && $de !== false);


			printf("\nOnly on remote:\n");
			foreach ($onlyOnRemote as $or) {
				printf("%s\n", $or['fname']);
			}

			printf("\nOnly in dev:\n");
			foreach ($onlyOnDev as $od) {
				printf("%s\n", $od['fname']);
			}

			printf("\nTimes differ (later time):\n");
			foreach ($timeDiffers as $td) {
				printf("%s (%s)\n", $td['file name'], $td['dev time'] > $td['remote time'] ? 'dev' : 'remote');
			}

			printf("\nSizes differ:\n");
			foreach ($sizeDiffers as $sd) {
				printf("%s\n", $sd['file name']);
			}
			break;

		default:
			fprintf(STDERR, USAGE);
			exit(1);
	}
}
catch (HelpException $e) {
	fprintf(STDOUT, USAGE);
	fprintf(STDOUT, HELP_PAGE);
}
catch (NotInitializedException $e) {
	$msync->report->error('This is not an msync managed directory.');
	$msync->report->error('Use ‘msync init’ to create local workspace.');
	$exit = 1;
}
catch (Throwable $t) {
	fprintf(STDERR, $t);
	$exit = $t->getCode();
}

exit($exit);
