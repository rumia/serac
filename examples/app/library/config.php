<?php
// vim: ts=3 sw=3 et
if (!defined("BASEPATH"))
   die("You are in a maze of twisty little passages, all alike.");

class Config extends ArrayObject
{
   const DIR_NAME = 'config';

   public function __construct()
   {
      $sys = Serac::getInstance();
      parent::__construct(array(), ArrayObject::ARRAY_AS_PROPS);
   }

   public function load($file)
   {
      $result = Serac::includeFile(
         $file,
         APP.DIRECTORY_SEPARATOR.self::DIR_NAME,
         NULL, TRUE);

      if (!is_array($result)) return(FALSE);
      $this->exchangeArray(array_merge($this->getArrayCopy(), $result));
   }
}

