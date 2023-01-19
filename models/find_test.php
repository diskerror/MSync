#!/usr/bin/env php
<?php

use Model\Opts;

require 'Opts.php';
$opts = new Opts($argv);

$path        = $opts->localPath;
$plength     = strlen($opts->localPath);
$regexIgnore = Opts::heredocToRegex($opts->IGNORE_REGEX);
$regexNoHash = Opts::heredocToRegex($opts->NO_PUSH_REGEX);
$hashAlgo    = Opts::HASH_ALGO;

require 'find.php';

var_export($rtval);
