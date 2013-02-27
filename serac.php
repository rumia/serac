<?php
// vim: ts=3 sw=3 et
if (!defined('BASE_PATH'))
{
   if (!defined('__DIR__')) define('__DIR__', realpath(dirname(__FILE__)));
   define('BASE_PATH', __DIR__);
}

class serac
{
   const EXT = '.php',
         VERSION = '0.4',
         DEFAULT_METHOD = 'GET';

   const ERR_NO_CLASS = 700,
         ERR_NO_ROUTE = 701,
         ERR_NOT_CALLABLE = 702;

   protected static $instance;
   public $protocol = null;
   public $scheme = null;
   public $hostname = null;
   public $method = self::DEFAULT_METHOD;
   public $uri = null;
   public $routes = array();
   public $current_route;
   public $options =
      array(
         'encoding' => 'utf-8',
         'cleanup_superglobals' => true
      );

   protected $valid_methods = array(
      'GET' => true,
      'POST' => true,
      'PUT' => true,
      'DELETE' => true
   );

   protected function __construct() {}

   public static function initialize(array $options = null)
   {
      if (!self::$instance instanceOf serac)
      {
         $serac = new serac;
         if (!empty($options)) $serac->options = $options + $serac->options;
         $pre_init = self::get_value($serac->options, 'pre_init');

         if (!empty($pre_init) && is_callable($pre_init))
            call_user_func($pre_init, $serac);

         mb_internal_encoding(self::get_value(
            $serac->options,
            'encoding',
            'utf-8'));

         if (self::get_value($serac->options, 'cleanup_superglobals'))
         {
            self::cleanup_superglobals();
         }

         $is_cli = self::get_value($serac->options, 'cli', false);
         if (PHP_SAPI == 'cli' || $is_cli) $serac->init_cli();
         else $serac->init_web(); // TODO: better name :)

         // remove duplicate and trailing slashes
         $serac->uri = preg_replace(
            array('#/+#', '#\$+#'),
            array('/', ''),
            trim($serac->uri, '/'));

         $autoloader = self::get_value(
            $serac->options,
            'autoloader',
            array('serac', 'load_class'));

         if (is_callable($autoloader)) spl_autoload_register($autoloader);
         self::$instance = $serac;

         $post_init = self::get_value($serac->options, 'post_init');
         if (!empty($post_init) && is_callable($post_init))
            call_user_func($post_init, $serac);
      }

      return self::$instance;
   }

   public static function cleanup_superglobals()
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

   protected function init_cli()
   {
      $this->protocol = 'CLI';
      $this->scheme = 'file';

      $hostname = getenv('HOSTNAME');
      if (strlen($hostname))
         $this->hostname = $hostname;
      else
         $this->hostname = 'localhost';

      $method = getenv('METHOD');
      if (isset($this->valid_methods[$method]) && $this->valid_methods[$method])
         $this->method = $method;
      else
         $this->method = self::DEFAULT_METHOD;

      $uri_mode = self::get_value($this->options, 'uri_mode', 'none');
      switch ($uri_mode)
      {
         case 'environment':
            /* use env value for uri
             * eg:
             *    > URI=/one/two/three script.php
             *
             * become:
             *    script.php/one/two/three
             */
            $var = self::get_value($this->options, 'uri_param', 'URI');
            $this->uri = (string) getenv($var);
            break;

         case 'parameter':
            /* use command line argument for uri
             * eg:
             *    > script.php one two three
             *
             * become:
             *    script.php/one/two/three
             */
            global $argv, $argc;

            $index = self::get_value($this->options, 'uri_param', 1);
            if ($index > 0 && $index < $argc) $this->uri = $argv[$index];
            break;
      }
   }

   protected function init_web()
   {
      $this->hostname = self::get_value($_SERVER, 'HTTP_HOST', 'localhost');
      $this->protocol = self::get_value($_SERVER, 'SERVER_PROTOCOL', 'HTTP/1.1');

      if (self::get_value($_SERVER, 'HTTPS', 'off') == 'on')
         $this->scheme = 'https';
      else
         $this->scheme = 'http';

      $method = self::get_value($_SERVER, 'REQUEST_METHOD', self::DEFAULT_METHOD);
      if (isset($this->valid_methods[$method]) && $this->valid_methods[$method])
         $this->method = $method;
      else
         $this->method = self::DEFAULT_METHOD;

      $uri_mode = self::get_value($this->options, 'uri_mode', 'path_info');
      switch ($uri_mode)
      {
         case 'request_uri':
            $this->uri = preg_replace(
               '/(?:\?/*)?$/',
               '',
               self::get_value($_SERVER, 'REQUEST_URI', ''));
            break;

         case 'query_string':
            $this->uri = self::get_value(
               $_GET,
               self::get_value($this->options, 'uri_param', 'uri'),
               '');
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
            if (class_exists($name) || interface_exists($name))
               return true;
         }
      }

      throw new Exception('Class not found: ' . $name, self::ERR_NO_CLASS);
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
               $def = call_user_func($conversion_callback, $glob, $def);
            else
            {
               if (is_array($def))
               {
                  if ($matches) $def = self::apply_matches($def, $matches);
                  if (isset($def['arguments']) && strlen($def['arguments']))
                     $def['arguments'] = explode('/', $def['arguments']);
               }
            }

            if (isset($comparison_callback) && is_callable($comparison_callback))
            {
               $result = call_user_func($comparison_callback, $glob, $def);
               if (!$result && $single_mode)
                  return false;
            }

            return $def;
         }
      }

      return null;
   }

   protected static function apply_matches(array $array, array $matches)
   {
      $replacements = array();
      foreach ($matches as $key => $value)
         $replacements['$' . $key] = $value;

      foreach ($array as $key => $value)
      {
         if (!is_string($value)) continue;
         $array[$key] = strtr($value, $replacements);
      }

      return $array;
   }


   public static function run()
   {
      self::$instance->current_route = self::$instance->get_matching_handler();
      if (!empty(self::$instance->current_route))
         return self::$instance->execute();
      else
      {
         throw new Exception(
            'No matching route for /' . self::$instance->uri,
            self::ERR_NO_ROUTE);
      }
   }

   protected function execute()
   {
      $callback = false;
      $args     = array();
      $route    = $this->current_route;
      $pre_exec = self::get_option('pre_exec');

      if (is_string($route) || $route instanceOf Closure)
      {
         $callback = $route;
         $args = array($this);
      }
      elseif (is_array($route))
      {
         if (isset($route['arguments']) && is_array($route['arguments']))
            $args = $route['arguments'];

         if (!empty($route['function']))
         {
            if (!empty($route['class']))
            {
               if (!empty($pre_exec) && is_callable($pre_exec))
                  call_user_func($pre_exec, $this->current_route);

               $class = $route['class'];
               $object = new $class($this);
               $callback = array($object, $route['function']);
            }
            else $callback = $route['function'];
         }
      }

      if (!is_callable($callback))
      {
         throw new Exception(
            'Route handler is not callable',
            self::ERR_NOT_CALLABLE);
      }
      else
      {
         $result = call_user_func_array($callback, $args);

         $post_exec = self::get_option('post_exec');
         if (!empty($post_exec) && is_callable($post_exec))
            call_user_func($post_exec, $result);

         return $result;
      }
   }

   public static function compile_selector($pattern)
   {
      $regex = false;
      if (!preg_match('#^\{#', $pattern))
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
            $method_re  = self::compile_prefix_part($method);
            $path_re    = self::compile_path_part($matches[2]);

            $regex = sprintf(
               '{^(?:%s):(?:%s):(?:%s):%s}i',
               $scheme_re,
               $host_re,
               $method_re,
               $path_re);
         }
      }
      else $regex = $pattern;

      return $regex;
   }

   public static function compile_host_glob($pattern)
   {
      if (preg_match('/^((?:\*\.)+)?(.+)/', $pattern, $matches))
      {
         $regex =
            str_replace('*.', '[^.]+\.', $matches[1]) .
            preg_quote($matches[2], '/');
      }
      else $regex = preg_quote(trim($pattern), '/');

      return $regex;
   }

   public static function compile_prefix_part($part)
   {
      if (strlen($part) > 0 && $part !== '*')
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
      else $regex = '.+';

      return $regex;
   }

   public static function compile_path_part($path)
   {
      $regex = '';
      foreach (explode('/', trim($path, '/')) as $segment)
      {
         if ($segment === '*')
            $regex .= '(?:/([^/]+))';
         elseif (strncmp($segment, '$', 1) === 0)
         {
            if (preg_match('/^(?:\$([a-z][a-z0-9]*))$/i', $segment, $matches))
               $regex .= '(?:/(?<' . $matches[1] . '>[^/]+))';
            else
               $regex .= '/' . preg_quote($segment);
         }
         elseif (strpos($segment, ',') !== false)
         {
            $list = explode(',', $segment);
            foreach ($list as $key => $value)
            {
               $value = trim($value);
               if ($value == '') continue;

               $list[$key] = preg_quote($value);
            }
            $regex .= '(?:/(' . implode('|', $list) . '))';
         }
         elseif ($segment === '~~')
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
         if (strncmp($dir, DIRECTORY_SEPARATOR, 1) !== 0)
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
