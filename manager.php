<?php
error_reporting(E_ERROR | E_PARSE);
session_start();
$_SESSION["profile"] ??= "";
$_SESSION["lastAccessID"] = $_SERVER["REQUEST_URI"];
$body = strlen(file_get_contents("php://input")) > 1 ? json_decode(file_get_contents("php://input"), true) : array();
$_ORIGINALGET = $_GET;
$_GET = array_merge($_GET, $_POST, $body);
$_GET["profile"] = $_ORIGINALGET["profile"] ?? false;
$_GET["subject"] = $_ORIGINALGET["subject"] ?? false;
$_GET["scope"] ??= false;
$_GET["saveProfile"] ??= "true";
if ($_GET["saveProfile"] === "false" && !true) { //This is a very beta feature and stuff does break with it. Please don't use it.
    $PROFILE = ($_GET["profile"] == "" || $_GET["profile"] == "default") ? "" : ("-".$_GET["profile"]);
} else {
    if (!!$_GET["profile"]) $_SESSION["profile"] = $_GET["profile"];
    if ($_GET["profile"] === "default") $_SESSION["profile"] = "";
    $_SESSION["profile"] = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $_SESSION["profile"]);
    $PROFILE = $_SESSION["profile"] === "" ? "" : ("-".$_SESSION["profile"]);
}
var_dump($PROFILE);
var_dump($_GET["profile"]);
var_dump($_SESSION["profile"]);
if (!file_exists("./JSON{$PROFILE}") || !is_dir("./JSON{$PROFILE}")) {
    if ($PROFILE != "") {
        header('Content-Type: application/json');
        die(json_encode(array("status" => false, "message" => "This profile does not exist!")));
    }
    mkdir("./JSON{$PROFILE}");
}

function copyFolder($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);

    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            if (is_dir("$src/$file")) {
                copyFolder("$src/$file", "$dst/$file");
            } else {
                copy("$src/$file", "$dst/$file");
            }
        }
    }
    closedir($dir);
}
function deleteFolder($dir) {
    if (!is_dir($dir)) return;

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item) : unlink($item);
    }
    rmdir($dir);
}

$subjectJSONs = array_diff(scandir("./JSON{$PROFILE}/"), array('.', '..'));
$userID = $_GET["UID"] ?? NULL;
$userList = file_exists("./JSON{$PROFILE}/users.json") ? file_get_contents("./JSON{$PROFILE}/users.json") : "";
$userList = strlen($userList) > 1 ? json_decode($userList, true) : array();
$subjectName = $_GET["subject"];
$subject = $subjectName.".json";
$userData = $userList[$userID] ?? NULL;
$subjectData = in_array($subject, $subjectJSONs) ? json_decode(file_get_contents("./JSON{$PROFILE}/".$subject), true) : false;

$profileListRAW = array_diff(scandir("."), array('.', '..'));
$profileListRAW = array_filter($profileListRAW, function($item) {
    return strpos($item, 'JSON-') === 0;
});
$profileList = array_map(function($item) {
    return substr($item, 5);
}, array_values($profileListRAW));

function getAllData() {
    global $userData, $PROFILE;
    if (!($userData["admin"] ?? false)) return false;
    $allSubjectsData = array();

    $subjectJSONs = array_diff(scandir("./JSON{$PROFILE}/"), array('.', '..'));
    foreach ($subjectJSONs as $tmpsubject) {
        if ($tmpsubject === "users.json") continue;
        $subjectNameTMP = str_replace(".json", "", $tmpsubject);
        $subjectDataTMP = json_decode(file_get_contents("./JSON{$PROFILE}/".$tmpsubject), true);
        array_push($allSubjectsData, array("fileName" => $subjectNameTMP, "data" => $subjectDataTMP));
    }
    return $allSubjectsData;
}

if ($_GET["scope"] === "loadPageData") {
    header('Content-Type: application/json');
    $result = array();
    $result["user"] = array_merge($userData ?? array(), array("subjectData" => array(
        "day" => isset($subjectData["answers"][$userID]) ? $subjectData["answers"][$userID]["date"] : false
    )));

    $result["subject"] = $subjectData ? array("name" => $subjectName, "days" => $subjectData["days"], "lock" => $subjectData["lock"]) : false;

    $result["users"] = $userData["admin"] ? $userList : array();
    $result["profiled"] = $PROFILE != "";
    $result["profiles"] = $userData["admin"] ? $profileList : array();
    
    $result["profileList"] = array();
    $result["subjectList"] = array();
    foreach ($profileList as $profile) {
        $profileUserData = file_exists("./JSON-{$profile}/users.json") ? json_decode(file_get_contents("./JSON-{$profile}/users.json"), true) : array();
        if ($profileUserData[$_GET["UID"]] ?? false) array_push($result["profileList"], array("name" => $profile, "admin" => $profileUserData[$_GET["UID"]]["admin"] ?? false ));
    }
    foreach ($subjectJSONs as $subject) {
        if ($subject === "users.json") continue;
        if (json_decode(file_get_contents("./JSON{$PROFILE}/".$subject), true)["hide"] ?? false) continue;
        array_push($result["subjectList"], str_replace(".json", "", $subject));
    }

    $result["section"] = 
    (count($userList) === 0 ? "welcome" : (
        !$userData ? (
            count($result["profileList"]) > 0 ? "changeprofile" : "login-account-not-found"
        ) : (
            (isset($_GET["changeProfile"])) ? "changeprofile" : (
                ((!$subjectData) || (($subjectData["hide"] ?? false) === true)) ? "schedule-subject" : (
                    (isset($subjectData["answers"][$userID])) ? "alreadyscheduled" : (
                        ((isset($_GET["day"])) && (!isset($subjectData["days"][$_GET["day"]]) || strtok($subjectData["days"][$_GET["day"]]["availability"], "/") <= 0)) ? "dayunavailable" : (
                            (count($subjectData["days"] ?? array()) === 0 || $subjectData["lock"] === true) ? "nodays" : "schedule-day"
                        )
                    )
                )
            )
        )
    ));

    die(json_encode($result, JSON_PRETTY_PRINT));
} else if ($_GET["scope"] === "getAllData") {
    if (!($userData["admin"] ?? false)) die(json_encode(array("status" => false)));
    $allSubjectsData = getAllData();
    header('Content-Type: application/json');
    die(json_encode($allSubjectsData, JSON_PRETTY_PRINT));
} else if ($_GET["scope"] === "getAllUsers") {
    if (!($userData["admin"] ?? false)) die(json_encode(array("status" => false)));
    header('Content-Type: application/json');
    die(json_encode($userList, JSON_PRETTY_PRINT));
} else if ($_GET["scope"] === "updateSettings") {
    if (!($userData["admin"] ?? false) && count($userList) > 0) die(json_encode(array("status" => false)));
    $allSubjectsData = array();
    $okay = true;
    foreach($body as $updateSubject) {
        if ($_GET["type"] === "users") {
            if (($updateSubject["data"] ?? false) && $updateSubject["data"] === "removed") die(json_encode(array("status" => false)));
            $okay = $okay && file_put_contents("./JSON{$PROFILE}/users.json", json_encode($updateSubject, JSON_PRETTY_PRINT));
        } else if ($_GET["type"] === "subject" || !isset($_GET["type"])) {
            $originalFileName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $updateSubject["fileName"]);
            $updateSubject["fileName"] = $originalFileName.'.json';
            if ($updateSubject["fileName"] === "users.json") continue;
            $updateSubject["cleared"] ??= false;
            if ($updateSubject["data"] === "removed") {
                $okay = $okay && unlink("./JSON{$PROFILE}/".$updateSubject["fileName"]);
                foreach ($userList as $userListID => $userListData) {
                    if (isset($userList[$userListID]["answers"][$originalFileName]))
                    unset($userList[$userListID]["answers"][$originalFileName]);
                }
                $okay = $okay && file_put_contents("./JSON{$PROFILE}/users.json", json_encode($userList, JSON_PRETTY_PRINT));
                continue;
            }
            if ($updateSubject["cleared"] === true) {
                foreach ($userList as $userListID => $userListData) {
                    if (isset($userList[$userListID]["answers"][$originalFileName]))
                    unset($userList[$userListID]["answers"][$originalFileName]);
                }
                foreach ($updateSubject["data"]["days"] as $day => $dayData) {
                    $availability = explode("/", $dayData["availability"], 2);
                    $updateSubject["data"]["days"][$day]["availability"] = ($availability[1]) . "/" . $availability[1];
                }
                $okay = $okay && file_put_contents("./JSON{$PROFILE}/users.json", json_encode($userList, JSON_PRETTY_PRINT));
            }
            $okay = $okay && file_put_contents("./JSON{$PROFILE}/".$updateSubject["fileName"], json_encode($updateSubject["data"], JSON_PRETTY_PRINT));
        }
    }
    header('Content-Type: application/json');
    die(json_encode(array("status" => $okay, "newData" => ($okay ? array("subjects" => getAllData(), "users" => json_decode(file_get_contents("./JSON{$PROFILE}/users.json"), true), "profiles" => ($PROFILE == "" ? $profileList : false)) : false))));
} else if ($_GET["scope"] === "profileMGMT") {
    if (!($userData["admin"] ?? false)) die(json_encode(array("status" => false)));
    header('Content-Type: application/json');
    $target = preg_replace('/[^a-zA-Z0-9_-]+/', '-', ($body["profile"]??""));
    $body["action"]??="";
    $body["method"]??="";
    $body["newName"]??=false;
    if ($body["action"] != "listprofiles" && ($target === "default" || $target === "")) die(json_encode(array("status" => false, "message" => "You can't change this profile!")));
    if ($body["action"] === "newprofile") {
        if (file_exists("./JSON-{$target}")) die(json_encode(array("status" => false, "message" => "This profile already exists!")));
        if ($body["method"] === "import") {
            copyFolder("./JSON{$PROFILE}", "./JSON-{$target}");
            die(json_encode(array("status" => true)));
        } else {
            $okay = mkdir("./JSON-{$target}");
            $newUserData = array($userID => $userList[$userID]);
            $newUserData[$userID]["answers"] = array();
            $okay = $okay && file_put_contents("./JSON-{$target}/users.json", json_encode($newUserData, JSON_PRETTY_PRINT));
            die(json_encode(array("status" => $okay)));
        }
    } else if ($body["action"] === "listprofiles") {
        if ($PROFILE != "") die(json_encode(array("status" => false, "message" => "You can only list profiles from the main one!")));
        die(json_encode(array("status" => true, "profiles" => $profileList)));
    } else {
        if (!file_exists("./JSON-{$target}")) die(json_encode(array("status" => false, "message" => "This profile does not exist!")));
        if ($body["action"] === "renameprofile") {
            if (!$body["newName"]) die(json_encode(array("status" => false, "message" => "You must specify a new name!")));
            if ($body["newName"] === "default" || $body["newName"] === "") die(json_encode(array("status" => false, "message" => "This name is forbidden!")));
            $newName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $body["newName"]);
            $okay = rename("./JSON-{$target}", "./JSON-{$newName}");
            die(json_encode(array("status" => $okay), JSON_PRETTY_PRINT));
        } else if ($body["action"] === "deleteprofile") {
            deleteFolder("./JSON-{$target}");
            die(json_encode(array("status" => true)));
        }
        die(json_encode(array("status" => false, "message" => "Invalid action!")));
    }
} else if ($_GET["scope"] === "downloadProfile") {
    //! REQUIREMENTS:
    //! $ apt-get install php-zip

    if (!($userData["admin"] ?? false)) {
        header('Content-Type: application/json');
        die(json_encode(array("status" => false)));
    }

    $_GET["profileName"] ??= "";
    $profileName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $_GET["profileName"]);
    if ($profileName == "" || $profileName == "default") {
        header('Content-Type: application/json');
        die(json_encode(array("status" => false, "message" => "You can't download this profile!")));
    }

    if (!file_exists("./JSON-{$profileName}") || !is_dir("./JSON-{$profileName}")) {
        header('Content-Type: application/json');
        die(json_encode(array("status" => false, "message" => "This profile does not exist!")));
    }

    $zip = new ZipArchive();
    $zipFilename = tempnam(sys_get_temp_dir(), 'zip');
    if ($zip->open($zipFilename, ZipArchive::CREATE) !== TRUE) {
        header('Content-Type: application/json');
        die(json_encode(array("status" => false, "message" => "Internal Server Error!")));
    }

    $zip->addFromString('profile.json', json_encode(array("name" => $profileName, "date" => date("d.m.Y"))));

    $zip->addEmptyDir('data');
    $profileData = array_diff(scandir("./JSON-{$profileName}/"), array('.', '..'));
    foreach ($profileData as $profileFile) {
        $zip->addFile("./JSON-{$profileName}/{$profileFile}", "data/{$profileFile}");
    }

    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$profileName.'.profile.zip"');
    header('Content-Length: ' . filesize($zipFilename));

    readfile($zipFilename);
    unlink($zipFilename);
    die();
} else if ($_GET["scope"] === "uploadProfile") {
    //! REQUIREMENTS:
    //! $ apt-get install php-zip

    header('Content-Type: application/json');
    if (!($userData["admin"] ?? false)) die(json_encode(array("status" => false)));
    if (!isset($_FILES["profileData"])) die(json_encode(array("status" => false, "message" => "No file uploaded.")));
    $file = $_FILES['profileData']['tmp_name'];
    $tempDir = sys_get_temp_dir() . '/' . uniqid('zip_', true);

    if (!mkdir($tempDir, 0700, true)) die(json_encode(array("status" => false, "message" => "Internal Server Error!")));
    $destination = $tempDir . '/' . $_FILES['profileData']['name'];

    if (!move_uploaded_file($file, $destination)) die(json_encode(array("status" => false, "message" => "Internal Server Error!")));

    $zip = new ZipArchive;
    if ($zip->open($destination) != TRUE) die(json_encode(array("status" => false, "message" => "Internal Server Error!")));

    $zip->extractTo($tempDir);
    $zip->close();
    
    if (!file_exists("{$tempDir}/profile.json") || !file_exists("{$tempDir}/data") || !is_dir("{$tempDir}/data")) echo (json_encode(array("status" => false, "message" => "Invalid profile zip!")));
    else {
        $profileData = json_decode(file_get_contents("{$tempDir}/profile.json"), true);
        $profileName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $profileData["name"]);
        if ($profileName == "" || $profileName == "default") echo json_encode(array("status" => false, "message" => "Invalid profile name!"));
        else {
            rename("{$tempDir}/data", "./JSON-{$profileName}");
            echo json_encode(array("status" => true, "profileName" => $profileName));
        }
    }
    unlink($destination);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file) : unlink($file);
    }
    rmdir($tempDir);
    die();
} else if ($_GET["scope"] === "notifications") {
    if (!$userData) die(json_encode(array("status" => false, "message" => "Not Authorized!")));

    /*
    const data = {
        title: bodyData.title,
        body: bodyData.body,
        icon: bodyData.icon,
        url: bodyData.url,
        subscriptions: bodyData.subscriptions
    };
    */

    switch ($body["action"] ?? "invalid") {
        case "VAPIDkey":
            $body["path"] = "/api/vapid-public-key";
        break;

        case "subscribe":
            $body["path"] = "/api/subscribe";

            $body["subscription"] ??= array();
            if (!isset($body["subscription"]["endpoint"]) || !isset($body["subscription"]["keys"])) die(json_encode(array("status" => false, "message" => "Invalid Subscription!")));
            $userList[$userID]["pushSubscriptions"] ??= array();
            if (str_contains(json_encode($userList[$userID]["pushSubscriptions"]), json_encode($body["subscription"]))) die(json_encode(array("status" => true, "message" => "This subscription already exists!")));
            array_push($userList[$userID]["pushSubscriptions"], $body["subscription"]);

            $okay = file_put_contents("./JSON{$PROFILE}/users.json", json_encode($userList, JSON_PRETTY_PRINT));
            die(json_encode(array("status" => !!$okay, "message" => null))); //This is not server-managed anymore
        break;

        case "unsubscribe":
            $body["path"] = "/api/unsubscribe";

            if ($body["subscription"] && isset($userList[$userID]["pushSubscriptions"])) {
                $newPushSubs = array();
                foreach($userList[$userID]["pushSubscriptions"] as $key => $subscription) {
                    if (json_encode($subscription) != json_encode($body["subscription"])) array_push($newPushSubs, $subscription);
                }
                $userList[$userID]["pushSubscriptions"] = $newPushSubs;
                if (count($userList[$userID]["pushSubscriptions"]) === 0) unset($userList[$userID]["pushSubscriptions"]);
            } else unset($userList[$userID]["pushSubscriptions"]);

            $okay = file_put_contents("./JSON{$PROFILE}/users.json", json_encode($userList, JSON_PRETTY_PRINT));

            die(json_encode(array("status" => !!$okay, "message" => null))); //This is not server-managed anymore
        break;

        case "sendNotifications": 
            if (!$userData["admin"]) die(json_encode(array("status" => false, "message" => "Not Authorized!")));
            $body["path"] = "/api/send-notification";
            $body["users"] ??= array();
            $body["subscriptions"] = array();
            foreach ($body["users"] as $user) {
                if (!$userList[$user] || !isset($userList[$user]["pushSubscriptions"])) continue;
                $body["subscriptions"] = array_merge($body["subscriptions"], $userList[$user]["pushSubscriptions"]);
            }
        break;

        default: 
            die(json_encode(array("status" => false, "message" => "Invalid Action!")));
        break;
    }

    $url = 'http://localhost:5743'.($body["path"]);

    // Convert data array to JSON
    $data_json = json_encode($body);

    // Create a stream context
    $options = array(
        'http' => array(
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => $data_json,
        ),
    );
    $context = stream_context_create($options);

    // Send the request and get the response
    $response = file_get_contents($url, false, $context);

    // Check if the request was successful
    if ($response === false) die(json_encode(array("status" => false, "message" => "Internal Server Error!")));

    // Process the response
    die($response);
} else if ($_GET["scope"] === "schedule") {
    if (!$subjectData || !$subjectData["days"][$_GET["day"]]) die(json_encode(array("status" => false, "message" => "Invalid Day!")));
    $availability = explode("/", $subjectData["days"][$_GET["day"]]["availability"], 2);
    if ($availability[0] == "0") die(json_encode(array("status" => false, "message" => "Invalid Day!")));
    $subjectData["days"][$_GET["day"]]["availability"] = ($availability[0] - 1) . "/" . $availability[1];
    $subjectData["answerCount"] = $subjectData["answerCount"] + 1;
    $subjectData["answers"][$userID] = array("date" => $_GET["day"], "answerNumber" => $subjectData["answerCount"]);
    $userList[$userID]["answers"][$subjectName] ??= array();
    array_push($userList[$userID]["answers"][$subjectName], $_GET["day"]);
    $success = file_put_contents("./JSON{$PROFILE}/".$subject, json_encode($subjectData, JSON_PRETTY_PRINT)) && file_put_contents("./JSON{$PROFILE}/users.json", json_encode($userList, JSON_PRETTY_PRINT));
    die(json_encode(array("status" =>!!$success, "message" => null)));
}