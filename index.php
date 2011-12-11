<?php
// vim: et ts=3 sw=3
define('EXT', '.php');
define('DS', DIRECTORY_SEPARATOR);
define('BASEPATH', realpath(dirname(__FILE__)).DS);
define('CLASSDIR', 'classes');
define('CLASSPATH', BASEPATH.CLASSDIR.DS);

$host = (empty($_SERVER['HTTP_HOST']) ? '127.0.0.1' : $_SERVER['HTTP_HOST']);
define('SYSTEM_HOST', $host);
unset($host);

require_once(CLASSPATH.'serac'.DS.'base'.EXT);
require_once(CLASSPATH.'serac'.EXT);

$system = new Serac;

// add some routes here

$system->run();
