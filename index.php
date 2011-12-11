<?php
// vim: et ts=3 sw=3
define('BASEPATH', realpath(dirname(__FILE__)));

include 'serac.php';

$system = Serac::initialize();

// add some routes here

$system['*'] = function () {
   echo 'Hello World!';
};

$system->run();
