<?php

global $Wcms;

include_once __DIR__ . "/Store.php";

$Store = new Store(true);
$Store->init();
$Store->attach();
