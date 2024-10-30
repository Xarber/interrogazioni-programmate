<?php
// Image Background Color: #452100
// Padding: 40%
// Used Image: original-app-hd.jpeg
// Downloaded sizes: 
// ( for https://maskable.app/editor )
header("Content-Type: application/json");
session_start();
$startUrl = ($_SESSION["lastAccessID"] ?? "/interrogazioni.php");
if (!str_contains($startUrl, "UID=")) $startUrl = $startUrl."?".$_SERVER["QUERY_STRING"];
header("HTTP/1.1 301 Moved Permanently");
header("Location: $startUrl");
exit();