<?php

namespace Stanford\ProjSnyderCovid;

/** @var \Stanford\ProjSnyderCovid\ProjSnyderCovid $module */

use \REDCap;


$module->emDebug($_POST);

if (!$_POST) {
    die( "You cant be here");
}


if (!$_POST['origin_pid']) {
    die("No originating project ID set.");
}

$first_ct = $_POST['start_record'] ? $_POST['start_record'] : 0;
$last_ct = $_POST['last_record'] ? $_POST['last_record'] : NULL;

$origin_pid = $_POST['origin_pid'];

$data = $module->process($origin_pid, $first_ct, $last_ct);