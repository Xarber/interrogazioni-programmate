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
            fetch(`manager.php?scope=updateSettings&type=users`, {method: 'POST', body: JSON.stringify([body])}).then(r=>r.json()).then(r=>{
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
    <div class="mainDiv hided" id="login-account-not-found">
        <h1>Il tuo account non esiste!</h1>
        <p>Inserisci un nuovo ID oppure usa un link diretto se ne hai uno a disposizione.</p>
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
            <button type="button" id="changeProfileButton" onclick="location.href = `?UID=${window.UID}&changeProfile=true`">Cambia Profilo</button>
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
            <button onclick="fetch(`manager.php?UID=${window.UID}&subject=${window.SUBJECT}&day=${document.getElementById('day').value}`).then(r=>r.text()).then((r)=>document.write(r))">Conferma</button>
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
        window.UID = new URLSearchParams(location.search).get('UID');
        window.SUBJECT = new URLSearchParams(location.search).get('subject');

        var link = document.createElement('link');
        link.rel = 'manifest';
        link.href = `manifest.php?UID=${window.UID}`;
        document.head.appendChild(link);

        (async ()=>{
            window.pageData = {section: "login"};
            if (window.UID) {
                window.pageData = await fetch(`manager.php?UID=${window.UID}&scope=loadPageData`, {
                    method: 'POST',
                    body: JSON.stringify({
                        subject: window.SUBJECT,
                        UID: window.UID,
                    })
                }).then(r=>r.json());
                if (window.pageData.status === false && window.pageData.message === "This profile does not exist!") {
                    await fetch(`manager.php?profile=default`);
                    location.reload();
                    //! Critical page error, return to default profile!
                }
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
                window.isAdmin = window.userData.admin;
                window.users = window.pageData.users;
                window.profiles = window.pageData.profiles;
                window.isCustomProfile = window.pageData.profiled;
                window.notifications = new PushNotifications(window.UID, "manager.php");
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

            }

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
                        fetch(`manager.php?UID=${window.UID}&scope=getAllData`).then(r=>r.json()).then(r=>{
                            window.adminDash = new AdminDashboard(null, {
                                fetchPrefix: "manager.php",
                                subjects: r,
                                updateCallback: (type, fullData, fileData, forceBlockRefresh = false)=>{
                                    console.log(fullData, fileData);
                                    fetch(`manager.php?UID=${window.UID}&scope=updateSettings&type=${type}`, {
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
                                    const res = await fetch(`manager.php?UID=${window.UID}&scope=getAllUsers`).then(r=>r.json());
                                    return (res.status === false) ? {} : res;
                                },
                                refreshProfiles: async()=>{
                                    const res = await fetch(`manager.php?UID=${window.UID}&scope=profileMGMT`, {
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