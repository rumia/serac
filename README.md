Barely Usable PHP Micro-framework

    require "serac.php";

    $vaan = new serac;

    $vaan["/"] = function () {
        echo "I'M CAPTAIN BASCH FON RONSENBURG OF DALMASCA!";
    };

    $vaan->run();
