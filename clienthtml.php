<?php
error_reporting(E_ERROR | E_PARSE);
session_start();
$_SESSION["profile"] ??= "";
$_SESSION["lastAccessID"] = $_SERVER["REQUEST_URI"];
$_GET["profile"] ??= false;
$_GET["subject"] ??= false;
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
        array_push($result["subjectList"], str_replace(".json", "", $subject));
    }

    $result["section"] = 
    (count($userList) === 0 ? "welcome" : (
        !$userData ? (
            count($result["profileList"]) > 0 ? "changeprofile" : "login"
        ) : (
            $_GET["changeprofile"] ? "changeprofile" : (
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
    $body = json_decode(file_get_contents("php://input"), true);
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
    $body = json_decode(file_get_contents("php://input"), true);
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
    $body = json_decode(file_get_contents("php://input"), true);
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
}
$eligibleSubjectCount = 0;
foreach ($subjectJSONs as $subjectNameTMP) {
    if ($subjectNameTMP === "users.json") continue;
    $subjectDataTMP = json_decode(file_get_contents("./JSON{$PROFILE}/".$subjectNameTMP), true);
    if (($subjectDataTMP["hide"] ?? true) === true) continue;
    $eligibleSubjectCount = $eligibleSubjectCount + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="images/original-app-hd.png" type="image/x-icon">
    <link rel="icon" href="images/original-app-hd.png" type="image/x-icon">
    <title>Prenota Interrogazioni</title>
    <link rel="stylesheet" href="app.css">
</head>
<body>
    <div class="mainDiv hided" id="welcome">
        <h1>Benvenuto, crea il primo account admin per continuare!</h1>
        <p>Inserisci il tuo nome per iniziare!</p>
        <input type="text" name="name" id="name" placeholder="Nome Utente">
        <p>Il tuo UserID / Chiave di accesso (clicca per copiare):</p>
        <input type="text" name="uid" id="uid" style="cursor: pointer;" readonly onclick="navigator.clipboard.writeText(this.value);alert('UserID Copiato!');">
        <button onclick="
            const body = {};
            body[this.parentNode.querySelector('input[name=\'uid\']').value] = {
                name: this.parentNode.querySelector('input[name=\'name\']').value,
                admin: true,
                answers: {}
            };
            fetch('?scope=updateSettings&type=users', {method: 'POST', body: JSON.stringify([body])}).then(r=>r.json()).then(r=>{
                if (!r.status) return alert('Impossibile completare l\'azione!');
                location.href = '?UID='+this.parentNode.querySelector('input[name=\'uid\']').value;
            })
        ">Accedi</button>
        <script>
            const uid = ('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            }));
            document.querySelector('input[name=\'uid\']').value = uid;
        </script>
    </div>
    <div class="mainDiv hided" id="login">
        <h1>Ciao! Accedi per continuare.</h1>
        <p>Inserisci un ID oppure usa un link diretto se ne hai uno a disposizione.</p>
        <input type="text" name="UID" id="UID">
        <button onclick="location.href = '?UID='+document.getElementById('UID').value">Accedi</button>
    </div>
    <div class="mainDiv hided" id="changeprofile">
        <h1>Scegli un profilo!</h1>
        <p>Scegli un profilo in cui sei registrato per continuare!</p>
        <select name="profile" id="profile" class="id-select-profilelist" required>
            <option selected disabled>Scegli un profilo</option>
        </select>
        <div class="inline">
            <button type="button" onclick="location.href = '?'">Cambia Utente</button>
            <button type="submit" onclick="location.href = '?profile='+document.getElementById('profile').value+'&UID='+(new URLSearchParams(location.search).get('UID'))">Accedi</button>
        </div>
    </div>
    <div class="mainDiv hided" id="schedule-subject">
        <h1 id="javascript-change-user-name">Ciao, $USERNAME!</h1>
        <p>Per quale materia vuoi prenotarti?</p>
        <select name="subject" id="subject" class="id-select-subjectlist" required>
            <option selected disabled>Scegli una materia</option>
        </select>
        <div class="inline">
            <button type="button" id="changeSubjectButton" onclick="location.href = `?UID=${window.UID}&changeProfile=true`">Cambia Materia</button>
            <button type="submit" onclick="location.href = `?UID=${window.UID}&subject=${document.getElementById('subject').value}`">Conferma</button>
        </div>
    </div>
    <div class="mainDiv hided" id="dayunavailable">
        <h1>Non puoi prenotarti per questo giorno!</h1>
    </div>
    <div class="mainDiv hided" id="alreadyscheduled">
        <h1>Ti sei già prenotato! Non puoi cambiare la tua scelta.</h1>
        <p id="javascript-change-schedule-data">Sarai interrogato in data: $SUBJECTDATE</p>
        <button id="changeSubjectButton" onclick="location.href = `?UID=${window.UID}`">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="scheduleconfirmed">
        <h1>Ti sei prenotato!</h1>
        <p id="javascript-change-schedule-data">Ti sei prenotato a $SUBJECTNAME per il $SUBJECTDATE!</p>
        <button id="changeSubjectButton" onclick="location.href = `?UID=${window.UID}`">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="schedulefailed">
        <h1>Whoops! :( </h1>
        <p>C'è stato un problema mentre provavi a prenotarti, per favore riprova o cambia giorno.</p>
        <button onclick='location.reload();'>Cambia giorno</button>
    </div>
    <div class="mainDiv hided" id="nodays">
        <h1>Questa materia è bloccata o non ha interrogazioni!</h1>
        <button id="changeSubjectButton" onclick="location.href = `?UID=${window.UID}`">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="schedule-day">
        <h1>Che giorno vuoi farti interrogare?</h1>
        <p>Non potrai cambiare la tua scelta.</p>
        <select name="day" id="day" class="id-select-daylist">
            <option value="" selected disabled>Scegli un giorno</option>
        </select>
        <div class="inline">
            <button id="changeSubjectButton" onclick="location.href = `?UID=${window.UID}`">Cambia Materia</button>
            <button onclick="fetch(`?UID=${window.UID}&subject=${window.SUBJECT}&day=${document.getElementById('day').value}`).then(r=>r.text()).then((r)=>document.write(r))">Conferma</button>
        </div>
    </div>
    </div>
    <script>
        (()=>{var script = document.createElement('script');script.src="//cdn.jsdelivr.net/npm/eruda";document.body.appendChild(script);script.onload = ()=>{
            eruda.init();
            document.querySelector('#eruda').style.display = "none";
            var toggleBtn = document.createElement('button');
            toggleBtn.style.position = "fixed";
            toggleBtn.style.left = "8px";
            toggleBtn.style.top = "8px";
            toggleBtn.style.width = "30px";
            toggleBtn.style.height = "30px";
            toggleBtn.style.padding = "0";
            toggleBtn.style.border = "0";
            toggleBtn.style.opacity = "0";
            toggleBtn.style.cursor = "default";
            toggleBtn.style.margin = "0";
            toggleBtn.style.zIndex = "2147483647";
            toggleBtn.innerHTML = "";
            toggleBtn.onclick = ()=>document.querySelector('#eruda').style.display = document.querySelector('#eruda').style.display === "none" ? "block" : "none";
            document.body.appendChild(toggleBtn);
        }})();
    </script>
    <script src="dash.js"></script>
    <script>
        const PWA = window.matchMedia('(display-mode: standalone)').matches;
        window.UID = new URLSearchParams(location.search).get('uid');
        window.SUBJECT = new URLSearchParams(location.search).get('subject');

        var link = document.createElement('link');
        link.rel = 'manifest';
        link.href = `manifest.php?UID=${window.UID}`;
        document.head.appendChild(link);

        (async ()=>{
            window.pageData = await fetch(`?UID=${window.UID}&scope=loadPageData`).then(r=>r.json());
            /*
            {
                section: "sectionName",
                user: {...userData, subjectData: {day: "whenAreTheyScheduled"}};
                users: [userList] (IF ADMIN),

                profiled: boolean (is custom profile),
                profiles: [profileData],
                profileList: [{name: profileName, admin: isUserAdmin}],

                subject: {name, days, lock}
                subjectList: [subjects]
            }
            */
            window.userData = window.pageData.user;
            const isAdmin = window.userData.admin;
            window.users = window.pageData.users;
            window.profiles = window.pageData.profiles;
            window.isCustomProfile = window.pageData.profiled;
            window.notifications = new PushNotifications(window.UID);
            if (!!window.UID && window.UID.length > 0) localStorage["lastUID"] = window.UID;
            localStorage["lastPathName"] = location.pathname;

            for (var profile in window.pageData.profileList) {
                document.querySelector('select.id-select-profilelist').innerHTML += `<option value="${window.pageData.profileList[profile].name}">${profile.admin ? `<b>Admin</b> ` : ``}${window.pageData.profileList[profile].name}</option>`;
            }
            for (var subject of window.pageData.subjectList) {
                document.querySelector('select.id-select-subjectlist').innerHTML += `<option value="${subject}">${subject}</option>`;
            }
            for (var day in window.pageData.subject.days) {
                document.querySelector('select.id-select-daylist').innerHTML += `<option value="${day}" ${window.pageData.subject.days[day].availability.split('/')[0] === "0" ? "disabled" : ""}>(${window.pageData.subject.days[day].availability} Liberi) ${window.pageData.subject.days[day].dayName} ${day}</option>`;
            }

            document.querySelectorAll('#javascript-change-user-name').forEach(e=>e.innerHTML = e.innerHTML.replaceAll('$USERNAME', window.userData.name));
            document.querySelectorAll('#javascript-change-schedule-data').forEach(e=>e.innerHTML = e.innerHTML.replaceAll('$SUBJECTDATE', window.userData.subjectData.day).replaceAll('$SUBJECTNAME', window.SUBJECT));

            document.querySelector('div.mainDiv#'+window.pageData.section).classList.remove('hided');

            window.notifications.status().then(async r=>{
                if (r != true) return;
                const sw = await navigator.serviceWorker.getRegistration();
                if (!window.userData.pushSubscriptions || !sw) return window.notifications.unsubscribe();
                const sub = await sw.pushManager.getSubscription();
                const userSubscriptions = new Set();
                for (var rsub of window.userData.pushSubscriptions) userSubscriptions.add(JSON.stringify(rsub));
                if (!userSubscriptions.has(JSON.stringify(sub))) return window.notifications.unsubscribe(false);
                window.notifications.update();
                navigator.serviceWorker.addEventListener("message", (e)=>console.log(JSON.parse(e.data)));
            })

            function analizzaDati(options = {
                clipboard: false,
                copy: "json",
                log: true,
                data: undefined,
                subject: undefined,
                users: undefined,
            }) {
                const copyToClipboard = options.clipboard ?? false;
                const valueToCopy = options.copy ?? "json";
                const logger = options.log ? console : {group: ()=>{}, groupEnd: ()=>{}, error: ()=>{}, log: ()=>{}};

                const utenti = options.users ?? window.users;
                const datiMateria = options.data ?? window.pageData.currentSubject;
                if (!datiMateria) return false;
                const materia = options.subject ?? window.SUBJECT;
                const messageArr = [];
                var listaPrenotazioni = {};

                for (var data in datiMateria.days ?? []) {
                    listaPrenotazioni[data] ??= {
                        header: `[${datiMateria.days[data].availability}] ${datiMateria.days[data].dayName} ${data}`,
                        answers: []
                    }
                }

                for (var utente in datiMateria.answers) {
                    let date = datiMateria.answers[utente].date;
                    listaPrenotazioni[datiMateria.answers[utente].date] ??= {
                        header: `[??] ${new Date(`${date.split("-")[1]}-${date.split("-")[0]}-${date.split("-")[2]}`).toLocaleString("it-IT", {weekday: "long"})} ${datiMateria.answers[utente].date}`,
                        answers: []
                    };
                    listaPrenotazioni[datiMateria.answers[utente].date].answers.push(`[${datiMateria.answers[utente].answerNumber}] ${utenti[utente].name}`)
                }

                var listaPrenotazioniText = `[${materia}] Prenotati (Numero Risposta, Nome): ---\n`;
                for (var data in listaPrenotazioni) {
                    listaPrenotazioniText+="\n"+data+"\n"+listaPrenotazioni[data].answers.join("\n")+"\n";
                }
                
                logger.group("Lista Prenotazioni per "+materia);
                listaPrenotazioniText.length > 0 && logger.log(listaPrenotazioniText);
                logger.groupEnd();

                const returnOBJ = {
                    prenotazioni: listaPrenotazioniText,
                    linkUtente: messageArr
                };

                if (copyToClipboard) {
                    var toCopy = "";
                    if (!valueToCopy) valueToCopy = "json";
                    if (valueToCopy != "json" && returnOBJ[valueToCopy]) toCopy = returnOBJ[valueToCopy];
                    else toCopy = JSON.stringify(returnOBJ);
                    navigator.clipboard.writeText(toCopy);
                }

                return returnOBJ;
            }

            let btnDiv = document.createElement("div");
                btnDiv.style.display = "flex";
                btnDiv.style.position = "fixed";
                btnDiv.style.bottom = "10px";
                btnDiv.style.right = "10px";
                btnDiv.style.gap = "2.5px";

            if (isAdmin) {
                if (analizzaDati() != false) {
                    let btn = document.createElement("button");
                        btn.innerHTML = "Copia le prenotazioni";
                        btn.onclick = ()=>{
                            btn.innerHTML = "Prenotazioni copiate!";
                            setTimeout(()=>btn.innerHTML = "Copia le prenotazioni", 3000);
                            analizzaDati({
                                clipboard: true, 
                                copy: "prenotazioni", 
                                log: false
                            });
                        };
                    btnDiv.appendChild(btn);
                }
            }
            
            let btn = document.createElement("button");
                btn.innerHTML = "Dati utente";
                btn.onclick = ()=>{
                    window.dash = (!!(window.dash ?? {closed: true}).closed) ? new UserDashboard(null, {admin: isAdmin, onOpenAdminDash: ()=>{
                        fetch(`?UID=${window.UID}&scope=getAllData`).then(r=>r.json()).then(r=>{
                            window.adminDash = new AdminDashboard(null, {
                                subjects: r,
                                updateCallback: (type, fullData, fileData, forceBlockRefresh = false)=>{
                                    console.log(fullData, fileData);
                                    fetch(`?UID=${window.UID}&scope=updateSettings&type=${type}`, {
                                        method: "POST",
                                        body: JSON.stringify([fileData])
                                    }).then(r=>r.json()).then(r=>{
                                        console.log(r);
                                        if (r.status != true) alert("Impossibile completare l'azione!");
                                        else {
                                            // alert("Dati aggiornati con successo!");
                                            !forceBlockRefresh && window.adminDash && window.adminDash.update({
                                                subjects: r.newData.subjects,
                                                users: r.newData.users,
                                                profiles: !!r.newData.profiles ? r.newData.profiles : undefined
                                            });
                                        }
                                    });
                                },
                                users: window.users,
                                profiles: window.profiles,
                                analysisFunction: analizzaDati,
                                notificationClass: window.notifications,
                                refreshUsers: async ()=>{
                                    const res = await fetch(`?UID=${window.UID}&scope=getAllUsers`).then(r=>r.json());
                                    return (res.status === false) ? {} : res;
                                },
                                refreshProfiles: async()=>{
                                    const res = await fetch(`?UID=${window.UID}&scope=profileMGMT`, {
                                        method: "POST",
                                        body: JSON.stringify({action: "listprofiles"})
                                    }).then(r=>r.json());
                                    return (res.status === false || !res.profiles) ? [] : res.profiles;
                                },
                                isCustomProfile: window.isCustomProfile
                            });
                        });
                    }, ...window.userData}, window.notifications) : window.dash;
                }
                if (JSON.stringify(userData) != "{}") btnDiv.appendChild(btn);
            document.documentElement.appendChild(btnDiv);
        })();

        window.addEventListener("beforeinstallprompt", (e)=>{
            e.preventDefault();
            window.installEvent = e;
            window.btn2 = window.btn2 ?? document.createElement("button");
            btn2.innerHTML = "Installa App";
            btn2.onclick = ()=>{
                window.installEvent && window.installEvent.prompt();
            }
            btnDiv.appendChild(btn2);
        });
    </script>
</body>
</html>