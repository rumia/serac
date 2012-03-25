<?php
// vim: ts=3 sw=3 et
if (!defined('BASE_PATH'))
   define('BASE_PATH', realpath(dirname(__FILE__)));

class serac
{
   const EXT               = '.php',
         VERSION           = '0.2',
         DEFAULT_METHOD    = 'get';

   public static $valid_methods =
      array(
         'get',   'post',
         'put',   'delete',
         'head',  'options'
      );

   public $protocol  = NULL;
   public $scheme    = NULL;
   public $hostname  = NULL;
   public $method    = self::DEFAULT_METHOD;
   public $uri       = NULL;
   public $routes    = array();
   public $options   =
      array(
         'base_url'              => '//localhost',
         'encoding'              => 'utf-8',
         'cleanup_superglobals'  => TRUE
      );

   public function __construct(array $options = NULL)
   {
      if (!empty($options))
         $this->options = array_merge($this->options, $options);

      $pre_init = $this->get_option('pre_init');
      if (!empty($pre_init) && is_callable($pre_init))
         call_user_func_array($pre_init, array($this));

      $this->initialize();

      $post_init = $this->get_option('post_init');
      if (!empty($post_init) && is_callable($post_init))
         call_user_func_array($post_init, array($this));
   }

   protected function initialize()
   {
      if ($this->get_option('cleanup_superglobals'))
      {
         if (ini_get('register_globals'))
         {
            if (isset($_REQUEST['GLOBALS']) || isset($_FILES['GLOBALS']))
               exit(1);

            foreach (array($_ENV, $_GET, $_POST, $_COOKIE, $_SERVER) as $array)
            {
               foreach ($array as $key => $val)
                  if (isset($GLOBALS[$key])) unset($GLOBALS[$key]);
            }
         }

         if (get_magic_quotes_gpc())
         {
            $_GET    = self::cleanup_magic_quotes($_GET);
            $_POST   = self::cleanup_magic_quotes($_POST);
            $_COOKIE = self::cleanup_magic_quotes($_COOKIE);
         }
      }

      if (PHP_SAPI == 'cli')
      {
         $this->protocol   = 'cli';
         $this->scheme     = 'file';

         $hostname = getenv('HOSTNAME');
         if (strlen($hostname)) $this->hostname = $hostname;
         else $this->hostname = 'localhost';

         $method = strtolower(getenv('METHOD'));
         if (strlen($method) && in_array($method, self::$valid_methods))
            $this->method = $method;
         else
            $this->method = self::DEFAULT_METHOD;

         $uri_mode = $this->get_option('uri_mode', 'parameter');

         switch ($uri_mode)
         {
            case 'environment':
               $var = self::get_value($this->options, 'uri_param', 'URI');
               $this->uri = (string) getenv($var);
               break;

            case 'parameter':
            default:
               global $argv, $argc;

               $index = $this->get_option('uri_param', 1);
               if ($index > 0 && $index < $argc) $this->uri = $argv[$index];
               break;
         }
      }
      else
      {
         $this->hostname = self::get_value($_SERVER, 'HTTP_HOST', 'localhost');
         $this->protocol = strtolower(self::get_value(
            $_SERVER, 'SERVER_PROTOCOL', 'http/1.1'
         ));

         if (self::get_value($_SERVER, 'HTTPS', 'off') == 'on')
            $this->scheme = 'https';
         else
            $this->scheme = 'http';

         $method = self::get_value($_SERVER, 'HTTP_METHOD', self::DEFAULT_METHOD);

         if ($method == 'POST' && !empty($_POST['_method']))
            $method = $_POST['_method'];

         $method = strtolower($method);
         if (in_array($method, self::$valid_methods))
            $this->method = $method;
         else
            $this->method = self::DEFAULT_METHOD;

         $uri_mode = $this->get_option('uri_mode', 'path_info');
         switch ($uri_mode)
         {
            case 'request_uri':
               $this->uri = self::get_value($_SERVER, 'REQUEST_URI', '');
               $this->uri = preg_replace('/(?:\?/*)?$/', '', $this->uri);
               break;

            case 'query_string':
               $var = $this->get_option('uri_param', 'uri');
               $this->uri = self::get_value($_GET, $var, '');
               break;

            case 'path_info':
            default:
               if (isset($_SERVER['ORIG_PATH_INFO']))
                  $this->uri = $_SERVER['ORIG_PATH_INFO'];
               else
                  $this->uri = self::get_value($_SERVER, 'PATH_INFO', '');
               break;
         }
      }

      $this->uri = preg_replace('#/+#', '/', trim($this->uri, '/'));
      mb_internal_encoding($this->get_option('encoding', 'utf-8'));

      $autoloader = $this->get_option('autoloader', array($this, 'load_class'));
      if (is_callable($autoloader)) spl_autoload_register($autoloader);
   }

   public static function cleanup_magic_quotes(array $array)
   {
      foreach ($array as $key => $value)
      {
         if (is_array($value))
            $array[$key] = self::cleanup_magic_quotes($value);
         else
            $array[$key] = stripslashes($value);
      }
      return $array;
   }

   public static function get_value(array $array, $index, $default = FALSE)
   {
      if (isset($array[$index]))
         return $array[$index];
      else
         return $default;
   }

   public function get_option($name, $default = FALSE)
   {
      return self::get_value($this->options, $name, $default);
   }

   public static function get_file_path($file, $dir = NULL, $ext = NULL)
   {
      $path =
         rtrim(BASE_PATH, DIRECTORY_SEPARATOR).
         DIRECTORY_SEPARATOR.
         preg_replace(
            '/'.preg_quote(DIRECTORY_SEPARATOR, '/').'+/',
            DIRECTORY_SEPARATOR,
            trim($dir.DIRECTORY_SEPARATOR.$file, DIRECTORY_SEPARATOR)
         ).
         self::EXT;

      return (file_exists($path) ? $path : FALSE);
   }

   public function load_class($name)
   {
      $file = str_replace('_', DIRECTORY_SEPARATOR, strtolower($name));

      $dirs = $this->get_option('class_dir');
      if (!is_array($dirs)) $dirs = array($dirs);

      foreach ($dirs as $dir)
      {
         if ($path = self::get_file_path($file, $dir))
         {
            require_once $path;
            return (class_exists($name) || interface_exists($name));
         }
      }

      return FALSE;
   }

   public function run()
   {
      $found   = $this->get_matching_route();
      $result  = $this->execute($found);
   }

   protected function execute($def)
   {
      $callback   = FALSE;
      $args       = array();

      if ($def instanceOf Closure)
      {
         $callback = $def;
         $args = array($this);
      }
      elseif (is_array($def))
      {
         if (isset($def['arguments']) && is_array($def['arguments']))
            $args = $def['arguments'];

         if (!empty($def['function']))
         {
            if (!empty($def['class']))
            {
               $class   = $def['class'];
               $object  = new $class($this);

               if (method_exists($object, $def['function']))
                  $callback = array($object, $def['function']);
            }
            else
            {
               array_unshift($args, $this);
               if ($def['function'] instanceOf Closure ||
                  (is_string($def['function']) &&
                  function_exists($def['function'])))
                  $callback = $def['function'];
            }
         }
         else return FALSE;
      }
      else return FALSE;

      if (!is_callable($callback)) return FALSE;

      return call_user_func_array($callback, $args);
   }

   protected function get_matching_route()
   {
      $query = sprintf(
         '%s:%s:%s:/%s',
         $this->scheme,
         $this->hostname,
         $this->method,
         $this->uri);

      foreach ($this->routes as $glob => $def)
      {
         $regex = $this->compile_route($glob);
         if ($regex === FALSE) continue;

         if (preg_match($regex, $query, $matches))
         {
            $captured = array_slice($matches, 4);
            if (is_array($def))
            {
               if (count($captured) > 0)
               {
                  foreach ($def as $key => $value)
                  {
                     $def[$key] = preg_replace(
                        '/(?:\$([a-z][a-z0-9_]+))/ie',
                        'isset($captured["$1"]) ? $captured["$1"] : ""',
                        $value);
                  }
               }

               if (isset($def['arguments']))
                  $def['arguments'] = explode('/', $def['arguments']);
            }

            $this->routes[$glob] = $def;

            return $this->routes[$glob];
         }
      }

      return NULL;
   }

   protected function compile_route($pattern)
   {
      static $compiled = array();

      if (!isset($compiled[$pattern]))
      {
         if (!preg_match('#^\{.*?\}$#', $pattern))
         {
            $regex = FALSE;
            $scheme = $host = $method = '';

            if (preg_match('#^([^/]+)?(.*)$#', $pattern, $matches))
            {
               $parts = explode(':', trim($matches[1], ':'));

               switch (count($parts))
               {
                  case 3:
                     $method  = trim($parts[2]);
                     $host    = trim($parts[1]);
                     $scheme  = trim($parts[0]);
                     break;

                  case 2:
                     $method  = trim($parts[1]);
                     $host    = trim($parts[0]);
                     break;

                  case 1:
                     $method  = trim($parts[0]);
                     break;
               }

               $scheme_re  = $this->compile_prefix_part($scheme);
               $host_re    = $this->compile_prefix_part($host);
               $method_re  = $this->compile_prefix_part($method);
               $path_re    = $this->compile_path($matches[2]);

               $regex = sprintf(
                  '{^(%s):(%s):(%s):%s}',
                  $scheme_re,
                  $host_re,
                  $method_re,
                  $path_re);
            }
         }
         else $regex = $pattern;

         $compiled[$pattern] = $regex;
      }

      return $compiled[$pattern];
   }

   protected function compile_prefix_part($part)
   {
      if (mb_strlen($part) > 0 && $part !== '*')
      {
         if (strpos($part, ',') !== FALSE)
         {
            $list = explode(',', $part);
            foreach ($list as $key => $value)
               $list[$key] = preg_quote(trim($value), '/');

            $regex = implode('|', $list);
         }
         else $regex = preg_quote($part, '/');
      }
      else $regex = '.+';

      return $regex;
   }

   protected function compile_path($path)
   {
      $segments = explode('/', trim($path, '/'));
      $res = array();

      foreach ($segments as $segment)
      {
         if ($segment === '*')
         {
            $res[] = '(?:/([^/]+))';
         }
         elseif (strncmp($segment, '?', 1) === 0)
         {
            $name = substr($segment, 1);
            if (!preg_match('/^[a-z][a-z0-9_]*$/i', $name))
               $res[] = '/'.preg_quote($segment);
            else
               $res[] = '(?:/(?<'.$name.'>[^/]+))';
         }
         elseif ($segment === '~$')
         {
            $res[] = '(?:/?(.*))?';
         }
         else
         {
            $res[] = '/'.preg_quote($segment);
         }
      }

      return implode('', $res);
   }

   public function dispatch($pattern, $handler = NULL)
   {
      $this->routes[$pattern] = $handler;
   }
}
