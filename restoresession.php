<?php
header("Content-Type: application/json");
session_start();
$parameters = [];
if (!empty($_SESSION['userID'])) $parameters['UID'] = $_SESSION['userID'];
if (!empty($_SESSION['classId'])) $parameters['class'] = $_SESSION['classId'];
$startUrl = '/interrogazioni.php' . ($parameters === [] ? '' : '?' . http_build_query($parameters));
header("HTTP/1.1 301 Moved Permanently");
header("Location: $startUrl");
exit();
