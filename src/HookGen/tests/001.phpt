--TEST--
Check if hookgen is loaded
--EXTENSIONS--
hookgen
--FILE--
<?php
echo 'The extension "hookgen" is available';
?>
--EXPECT--
The extension "hookgen" is available
