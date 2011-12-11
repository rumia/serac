<?php
// vim: ts=3 sw=3 et
if (!defined("BASEPATH"))
   die("You are in a maze of twisty little passages, all alike.");

class Controller_Home extends Controller
{
   public function __construct()
   {
      parent::__construct();
      $this->core->config->load('example');
   }

   public function index()
   {
      printf('%s: This is index', $this->core->config->title);
   }

   public function test($message = NULL)
   {
      printf('%s: This is test: %s', $this->core->config->title, $message);
   }
}

