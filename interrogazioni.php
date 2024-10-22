<?php
session_start();
$_SESSION["profile"] ??= "";
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
$subjectData = in_array($subject, $subjectJSONs) ? json_decode(file_get_contents("./JSON{$PROFILE}/".$subject), true) : null;

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

if ($_GET["scope"] === "getAllData") {
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
    die(json_encode(array("status" => $okay, "newData" => ($okay ? array("subjects" => getAllData(), "users" => json_decode(file_get_contents("./JSON{$PROFILE}/users.json"), true), "profiles" => $profileList) : false))));
} else if ($_GET["scope"] === "profileMGMT") {
    if (!($userData["admin"] ?? false) && count($userList) > 0) die(json_encode(array("status" => false)));
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
    <title>Prenota Interrogazioni</title>
    <style>
        <?php echo file_get_contents("app.css") ?>
    </style>
</head>
<body>
    <div class="mainDiv">
        <?php
            if (count($userList) === 0) {
                ?>
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
                <?php
                exit;
            } else if (!$userData) {
                ?>
                    <h1><?php echo (($_GET["UID"] ?? NULL) ? "Il tuo utente non esiste!" : "Ciao! Accedi per continuare.") ?></h1>
                    <p>Inserisci un ID oppure usa un link diretto se ne hai uno a disposizione.</p>
                    <input type="text" name="UID" id="UID">
                    <button onclick="location.href = '?UID='+document.getElementById('UID').value">Accedi</button>
                <?php
            } else if (!$subjectData || ($subjectData["hide"] ?? false) === true) {
                ?>
                    <h1>Ciao, <?php echo $userData["name"]; ?>!</h1>
                    <p><?php echo ($_GET["subject"]) ? "La materia scelta non esiste! " : "" ?>Per quale materia vuoi prenotarti?</p>
                    <select name="subject" id="subject">
                        <?php
                            $eligibleCount = 0;
                            $lastEligibleSubject = "";
                            foreach ($subjectJSONs as $subject) {
                                if ($subject === "users.json") continue;
                                $subjectDataTMP = json_decode(file_get_contents("./JSON{$PROFILE}/".$subject), true);
                                if (($subjectDataTMP["hide"] ?? true) === true) continue;
                                $subject = str_replace(".json", "", $subject);
                                echo "<option value='$subject'>$subject</option>";
                                $eligibleCount = $eligibleCount + 1;
                                $lastEligibleSubject = $subject;
                            }
                            if ($eligibleCount === 0) echo "<option value='' selected disabled>Nessuna materia disponibile!</option>";
                            if ($eligibleCount === 1) echo "<script>location.href = '?UID=$userID&subject=$lastEligibleSubject'</script>";
                        ?>
                    </select>
                    <button onclick="location.href = '?UID=<?php echo $userID; ?>&subject='+document.getElementById('subject').value">Conferma</button>
                <?php
            } else {
                if (isset($subjectData["answers"][$userID])) {
                    ?>
                        <h1>Ti sei già prenotato! Non puoi cambiare la tua scelta.</h1>
                        <p>Sarai interrogato in data: <?php echo $subjectData["answers"][$userID]["date"]; ?></p>
                        <?php if ($eligibleSubjectCount > 1) { ?>
                            <button onclick="location.href = '?UID=<?php echo $userID; ?>'">Cambia Materia</button>
                        <?php } ?>
                    <?php
                } else if (isset($_GET["day"])) {
                    if (!isset($subjectData["days"][$_GET["day"]]) || strtok($subjectData["days"][$_GET["day"]]["availability"], "/") <= 0) {
                        echo "<h1>Non puoi prenotarti per questo giorno!</h1>".(strlen($_GET["day"] ?? "") < 1 ? "Devi scegliere un giorno!" : ((strtok($subjectData["days"][$_GET["day"]]["availability"], "/") <= 0) ? "<p>Non ci sono più posti liberi!</p>" : ""))."<button onclick='location.reload();'>Cambia giorno</button>";
                    } else {
                        $availability = explode("/", $subjectData["days"][$_GET["day"]]["availability"], 2);
                        $subjectData["days"][$_GET["day"]]["availability"] = ($availability[0] - 1) . "/" . $availability[1];
                        $subjectData["answerCount"] = $subjectData["answerCount"] + 1;
                        $subjectData["answers"][$userID] = array("date" => $_GET["day"], "answerNumber" => $subjectData["answerCount"]);
                        $userList[$userID]["answers"][$subjectName] ??= array();
                        array_push($userList[$userID]["answers"][$subjectName], $_GET["day"]);
                        $success = file_put_contents("./JSON{$PROFILE}/".$subject, json_encode($subjectData, JSON_PRETTY_PRINT)) && file_put_contents("./JSON{$PROFILE}/users.json", json_encode($userList, JSON_PRETTY_PRINT));
                        echo ($success ? "<h1>Ti sei prenotato!</h1><p>Ti sei prenotato a ".$subjectName." per il ".$_GET["day"]."!</p>".($eligibleSubjectCount > 1 ? "<button onclick=\"location.href = '?UID=$userID'\">Cambia Materia</button>" : "") : "<h1>Whoops! :(</h1><p>C'è stato un problema mentre provavi a prenotarti, per favore riprova o cambia giorno.</p><button onclick='location.reload();'>Cambia giorno</button>");
                    }
                } else if (count($subjectData["days"]) === 0 || $subjectData["lock"] === true) {
                    echo "<h1>Questa materia ".($subjectData["lock"] === true ? "è bloccata" : "non ha interrogazioni")."!</h1>".($eligibleSubjectCount > 1 ? "<button onclick=\"location.href = '?UID=$userID'\">Cambia Materia</button>" : "");
                } else {
                    ?>
                        <h1>Che giorno vuoi farti interrogare, <?php echo $userData["name"]; ?>?</h1>
                        <p>Non potrai cambiare la tua scelta.</p>
                        <select name="day" id="day">
                            <option value="" selected disabled>Scegli un giorno</option>
                            <?php
                                foreach ($subjectData["days"] as $key => $value) {
                                    echo "<option value='$key'".(explode("/", $value["availability"], 2)[0] == "0" ? " disabled" : "").">({$value["availability"]} Liberi) {$value["dayName"]} {$key}</option>";
                                }
                            ?>
                        </select>
                        <div class="inline">
                            <?php if ($eligibleSubjectCount > 1) { ?>
                                <button onclick="location.href = '?UID=<?php echo $userID; ?>'">Cambia Materia</button>
                            <?php } ?>
                            <button onclick="fetch('?UID=<?php echo $userID; ?>&subject=<?php echo $subjectName; ?>&day='+document.getElementById('day').value).then(r=>r.text()).then((r)=>document.write(r))">Conferma</button>
                        </div>
                    <?php
                }
            }
        ?>
    </div>
    <script>
        <?php echo file_get_contents("dash.js"); ?>
    </script>
    <script>
        const isAdmin = <?php echo ($userData["admin"] ?? false) ? "true" : "false" ?>;
        window.userData = <?php echo json_encode($userData ?? new stdClass); ?>;
        window.users = <?php echo ($userData["admin"] ?? false) ? json_encode($userList) : "{}" ?>;
        window.profiles = <?php echo (($userData["admin"] ?? false) && $PROFILE === "") ? json_encode($profileList) : "false"; ?>;
        window.isCustomProfile = <?php echo $PROFILE == "" ? "false" : str_replace("-", "", $PROFILE); ?>

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
            const datiMateria = options.data ?? <?php echo ($userData["admin"] ?? false) ? json_encode($subjectData) : "{}" ?>;
            if (!datiMateria) return false;
            const materia = options.subject ?? `<?php echo $subjectName; ?>`;
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
                    fetch("?UID=<?php echo $userID; ?>&scope=getAllData").then(r=>r.json()).then(r=>{
                        window.adminDash = new AdminDashboard(null, {
                            subjects: r,
                            updateCallback: (type, fullData, fileData, forceBlockRefresh = false)=>{
                                console.log(fullData, fileData);
                                fetch("?UID=<?php echo $userID; ?>&scope=updateSettings&type="+type, {
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
                            refreshUsers: async ()=>{
                                const res = await fetch("?UID=<?php echo $userID; ?>&scope=getAllUsers").then(r=>r.json());
                                return (res.status === false) ? {} : res;
                            },
                            refreshProfiles: async()=>{
                                const res = await fetch("?UID=<?php echo $userID; ?>&scope=listprofiles").then(r=>r.json());
                                return (res.status === false) ? [] : res;
                            },
                            isCustomProfile: window.isCustomProfile
                        });
                    });
                }, ...window.userData}) : window.dash;
            }
            if (JSON.stringify(userData) != "{}") btnDiv.appendChild(btn);
        document.documentElement.appendChild(btnDiv);
    </script>
</body>
</html>