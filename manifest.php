<?php
header("Content-Type: application/json");
session_start();
echo json_encode(array(
  "name" => "Interrogazioni Programmate",
  "short_name" => "Interrogazioni",
  "start_url" => ($_SESSION["lastAccessID"] ?? "/interrogazioni.php")."?".$_SERVER['QUERY_STRING'],
  "display" => "standalone",
  "background_color" => "#472300",
  "theme_color" => "#472300",
  "icons" => array()
));