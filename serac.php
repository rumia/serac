<?php
// vim: ts=3 sw=3 et
if (!defined('BASE_PATH'))
   define('BASE_PATH', realpath(dirname(__FILE__)));

class serac
{
   const EXT               = '.php',
         VERSION           = '0.3',
         DEFAULT_METHOD    = 'get',
         VALID_METHODS     = '/^(?:get|post|put|delete|options)$/';

   protected static $instance;
   public $protocol  = null;
   public $scheme    = null;
   public $hostname  = null;
   public $method    = self::DEFAULT_METHOD;
   public $uri       = null;
   public $routes    = array();
   public $options   =
      array(
         'encoding'              => 'utf-8',
         'cleanup_superglobals'  => true
      );

   public $current_route;

   protected function __construct() {}

   public static function initialize(array $options = null)
   {
      if (!self::$instance instanceOf serac)
      {
         $serac = new serac;

         if (!empty($options))
            $serac->options = $options + $serac->options;

         $pre_init = self::get_value($serac->options, 'pre_init');
         if (!empty($pre_init) && is_callable($pre_init))
            call_user_func($pre_init, $serac);

         mb_internal_encoding(self::get_value($serac->options, 'encoding', 'utf-8'));
         if (self::get_value($serac->options, 'cleanup_superglobals'))
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
            $serac->protocol   = 'cli';
            $serac->scheme     = 'file';

            $hostname = getenv('HOSTNAME');
            if (strlen($hostname))
               $serac->hostname = $hostname;
            else
               $serac->hostname = 'localhost';

            $method = strtolower(getenv('METHOD'));
            if (preg_match(self::VALID_METHODS, $method))
               $serac->method = $method;
            else
               $serac->method = self::DEFAULT_METHOD;

            $uri_mode = self::get_value($serac->options, 'uri_mode', 'parameter');

            switch ($uri_mode)
            {
               case 'environment':
                  $var = self::get_value($serac->options, 'uri_param', 'URI');
                  $serac->uri = (string) getenv($var);
                  break;

               case 'parameter':
               default:
                  global $argv, $argc;

                  $index = self::get_value($serac->options, 'uri_param', 1);
                  if ($index > 0 && $index < $argc) $serac->uri = $argv[$index];
                  break;
            }
         }
         else
         {
            $serac->hostname = self::get_value($_SERVER, 'HTTP_HOST', 'localhost');
            $serac->protocol = 
               strtolower(self::get_value($_SERVER, 'SERVER_PROTOCOL', 'http/1.1'));

            if (self::get_value($_SERVER, 'HTTPS', 'off') == 'on')
               $serac->scheme = 'https';
            else
               $serac->scheme = 'http';

            $method = self::get_value($_SERVER, 'REQUEST_METHOD', self::DEFAULT_METHOD);

            if ($method == 'POST' && !empty($_POST['_method']))
               $method = $_POST['_method'];

            $method = strtolower($method);
            if (preg_match(self::VALID_METHODS, $method))
               $serac->method = $method;
            else
               $serac->method = self::DEFAULT_METHOD;

            $uri_mode = self::get_value($serac->options, 'uri_mode', 'path_info');
            switch ($uri_mode)
            {
               case 'request_uri':
                  $serac->uri = self::get_value($_SERVER, 'REQUEST_URI', '');
                  $serac->uri = preg_replace('/(?:\?/*)?$/', '', $serac->uri);
                  break;

               case 'query_string':
                  $var = self::get_value($serac->options, 'uri_param', 'uri');
                  $serac->uri = self::get_value($_GET, $var, '');
                  break;

               case 'path_info':
               default:
                  if (isset($_SERVER['ORIG_PATH_INFO']))
                     $serac->uri = $_SERVER['ORIG_PATH_INFO'];
                  else
                     $serac->uri = self::get_value($_SERVER, 'PATH_INFO', '');
                  break;
            }
         }

         $serac->uri = preg_replace('#/+#', '/', trim($serac->uri, '/'));

         $autoloader = self::get_value(
                           $serac->options,
                           'autoloader',
                           array('serac', 'load_class')
                        );

         if (is_callable($autoloader)) spl_autoload_register($autoloader);
         self::$instance = $serac;

         $post_init = self::get_value($serac->options, 'post_init');
         if (!empty($post_init) && is_callable($post_init))
            call_user_func($post_init, $serac);
      }

      return self::$instance;
   }

   public static function instance()
   {
      if (!self::$instance instanceOf serac)
         return self::initialize();
      else
         return self::$instance;
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

   public static function load_class($name)
   {
      $file = str_replace(
               array('_', '\\'),
               DIRECTORY_SEPARATOR,
               strtolower($name)
            );

      $dirs = self::get_option('class_dir');
      if ($dirs === false) $dirs = array('.');
      elseif (!is_array($dirs)) $dirs = array($dirs);

      foreach ($dirs as $dir)
      {
         $path = self::get_file_path($file, $dir);
         if ($path !== false)
         {
            require_once $path;
            return (class_exists($name) || interface_exists($name));
         }
      }

      $y = TRUE;
      return false;
   }

   public function get_matching_handler(
      $scheme = null,
      $host = null,
      $method = null,
      $uri = null)
   {
      return self::find_matching_pattern(
         $this->routes,
         (isset($scheme)   ? $scheme   : $this->scheme),
         (isset($host)     ? $host     : $this->hostname),
         (isset($method)   ? $method   : $this->method),
         (isset($uri)      ? $uri      : $this->uri)
      );
   }

   public static function find_matching_pattern(
      array $handlers,
      $scheme,
      $host,
      $method,
      $uri,
      $comparison_callback = null,
      $conversion_callback = null,
      $single_mode = false)
   {
      $query = sprintf('%s:%s:%s:/%s', $scheme, $host, $method, $uri);

      foreach ($handlers as $glob => $def)
      {
         $regex = self::compile_selector($glob);
         if ($regex === false) continue;

         if (preg_match($regex, $query, $matches))
         {
            if (isset($conversion_callback) && is_callable($conversion_callback))
            {
               $def = call_user_func($conversion_callback, $glob, $def);
            }
            else
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
            }

            if (isset($comparison_callback) && is_callable($comparison_callback))
            {
               $result = call_user_func_array($comparison_callback, $glob, $def);

               if (!$result && $single_mode)
                  return false;
            }

            return $def;
         }
      }

      return null;
   }


   public static function run()
   {
      self::$instance->current_route = self::$instance->get_matching_handler();
      if (!empty(self::$instance->current_route))
         return self::$instance->execute(self::$instance->current_route);
      else
         return self::signal('not-found');
   }

   public static function signal($name)
   {
      $handler = self::$instance->get_matching_handler(null, null, '@'.$name, '');
      if (!empty($handler)) return self::$instance->execute($handler, true);
   }

   protected function execute(&$def, $is_signal = false)
   {
      $callback   = false;
      $args       = array();

      if (is_string($def))
      {
         if (preg_match('/^([^:]+)::(.+)$/', $def, $def_parts))
            $def = array('class' => $def_parts[1], 'function' => $def_parts[2]);
         else
            $def = array('function' => $def);
      }

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
               $class = $def['class'];
               if (!self::load_class($class))
               {
                  if ($is_signal) exit(1);
                  return self::signal('class-not-found');
               }

               $class_ref = new ReflectionClass($class);

               if (!$class_ref->hasMethod($def['function']))
               {
                  if ($is_signal) exit(1);
                  return self::signal('method-not-found');
               }

               $method_ref = $class_ref->getMethod($def['function']);

               if ($method_ref->isPublic())
               {
                  $required_args = $method_ref->getNumberOfRequiredParameters();

                  if ($required_args > 0 && $required_args > count($args))
                  {
                     if ($is_signal) exit(1);
                     return self::signal('missing-args');
                  }

                  if ($method_ref->isStatic())
                     $callback = array($class, $def['function']);
                  elseif (is_string($class) && $class_ref->isInstantiable())
                  {
                     $def['object'] = new $class($this);
                     $callback = array($def['object'], $def['function']);
                  }
               }
               else
               {
                  if ($is_signal) exit(1);
                  return self::signal('inaccessible-method');
               }
            }
            else
            {
               $valid_closure    = ($def['function'] instanceOf Closure);
               $valid_func_name  = (is_string($def['function']) &&
                                    function_exists($def['function']));

               if ($valid_closure || $valid_func_name)
                  $callback = $def['function'];
            }
         }
      }

      if (!is_callable($callback))
      {
         if ($is_signal) exit(1);
         return self::signal('not-found');
      }
      else
      {
         if (!$is_signal)
         {
            $pre_exec = self::get_option('pre_exec');
            if (!empty($pre_exec) && is_callable($pre_exec))
               call_user_func($pre_exec, self::$instance->current_route);
         }

         $result = call_user_func_array($callback, $args);

         if (!$is_signal)
         {
            $post_exec = self::get_option('post_exec');
            if (!empty($post_exec) && is_callable($post_exec))
               call_user_func($post_exec, $result);
         }

         return $result;
      }

   }

   protected static function compile_selector($pattern)
   {
      $regex = false;
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

            $scheme_re  = self::compile_prefix_part($scheme);
            $host_re    = self::compile_prefix_part($host);
            $method_re  = self::compile_prefix_part($method, true);
            $path_re    = self::compile_path_part($matches[2]);

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

   protected static function compile_host_glob($pattern)
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

   protected static function compile_prefix_part($part, $is_method = false)
   {
      if (mb_strlen($part) > 0 && $part !== '*')
      {
         if (strpos($part, ',') !== false)
         {
            $list = explode(',', $part);
            foreach ($list as $key => $value)
               $list[$key] = self::compile_host_glob($value);

            $regex = implode('|', $list);
         }
         else $regex = self::compile_host_glob($part);
      }
      else $regex = ($is_method ? '[^@]' : '') . '.+';

      return $regex;
   }

   protected static function compile_path_part($path)
   {
      $regex = '';
      foreach (explode('/', trim($path, '/')) as $segment)
      {
         if ($segment === '*')
            $regex .= '(?:/([^/]+))';
         elseif (strncmp($segment, '?', 1) === 0)
         {
            if (preg_match('/^(?:\?([a-z][a-z0-9_]*))$/i', $segment, $matches))
               $regex .= '(?:/(?<' . $matches[1] . '>[^/]+))';
            else
               $regex .= '/' . preg_quote($segment);
         }
         elseif ($segment === '~$')
            $regex .= '(?:/?(.*))?';
         else
            $regex .= '/' . preg_quote($segment);
      }

      return $regex;
   }

   public static function route($pattern, $handler = null)
   {
      if (is_array($pattern))
      {
         foreach ($pattern as $key => $val)
            self::$instance->routes[$key] = $val;
      }
      elseif (!empty($handler))
      {
         self::$instance->routes[$pattern] = $handler;
      }
      else return false;
   }

   public static function get_value(array $array, $index, $default = null)
   {
      return (isset($array[$index]) ? $array[$index] : $default);
   }

   public static function get_option($name, $default = null)
   {
      return self::get_value(self::instance()->options, $name, $default);
   }

   public static function set_option($name, $value)
   {
      $instance = self::instance();
      $instance->options[$name] = $value;
   }

   public static function get_file_path($file, $dir = null, $ext = null)
   {
      if (isset($dir))
      {
         if (strncmp($dir, DIRECTORY_SEPARATOR, 1) === false)
         {
            $dir =
               BASE_PATH .
               DIRECTORY_SEPARATOR .
               rtrim($dir, DIRECTORY_SEPARATOR);
         }
      }
      else $dir = BASE_PATH;

      $path =
         $dir .
         DIRECTORY_SEPARATOR .
         trim($file, DIRECTORY_SEPARATOR) .
         (isset($ext) ? $ext : self::EXT);

      return (file_exists($path) ? $path : false);
   }
}
