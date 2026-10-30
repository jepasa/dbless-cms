<?php
/** Non-executed negative fixtures for PHP scanner regression; this file is never a public controller or required by the application. */
// ruleid: php-request-to-command
system($_GET['command']);
// ruleid: php-request-to-include
include $_GET['file'];
// ruleid: php-request-to-raw-output
echo $_GET['message'];
// ok: php-request-to-raw-output
echo htmlspecialchars($_GET['escaped'],ENT_QUOTES,'UTF-8');
