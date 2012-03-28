<?php
// vim: ts=3 sw=3 et
if (!defined('BASE_PATH'))
   define('BASE_PATH', realpath(dirname(__FILE__)));

class serac implements ArrayAccess
{
   const EXT               = '.php',
         VERSION           = '0.2',
         DEFAULT_METHOD    = 'get',
         VALID_METHODS     = '|get|post|put|delete|head|options|';

   public $protocol  = NULL;
   public $scheme    = NULL;
   public $hostname  = NULL;
   public $method    = self::DEFAULT_METHOD;
   public $uri       = NULL;
   public $routes    = array();
   public $current_route;
   public $options   =
      array(
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
      mb_internal_encoding($this->get_option('encoding', 'utf-8'));
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
         if (strpos(self::VALID_METHODS, '|'.$method.'|'))
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

         $method = self::get_value($_SERVER, 'REQUEST_METHOD', self::DEFAULT_METHOD);

         if ($method == 'POST' && !empty($_POST['_method']))
            $method = $_POST['_method'];

         $method = strtolower($method);
         if (strpos(self::VALID_METHODS, '|'.$method.'|'))
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

   public function set_option($name, $value)
   {
      $this->options[$name] = $value;
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
         (strlen($ext) ? $ext : self::EXT);

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
      $this->current_route = $this->get_matching_route();
      if (!empty($this->current_route))
      {
         $pre_exec = $this->get_option('pre_exec');
         if (!empty($pre_exec) && is_callable($pre_exec))
            call_user_func_array($pre_exec, array($this, $this->current_route));

         $result = $this->execute($this->current_route);

         $post_exec = $this->get_option('post_exec');
         if (!empty($post_exec) && is_callable($post_exec))
            call_user_func_array($post_exec, array($this, $result));

         return $result;
      }
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
               $class      = $def['class'];
               $classref   = new ReflectionClass($class);

               if (!$classref->hasMethod($def['function']))
                  return FALSE;

               $methodref = $classref->getMethod($def['function']);
               if ($methodref->isPublic())
               {
                  if ($methodref->isStatic())
                  {
                     $callback = array($class, $def['function']);
                     array_unshift($args, $this);
                  }
                  elseif (is_string($class) && $classref->isInstantiable())
                  {
                     $object = $classref->newInstance($this);
                     $callback = array($object, $def['function']);
                  }
               }
               else return FALSE;
            }
            else
            {
               if
               (
                  $def['function'] instanceOf Closure ||
                  (
                     is_string($def['function']) &&
                     function_exists($def['function'])
                  )
               )
               {
                  $callback = $def['function'];
                  array_unshift($args, $this);
               }
               else return FALSE;
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
            if (is_array($def))
            {
               if (count($matches) > 1)
               {
                  foreach ($def as $key => $value)
                  {
                     if (!is_string($value)) continue;
                     $def[$key] = preg_replace(
                        '/(?:\$([a-z0-9_]+))/ie',
                        'isset($matches["$1"]) ? $matches["$1"] : ""',
                        $value);
                  }
               }

               if (isset($def['arguments']) && mb_strlen($def['arguments']))
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
      $regex = FALSE;
      if (!preg_match('#^\{.*?\}$#', $pattern))
      {
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
            $path_re    = $this->compile_path_part($matches[2]);

            $regex = sprintf(
               '{^(?:%s):(?:%s):(?:%s):%s}',
               $scheme_re,
               $host_re,
               $method_re,
               $path_re);
         }
      }
      else $regex = $pattern;

      return $regex;
   }

   protected function compile_host_glob($pattern)
   {
      if (preg_match('/^((?:\*\.)+)?(.+)/', $pattern, $matches))
      {
         $regex =
            str_replace('*.', '[^.]+\.', $matches[1]).
            preg_quote($matches[2], '/');
      }
      else $regex = preg_quote(trim($pattern), '/');

      return $regex;
   }

   protected function compile_prefix_part($part)
   {
      if (mb_strlen($part) > 0 && $part !== '*')
      {
         if (strpos($part, ',') !== FALSE)
         {
            $list = explode(',', $part);
            foreach ($list as $key => $value)
               $list[$key] = $this->compile_host_glob($value);

            $regex = implode('|', $list);
         }
         else $regex = $this->compile_host_glob($part);
      }
      else $regex = '.+';

      return $regex;
   }

   protected function compile_path_part($path)
   {
      $regex = '';
      foreach (explode('/', trim($path, '/')) as $segment)
      {
         if ($segment === '*')
            $regex .= '(?:/([^/]+))';
         elseif (strncmp($segment, '?', 1) === 0)
         {
            if (preg_match('/^(?:\?([a-z][a-z0-9_]*))$/i', $segment, $matches))
               $regex .= '(?:/(?<'.$matches[1].'>[^/]+))';
            else
               $regex .= '/'.preg_quote($segment);
         }
         elseif ($segment === '~$')
            $regex .= '(?:/?(.*))?';
         else
            $regex .= '/'.preg_quote($segment);
      }

      return $regex;
   }

   public function route($pattern, $handler = NULL)
   {
      if (is_array($pattern))
      {
         foreach ($pattern as $key => $val)
            $this->routes[$key] = $val;
      }
      elseif (!empty($handler))
      {
         $this->routes[$pattern] = $handler;
      }
      else return FALSE;
   }

   public function offsetGet($index)
   {
      return (isset($this->routes[$index]) ? $this->routes[$index] : FALSE);
   }

   public function offsetSet($index, $value)
   {
      if ($index === NULL)
         $this->route($value);
      else
         $this->route($index, $value);
   }

   public function offsetExists($index)
   {
      return isset($this->routes[$index]);
   }

   public function offsetUnset($index)
   {
      unset($this->routes[$index]);
   }
}
