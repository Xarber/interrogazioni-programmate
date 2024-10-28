<?php
header("Content-Type: application/json");
session_start();
$startUrl = ($_SESSION["lastAccessID"] ?? "/interrogazioni.php");
if (!str_contains($startUrl, "UID=")) $startUrl = $startUrl."?".$_SERVER["QUERY_STRING"];
echo json_encode(array(
  "name" => "Interrogazioni Programmate",
  "short_name" => "Interrogazioni",
  "start_url" => $startUrl,
  "display" => "standalone",
  "background_color" => "#472300",
  "theme_color" => "#472300",
  "icons" => array(
    array(
      "src" => "/images/app-192x192.png",
      "sizes" => "192x192",
      "type" => "image/png",
      "purpose" => "maskable"
    ),
    array(
      "src" => "/images/app-512x512.png",
      "sizes" => "512x512",
      "type" => "image/png",
      "purpose" => "maskable"
    ),
    array(
      "src" => "/images/app-4800x4800.png",
      "sizes" => "4800x4800",
      "type" => "image/png",
      "purpose" => "maskable"
    )
  )
));
