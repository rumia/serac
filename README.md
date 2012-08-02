Barely Usable PHP Micro-framework

#### Usage Example

```php
require 'serac.php';

serac::initialize();
serac::route('/', function () {
    echo 'Run! Please, run!';
});

serac::run();

```
