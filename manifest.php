<?php
header("Content-Type: application/json");
echo json_encode(array(
  "name" => "Interrogazioni Programmate",
  "short_name" => "Interrogazioni",
  "start_url" => $_SERVER['REQUEST_URI'],
  "display" => "standalone",
  "background_color" => "#472300",
  "theme_color" => "#472300",
  "icons" => array()
));