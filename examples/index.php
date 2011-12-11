<?php
// vim: ts=3 sw=3 et

error_reporting(-1);

define('BASEPATH', realpath(dirname(__FILE__)));
define('APP', 'app');

include '../serac.php';

$system = Serac::initialize(array(
   'class_dir' => array(APP.'/library', APP),
   'autoload'  => array('Config')
));

/*
 * /take/it/easy  => Controller_Take->it('easy');
 * /take          => Controller_Take->index();
 * /              => Controller_Home->index()
 *
 */

$system[] =
   array(
      '/?/?/*' =>
         array(
            'class'     => 'Controller_$2',  // $1 contains http method
            'function'  => '$3',
            'arguments' => '$4'
         ),
      '/?' =>
         array(
            'class'     => 'Controller_$2',
            'function'  => 'index'
         ),
      '/' =>
         array(
            'class'     => 'Controller_Home',
            'function'  => 'index'
         )
   );

$system->run();
