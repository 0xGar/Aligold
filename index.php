<?php

include("../wordpress/wp-load.php");
include_once( ABSPATH . 'wp-admin/includes/image.php' );
include("./aligetter.php");
include("./aliputter.php");

$getter = new AliGoldGetter("http://127.0.0.1/test.txt");
$putter = new AliGoldPutter($getter);
$putter->run(array(
    "makeCategories"=>true,
    "makeVariations"=>true,
    "priceInflation"=>2.0
));
?>