<?php
// vim: ts=3 sw=3 et
if (!defined("BASEPATH"))
   die("You are in a maze of twisty little passages, all alike.");

class Controller
{
   public $core;

   public function __construct()
   {
      $this->core = Serac::getInstance();
   }
}

