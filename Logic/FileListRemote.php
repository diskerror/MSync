<?php

namespace Logic;

use Exception;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SFTP;
use UnexpectedValueException;

class FileListRemote extends FileList
{

	public function init(): void
	{
		$sshKey = PublicKeyLoader::load(file_get_contents($this->opts->sshKeyPath));
		$remote    = new SFTP($this->opts->host);

		if (!$remote->login($this->opts->user, $sshKey)) {
			throw new UnableToConnectException('Login failed');
		}

		//	Also removes all comments.
		$cmd = php_strip_whitespace('find.php');
		//	remove everything before the first '$', ie. "<?php\n" & etc.
		$cmd = strstr($cmd, '$ret');
		$cmd = strtr($cmd, "'", '"');    //	insure all single-quotes are double-quotes
		$cmd = 'try{ini_set("memory_limit","-1");' . $cmd . '}catch(Throwable $t){$ret["__exception"]=$t->__toString();}';

		//	Replace variables with literal strings.
		$cmd = str_replace(
			['$path', '$plength', '$regexIgnore', '$regexNoHash', '$hashAlgo'],
			[
				'"' . $this->opts->remotePath . '"',
				strlen($this->opts->remotePath),
				'"' . $this->opts->regexIgnore . '"',
				'"' . $this->opts->regexNoHash . '"',
				'"' . Options::HASH_ALGO . '"',
			],
			$cmd
		);

		if(false) {
			$response = $remote->exec("php -r '{$cmd}echo json_encode(\$ret);'");

			//	Remove text before first '[' or '{'.
			$response = preg_replace('/^[^[{]*(.+)$/sAD', '$1', $response);
		}
		else {
			//	It's written to a file (file_put_contents) first because some systems can't do this in memory.
			//	The file is written in the users home directory as the SSH2 object doesn't retain working directory.
			$remote->exec("php -r '{$cmd}file_put_contents(\"msync.json\",json_encode(\$ret));'");
			$remote->exec("gzip -n msync.json");
			$response = gzdecode($remote->get("msync.json.gz"));
			$remote->delete("msync.json.gz");
		}

		if ($response == '') {
			throw new UnexpectedValueException('Blank response from remote.');
		}

		$this->fileList =
			json_decode($response, JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);

		if (key_exists('__exception', $this->fileList)) {
			var_export($this->fileList);
			throw new Exception($this->fileList['__exception']);
		}
	}

}
