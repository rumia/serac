Barely Usable PHP Micro-framework

#### Usage Example

```php
<?php
require 'serac.php';

serac::initialize();
serac::route('/', function () {
    echo 'Run! Please, run!';
});

// handling http method POST to any URI
serac::route('post:/~~', function () {
	echo 'Did you just submit something?';
});

// handling request to specific hosts
serac::route('*.example.com:*:/home', function ($serac) {
	printf("You sent a request to %s", $serac->hostname);
});

// handle only https request
serac::route('https:*:*:/~~', function () {
	echo 'This is supposed to be a secure channel';
});

// use method action_* from class controller_* as the handler.
// the class file must be located in directory controller/ relative
// to base class directory specified in `class_dir` option.
serac::route('/$class/$method/~~', array(
	'class' => 'controller_$class',
	'function' => 'action_$method',
	'arguments' => '$3'
));

serac::run();

```

For PHP < 5.3, you can use the name of the function as the route handler.

```php
<?php
require 'serac.php';

function default_route()
{
	echo 'Run! Please, Run!';
}

serac::initialize();
serac::route('/', 'default_route');
serac::run();

```
