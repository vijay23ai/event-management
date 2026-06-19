<?php
echo "PHP version: " . phpversion() . "<br>";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "MySQLi extension: " . (extension_loaded('mysqli') ? 'Enabled' : 'Disabled') . "<br>";
echo "PDO MySQL extension: " . (extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled') . "<br>";
echo "PDO PGSQL extension: " . (extension_loaded('pdo_pgsql') ? 'Enabled' : 'Disabled') . "<br>";
?>
