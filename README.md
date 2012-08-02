Barely Usable PHP Micro-framework

#### Usage Example

```php
<?php
require 'serac.php';

serac::initialize();
serac::route('/', function () {
    echo 'Run! Please, run!';
});

serac::run();

```
