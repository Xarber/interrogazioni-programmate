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
echo json_encode(array(
  "name" => "Interrogazioni Programmate",
  "short_name" => "Interrogazioni",
  "start_url" => $startUrl,
  "display" => "standalone",
  "background_color" => "#472300",
  "theme_color" => "#472300",
  "icons" => array(
    /*
    array(
      "src" => "/images/maskable_icon_x48.png",
      "sizes" => "48x48",
      "type" => "image/png",
      "purpose" => "maskable"
    ),
    array(
      "src" => "/images/maskable_icon_x72.png",
      "sizes" => "72x72",
      "type" => "image/png",
      "purpose" => "maskable"
    ),
    array(
      "src" => "/images/maskable_icon_x96.png",
      "sizes" => "96x96",
      "type" => "image/png",
      "purpose" => "maskable"
    ),
    array(
      "src" => "/images/maskable_icon_x128.png",
      "sizes" => "128x128",
      "type" => "image/png",
      "purpose" => "maskable"
    ),*/
    array(
      "src" => "/images/maskable_icon_x192.png",
      "sizes" => "192x192",
      "type" => "image/png",
      "purpose" => "maskable"
    ),/*
    array(
      "src" => "/images/maskable_icon_x384.png",
      "sizes" => "384x384",
      "type" => "image/png",
      "purpose" => "maskable"
    ),*/
    array(
      "src" => "/images/maskable_icon_x512.png",
      "sizes" => "512x512",
      "type" => "image/png",
      "purpose" => "maskable"
    ),/*
    array(
      "src" => "/images/maskable_icon_x8000.png",
      "sizes" => "8000x8000",
      "type" => "image/png",
      "purpose" => "maskable"
    ),*/
    array(
      "src" => "/images/original-app-hd.png",
      "sizes" => "4800x4800",
      "type" => "image/png",
      "purpose" => "any"
    ),
  )
));
