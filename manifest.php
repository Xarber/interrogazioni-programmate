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
  "icons" => array(
    array(
      "src" => "/images/app-192x192.png",
      "sizes" => "192x192",
      "type" => "image/png"
    ),
    array(
      "src" => "/images/app-512x512.png",
      "sizes" => "512x512",
      "type" => "image/png"
    )
  )
));