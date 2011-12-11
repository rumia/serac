<?php
// vim: et ts=3 sw=3
define('BASEPATH', realpath(dirname(__FILE__)));

include 'serac.php';

$system = new Serac(array('base_path' => '..'));

// add some routes here

$system['*'] = array(
   'class'  => 'Classes_Foo',
   'function' => 'index'
);

$system->run();
