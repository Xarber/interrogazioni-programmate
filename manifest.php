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
  "orientation" => "any",
  "background_color" => "#472300",
  "theme_color" => "#472300",
  "icons" => array(
    array(
      "src" => "/images/maskable_icon_x192.png",
      "sizes" => "192x192",
      "type" => "image/png",
      "purpose" => "any"
    ),
    array(
      "src" => "/images/maskable_icon_x512.png",
      "sizes" => "512x512",
      "type" => "image/png",
      "purpose" => "maskable"
    )
  ),
  "id" => "atxarber-interrogazioni-programmate",
  "lang" => "it",
  "display_override" => array(
    "window-controls-overlay"
  ),
  "categories" => array(
    "education",
    "utilities"
  ),
  "description" => "Organizza facilmente e rapidamente delle interrogazioni programmate, dando solo date e disponibilità.",
  "scope" => "https://scuola.xcenter.it/",
  "launch_handler" => array(
    "client_mode" => "auto"
  )
));
