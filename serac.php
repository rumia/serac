<?php
// vim: ts=3 sw=3 et
if (!defined('BASEPATH'))
   define('BASEPATH', realpath(dirname(__FILE__)));

class Serac implements ArrayAccess
{
   const EXT               = '.php';
   const VERSION           = '0.1a-m';
   const DEFAULT_METHOD    = 'get';
   const DEFAULT_URI_MODE  = 'path_info';

   protected static $instance;

   protected $uri;
   protected $method;
   protected $routing   = array();
   protected $registry  = array();

   protected static $valid_methods =
      array(
         'get', 'post', 'put', 'delete', 'head', 'options'
      );

   public $options = 
      array(
         'base_url'  => 'http://localhost',
         'uri_mode'  => Serac::DEFAULT_URI_MODE,
         'encoding'  => 'UTF-8'
      );

   protected function __construct() {}

   public static function initialize(array $options = NULL)
   {
      if (Serac::$instance instanceOf Serac)
         return Serac::$instance;
      else
      {
         Serac::$instance = new Serac;

         spl_autoload_register(array(Serac::$instance, 'classLoader'));
         if (is_array($options) && count($options))
            Serac::$instance->setOptions($options);

         if (ini_get('register_globals')) Serac::$instance->cleanupGlobals();

         if (get_magic_quotes_gpc())
         {
            $_GET    = Serac::cleanupMagicQuotes($_GET);
            $_POST   = Serac::cleanupMagicQuotes($_POST);
            $_COOKIE = Serac::cleanupMagicQuotes($_COOKIE);
         }

         if (PHP_SAPI != 'cli')
         {
            Serac::$instance->method = strtolower(
               Serac::getServerVar('HTTP_METHOD', Serac::DEFAULT_METHOD)
            );

            Serac::$instance->prepareURI();
         }
         else Serac::$instance->method = Serac::DEFAULT_METHOD;

         mb_internal_encoding(Serac::$instance->getOptions('encoding', 'UTF-8'));

         //set_exception_handler(array('Signal', 'handleException'));

         $autoload = Serac::$instance->getOptions('autoload');
         if (!empty($autoload)) Serac::$instance->register($autoload);

         return Serac::$instance;
      }
   }

   public static function getInstance()
   {
      return Serac::$instance;
   }

   protected function prepareURI()
   {
      $uri  = '';
      $mode = $this->getOptions('uri_mode', Serac::DEFAULT_URI_MODE);
      switch ($mode)
      {
         case 'request_uri':
            $uri = Serac::getServerVar('REQUEST_URI', '');
            $uri = preg_replace('/(?:\?.*)?$/', '', $uri);
            break;

         case 'query_string':
            $uri = Serac::getQueryVar($this->getOptions('uri_param', 'uri'), '');
            break;

         case 'path_info':
            $uri = Serac::getServerVar('PATH_INFO', '');
            break;

         default:
            throw new Exception('Unknown URI Mode: '.$mode);
      }

      $this->uri = preg_replace('#/+#', '/', trim($uri, '/'));
   }

   protected function cleanupGlobals()
   {
      if (isset($_REQUEST['GLOBALS']) || isset($_FILES['GLOBALS']))
      {
         echo 'GLOBALS-ception.';
         exit(1);
      }

      foreach (array($_ENV, $_GET, $_POST, $_COOKIE, $_SERVER) as $array)
      {
         foreach ($array as $key => $val)
            if (isset($GLOBALS[$key])) unset($GLOBALS[$key]);
      }
   }

   public static function cleanupMagicQuotes($set)
   {
      foreach ($set as $key => $value)
      {
         if (is_array($value))
            $set[$key] = Serac::cleanupMagicQuotes($set);
         else
            $set[$key] = stripslashes($value);
      }

      return($set);
   }

   public function classLoader($class)
   {
      $file = str_replace('_', DIRECTORY_SEPARATOR, strtolower($class));

      $dirs = $this->getOptions('class_dir');
      if (empty($dirs)) $dirs = array(NULL);
      if (!is_array($dirs)) $dirs = array($dirs);

      foreach ($dirs as $dir)
      {
         if ($path = Serac::getFilePath($file, $dir))
         {
            require_once $path;
            return(class_exists($class) || interface_exists($class));
         }
      }

      return(FALSE);
   }

   public static function getFilePath(
      $file,
      $dir = NULL,
      $ext = NULL)
   {
      if (is_array($file)) $file = implode(DIRECTORY_SEPARATOR, $file);

      $path = 
         rtrim(BASEPATH, DIRECTORY_SEPARATOR).
         DIRECTORY_SEPARATOR.
         preg_replace(
            '/'.preg_quote(DIRECTORY_SEPARATOR, '/').'+/', DIRECTORY_SEPARATOR,
            trim(
               DIRECTORY_SEPARATOR.
               $dir.DIRECTORY_SEPARATOR.$file,
               DIRECTORY_SEPARATOR)).
         Serac::EXT;

      return(file_exists($path) ? $path : FALSE);
   }

   public static function includeFile(
      $_path,
      $_dir = NULL,
      array $_param = NULL,
      $_required = FALSE)
   {  
      $_found  = Serac::getFilePath($_path, $_dir);

      if (!$_found)
      {
         if ($_required)
         {
            throw new Exception('No such file or directory: '.$_path);
            exit(1);
         }

         return(FALSE);
      }

      if (is_array($_param) && count($_param) > 0)
         extract($_param, EXTR_SKIP | EXTR_REFS);

      return include $_found;
   }

   public function register(
      $thing,
      $name = NULL,
      $force_register = FALSE)
   {
      if (!is_array($thing))
         $this->registerObject($thing, $name, $force_register);
      else
      {
         foreach ($thing as $key => $val)
            $this->registerObject(
               $val,
               (is_string($key) ? $key : NULL),
               $force_register);
      }
   }

   public function registerObject(
      $thing,
      $name = NULL,
      $force_register = FALSE)
   {
      if (empty($name))
      {
         if (is_string($thing)) $name = $thing;
         else $name = get_class($thing);
      }

      $name = strtolower($name);
      if (isset($this->registry[$name]))
      {
         if (!$force_register) return(NULL);
         else unset($this->registry[$name]);
      }

      if (is_string($thing))
         $thing = new $thing;

      if (!is_object($thing))
         throw new Exception('Unable to register object');

      $this->registry[$name] = $thing;
      return($this->registry[$name]);
   }

   public function run()
   {
      $found = $this->getMatchingRoute();
      return($this->execute($found));
   }

   protected function execute($routedef)
   {
      $callback = FALSE;
      $args = array();

      if ($routedef instanceOf Closure)
         $callback = $routedef;
      elseif (is_array($routedef))
      {
         if (isset($routedef['arguments']) && is_array($routedef['arguments']))
            $args = $routedef['arguments'];

         if (isset($routedef['handler']))
            $callback = $routedef['handler'];
         elseif (!empty($routedef['function']))
         {
            if (!empty($routedef['class']))
            {
               $object = $this->registerObject($routedef['class']);
               if (!method_exists($object, $routedef['function']))
               {
                  throw new Exception(
                     sprintf('Method %s does not exist in class %s',
                        $routedef['function'],
                        $routedef['class'])
                     );
               }
               else $callback = array($object, $routedef['function']);
            }
            else
            {
               if (!function_exists($routedef['function']))
                  throw new Exception('No such function: '.$routedef['function']);
               else $callback = $routedef['function'];
            }
         }
         else return(FALSE);
      }
      else return(FALSE);

      if (!is_callable($callback))
         throw new Exception('Can not execute callback');


      return(call_user_func_array($callback, $args));
   }

   public function setRoute()
   {
      $args = func_get_args();
      if (func_num_args() > 1)
         $this->routing[ ($args[0]) ] = $args[1];
      else
      {
         if (isset($args[0]) && is_array($args[0]))
         {
            foreach ($args[0] as $uri => $handler)
               $this->routing[$uri] = $handler;
         }
         else return(FALSE);
      }
   }

   public function getMatchingRoute()
   {
      $query = sprintf('%s:/%s', $this->method, $this->uri);

      foreach ($this->routing as $pattern => $def)
      {
         if (!preg_match('/^\{.+\}$/', $pattern))
         {
            $method_selector = '';
            if (preg_match('/^([^:]+):(.*)/', $pattern, $matches))
            {
               $path    = $matches[2];
               $methods = explode(',', $matches[1]);

               $method_re = array();
               foreach ($methods as $method)
               {
                  $method = trim($method);
                  if ($method == '')
                     continue;
                  elseif ($method == '?')
                     $method_re[] = '[^:,]+';
                  elseif (in_array($method, Serac::$valid_methods))
                     $method_re[] = preg_quote($method);
               }

               if (count($method_re) > 0)
                  $method_selector = '('.implode('|', $method_re).')';
            }
            else $path = $pattern;

            if ($method_selector == '') $method_selector = '([^:,]+)';
            
            $segments   = explode('/', trim($path, '/'));
            $segment_re = array();
            foreach ($segments as $segment)
            {
               switch ($segment)
               {
                  case '?': $segment_re[] = '(?:/([^/]+))'; break;
                  case '*': $segment_re[] = '(?:/?(.*))?'; break 2;
                  default : $segment_re[] = '/'.preg_quote($segment);
               }
            }

            $regex = sprintf('{^%s:%s}',
               $method_selector,
               implode('', $segment_re));
         }
         else $regex = $pattern;

         if (preg_match($regex, $query, $matches))
         {
            if (is_array($def))
            {
               if (count($matches) > 1)
               {
                  foreach ($def as $key => $value)
                  {
                     $def[$key] = preg_replace(
                        '/(?:\$(\d+))/e',
                        'isset($matches[$1]) ? $matches[$1] : ""',
                        $value);
                  }
               }

               if (isset($def['arguments']))
                  $def['arguments'] = explode('/', $def['arguments']);
            }

            return($def);
         }
      }

      return(NULL);
   }

   public function offsetGet($index)
   {
      if ($index === NULL) return($this->routing);
      else return(Serac::getValue($this->routing, $index, FALSE));
   }

   public function offsetSet($index, $value)
   {
      if ($index === NULL) $this->setRoute($value);
      else $this->setRoute($index, $value);
   }

   public function offsetUnset($index)
   {
      if (isset($this->routing[$index]))
         unset($this->routing[$index]);
   }

   public function offsetExists($index)
   {
      return(isset($this->routing[$index]));
   }

   public function setOptions()
   {
      $args = func_get_args();
      if (func_num_args() > 1)
         $this->options[ ($args[0]) ] = $args[1];
      else
      {
         if (isset($args[0]) && is_array($args[0]))
         {
            foreach ($args[0] as $key => $val)
               $this->options[$key] = $val;
         }
         else return(FALSE);
      }
   }

   public function getOptions($key = NULL, $default = NULL)
   {
      if ($key === NULL) return($this->options);
      return(Serac::getValue($this->options, $key, $default));
   }

   public static function getValue(array $set, $key, $default)
   {
      return(isset($set[$key]) ? $set[$key] : $default);
   }

   public static function getServerVar($key, $default = FALSE)
   {
      return(Serac::getValue($_SERVER, $key, $default));
   }

   public static function getEnvVar($key, $default = FALSE)
   {
      return(Serac::getValue($_ENV, $key, $default));
   }

   public static function getQueryVar($key, $default = FALSE)
   {
      return(Serac::getValue($_GET, $key, $default));
   }

   public function __isset($name)
   {
      $name = strtolower($name);
      return(isset($this->registry[$name]));
   }

   public function __get($name)
   {
      $name = strtolower($name);
      return(!isset($this->registry[$name]) ? FALSE : $this->registry[$name]);
   }

   public function __set($name, $value)
   {
      $this->register($value, $name);
   }
}
