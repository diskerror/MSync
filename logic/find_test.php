#!/usr/bin/env php
<?php

use Logic\Opts;

require 'Opts.php';
$opts = new Opts($argv);

$path        = $opts->localPath;
$plength     = strlen($path);
$regexIgnore = '@^(.*/\..*|/vendor/.*)$@';
$regexNoHash = '@^(.*\.jpg|.*\.exe)$@';
$hashAlgo    = Opts::HASH_ALGO;

require 'find.php';

echo json_encode($ret, JSON_PRETTY_PRINT), "\n";
