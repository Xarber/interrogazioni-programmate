<?php
$_GET["subject"] ??= false;
$_GET["scope"] ??= false;
if (!file_exists("./JSON") || !is_dir("./JSON")) mkdir("JSON");
$subjectJSONs = array_diff(scandir("./JSON/"), array('.', '..'));
$userID = $_GET["UID"] ?? NULL;
$userList = file_exists("./JSON/users.json") ? file_get_contents("./JSON/users.json") : "";
$userList = strlen($userList) > 1 ? json_decode($userList, true) : array();
$subjectName = $_GET["subject"];
$subject = $subjectName.".json";
$userData = $userList[$userID] ?? NULL;
$subjectData = in_array($subject, $subjectJSONs) ? json_decode(file_get_contents("./JSON/".$subject), true) : null;
if ($_GET["scope"] === "getAllData") {
    if (!($userData["admin"] ?? false)) die(json_encode(array("status" => false)));
    $allSubjectsData = array();
    foreach ($subjectJSONs as $tmpsubject) {
        if ($tmpsubject === "users.json") continue;
        $subjectNameTMP = str_replace(".json", "", $tmpsubject);
        $subjectDataTMP = json_decode(file_get_contents("./JSON/".$tmpsubject), true);
        array_push($allSubjectsData, array("fileName" => $subjectNameTMP, "data" => $subjectDataTMP));
    }
    header('Content-Type: application/json');
    die(json_encode($allSubjectsData));
} else if ($_GET["scope"] === "updateSettings") {
    if (!($userData["admin"] ?? false) && count($userList) > 0) die(json_encode(array("status" => false)));
    $allSubjectsData = array();
    $body = json_decode(file_get_contents("php://input"), true);
    $okay = true;
    foreach($body as $updateSubject) {
        if ($_GET["type"] === "users") {
            if (($updateSubject["data"] ?? false) && $updateSubject["data"] === "removed") die(json_encode(array("status" => false)));
            $okay = $okay && file_put_contents("./JSON/users.json", json_encode($updateSubject, JSON_PRETTY_PRINT));
        } else if ($_GET["type"] === "subject" || !isset($_GET["type"])) {
            $originalFileName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $updateSubject["fileName"]);
            $updateSubject["fileName"] = $originalFileName.'.json';
            if ($updateSubject["fileName"] === "users.json") continue;
            $updateSubject["cleared"] ??= false;
            if ($updateSubject["data"] === "removed") {
                $okay = $okay && unlink("./JSON/".$updateSubject["fileName"]);
                foreach ($userList as $userListID => $userListData) {
                    if (isset($userList[$userListID]["answers"][$originalFileName]))
                    unset($userList[$userListID]["answers"][$originalFileName]);
                }
                $okay = $okay && file_put_contents("./JSON/users.json", json_encode($userList, JSON_PRETTY_PRINT));
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
                $okay = $okay && file_put_contents("./JSON/users.json", json_encode($userList, JSON_PRETTY_PRINT));
            }
            $okay = $okay && file_put_contents("./JSON/".$updateSubject["fileName"], json_encode($updateSubject["data"], JSON_PRETTY_PRINT));
        }
    }
    header('Content-Type: application/json');
    die(json_encode(array("status" => $okay)));
}
$eligibleSubjectCount = 0;
foreach ($subjectJSONs as $subjectNameTMP) {
    if ($subjectNameTMP === "users.json") continue;
    $subjectDataTMP = json_decode(file_get_contents("./JSON/".$subjectNameTMP), true);
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
                                $subjectDataTMP = json_decode(file_get_contents("./JSON/".$subject), true);
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
                        echo "<h1>Non puoi prenotarti per questo giorno!</h1>".((strtok($subjectData["days"][$_GET["day"]]["availability"], "/") <= 0) ? "<p>Non ci sono più posti liberi!</p>" : "")."<button onclick='location.reload();'>Cambia giorno</button>";
                    } else {
                        $availability = explode("/", $subjectData["days"][$_GET["day"]]["availability"], 2);
                        $subjectData["days"][$_GET["day"]]["availability"] = ($availability[0] - 1) . "/" . $availability[1];
                        $subjectData["answerCount"] = $subjectData["answerCount"] + 1;
                        $subjectData["answers"][$userID] = array("date" => $_GET["day"], "answerNumber" => $subjectData["answerCount"]);
                        $userList[$userID]["answers"][$subjectName] ??= array();
                        array_push($userList[$userID]["answers"][$subjectName], $_GET["day"]);
                        $success = file_put_contents("./JSON/".$subject, json_encode($subjectData, JSON_PRETTY_PRINT)) && file_put_contents("./JSON/users.json", json_encode($userList, JSON_PRETTY_PRINT));
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

            /*
                logger.group("Messaggi Link Prenotazione Materia");
                for (var e in utenti) messageArr.push("Ciao "+utenti[e].name.split(" ")[utenti[e].name.split(" ").length - 1]+"! Ti mando la pagina per prenotarsi per le interrogazioni, questo è il tuo link:\n"+location.href.split('?')[0]+"?UID="+e+"\nNON MANDARLO A NESSUNO, ALTRIMENTI POTRANNO PRENOTARE AL POSTO TUO!!\nSbrigati a scegliere il giorno altrimenti poi non ci saranno più posti :P")
                messageArr.sort(function(a, b){return 0.5 - Math.random()});
                messageArr.forEach((e)=>logger.log(e));
                logger.groupEnd();
            */

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
                            updateCallback: (type, fullData, fileData)=>{
                                console.log(fullData, fileData);
                                fetch("?UID=<?php echo $userID; ?>&scope=updateSettings&type="+type, {
                                    method: "POST",
                                    body: JSON.stringify([fileData])
                                }).then(r=>r.json()).then(r=>{
                                    console.log(r);
                                    if (r.status != true) alert("Impossibile completare l'azione!");
                                });
                            },
                            users: window.users,
                            analysisFunction: analizzaDati
                        });
                    });
                }, ...window.userData}) : window.dash;
            }
            if (JSON.stringify(userData) != "{}") btnDiv.appendChild(btn);
        document.documentElement.appendChild(btnDiv);
    </script>
</body>
</html>