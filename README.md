# NOT WORKING YET

The help screen serves as the outline of things to come.

# MSync
This has the basic features and adds a manifest file, thus “msync”. It aids in synchronizing two active directories, one of them being remote.

Help:
```
./msync.php -H
```

## ~/.*profile

I've added this line to my  ".zprofile" file (MacOS Ventura):

~~~
alias msync=~/Documents/Diskerror/MSync/msync
~~~
So now asking for the help screen goes like:
~~~
msync -H
~~~

## Help Screen

```
Usage:
	msync -H | --help
	msync [-d|--directory dir] [-h|--host hostname]
		[-p|--password passwd] [-u|--username uname]
		init | check | pull | push | resolve


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

