<?php
header("Content-Type: application/json");
session_start();
$startUrl = ("/interrogazioni.php?UID=".$_SESSION["userID"]);
if (!str_contains($startUrl, "UID=")) $startUrl = $startUrl."?".$_SERVER["QUERY_STRING"];
header("HTTP/1.1 301 Moved Permanently");
header("Location: $startUrl");
exit();