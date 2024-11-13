<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="images/original-app-hd.png" type="image/x-icon">
    <link rel="icon" href="images/original-app-hd.png" type="image/x-icon">
    <title>Interrogazioni Programmate</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <div class="mainDiv hided" id="welcome">
        <h1>Benvenuto, crea il primo account admin per continuare!</h1>
        <p>Inserisci il tuo nome per iniziare!</p>
        <input type="text" name="name" id="name" placeholder="Nome Utente">
        <p>Il tuo UserID / Chiave di accesso (clicca per copiare):</p>
        <input type="text" name="uid" id="uid" style="cursor: pointer;" readonly onclick="navigator.clipboard.writeText(this.value);alert('UserID Copiato!');">
        <button onclick="window.actions.welcomeCreate(this);">Accedi</button>
    </div>
    <div class="mainDiv hided" id="login">
        <h1>Ciao! Accedi per continuare.</h1>
        <p>Inserisci un ID oppure usa un link diretto se ne hai uno a disposizione.</p>
        <input type="text" name="UID" id="UID">
        <button onclick="window.actions.login(this);">Accedi</button>
    </div>
    <div class="mainDiv hided" id="login-account-not-found">
        <h1>Il tuo account non esiste!</h1>
        <p>Inserisci un nuovo ID oppure usa un link diretto se ne hai uno a disposizione.</p>
        <input type="text" name="UID" id="UID">
        <button onclick="window.actions.login(this);">Accedi</button>
    </div>
    <div class="mainDiv hided" id="changeprofile">
        <h1>Scegli un profilo!</h1>
        <p>Scegli un profilo in cui sei registrato per continuare!</p>
        <select name="profile" id="profile" class="id-select-profilelist" required>
            <option value="default" selected>Scegli un profilo (Profilo Default)</option>
        </select>
        <div class="inline">
            <button type="button" onclick="window.actions.changeUser(this);">Cambia Utente</button>
            <button type="submit" onclick="window.actions.changeProfile(document.getElementById('profile').value);">Accedi</button>
        </div>
    </div>
    <div class="mainDiv hided" id="schedule-subject">
        <h1>Ciao, <span class="dummy" id="javascript-change-user-name">$USERNAME</span>!</h1>
        <p>Per quale materia vuoi prenotarti?</p>
        <select name="subject" id="subject" class="id-select-subjectlist" required>
            <option selected disabled>Scegli una materia</option>
        </select>
        <div class="inline">
            <button type="button" id="changeProfileButton" onclick="CHANGESEC('changeprofile');">Cambia Profilo</button>
            <button type="submit" onclick="window.actions.changeSubject(document.getElementById('subject').value);">Conferma</button>
        </div>
    </div>
    <div class="mainDiv hided" id="dayunavailable">
        <h1>Questa scelta non è disponibile!</h1>
        <button onclick="window.actions.changeDay();">Cambia scelta</button>
    </div>
    <div class="mainDiv hided" id="alreadyscheduled">
        <h1>Hai già scelto la tua opzione!</h1>
        <p>Solo un admin può cambiare la tua scelta.<br><span class="dummy" id="javascript-change-schedule-alreadychosen-text">Sarai interrogato in data: </span><span class="dummy" id="javascript-change-schedule-data-day">$SUBJECTDATE</span></p>
        <button id="changeSubjectButton" class="notInlineBtn" onclick="window.actions.changeSubject('');">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="alreadyscheduled-excluded">
        <h1>Sei stato escluso da questa risposta!</h1>
        <p>Se è un errore, contatta un admin, altrimenti non dovrai preoccuparti di rispondere!</p>
        <button id="changeSubjectButton" class="notInlineBtn" onclick="window.actions.changeSubject('');">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="scheduleconfirmed">
        <h1>Hai scelto la tua opzione!</h1>
        <p><span class="dummy" id="javascript-change-schedule-confirmed1-text">Ti sei prenotato a </span><span class="dummy" id="javascript-change-schedule-data">$SUBJECTNAME</span><span class="dummy" id="javascript-change-schedule-confirmed2-text"> per il </span><span class="dummy" id="javascript-change-schedule-data-day">$SUBJECTDATE</span>!</p>
        <button id="changeSubjectButton" class="notInlineBtn" onclick="window.actions.changeSubject('');">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="schedulefailed">
        <h1>Whoops! :( </h1>
        <p>C'è stato un problema mentre provavi a rispondere, per favore riprova o cambia la tua scelta.</p>
        <button onclick="window.actions.changeDay();">Cambia opzione</button>
    </div>
    <div class="mainDiv hided" id="nodays">
        <h1>Questa materia è bloccata o non ha possibili risposte!</h1>
        <button id="changeSubjectButton" class="notInlineBtn" onclick="window.actions.changeSubject('');">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="schedule-day">
        <h1 id="javascript-change-schedule-text">Che giorno vuoi farti interrogare?</h1>
        <p>Non potrai cambiare la tua scelta.</p>
        <select name="day" id="day" class="id-select-daylist">
            <option value="" selected disabled>Scegli un opzione</option>
        </select>
        <div class="inline">
            <button id="changeSubjectButton" onclick="window.actions.changeSubject('');">Cambia Materia</button>
            <button onclick="window.actions.scheduleDay(document.getElementById('day').value)">Conferma</button>
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
    <script src="/assets/dash.js"></script>
    <script>
        const uid = ('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        }));
        document.querySelector('input[name=\'uid\']').value = uid;
    </script>
    <script>
        const PWA = window.matchMedia('(display-mode: standalone)').matches;
        window.UID = new URLSearchParams(location.search).get('UID') ?? localStorage["cachedUID"];
        window.SUBJECT = new URLSearchParams(location.search).get('subject');
        window.PROFILE = new URLSearchParams(location.search).get('profile') ?? false;

        function CHANGESEC(section) {
            if (!document.querySelector('.mainDiv#'+section)) return false;
            document.querySelectorAll('.mainDiv').forEach(e=>e.classList.add('hided'));
            document.querySelector('.mainDiv#'+section).classList.remove('hided');
        }

        window.renderPage = (async (UID = window.UID, subject = window.SUBJECT, profile = window.PROFILE)=>{
            window.firstRender = window.firstRender === undefined;
            window.prevUID = window.UID;
            window.UID = UID;
            window.SUBJECT = subject;
            window.PROFILE = profile;
            window.isAdmin = false;
            localStorage["cachedUID"] = window.UID;

            window.pageData = {section: "login"};
            if (window.UID) {
                window.pageData = await fetch(`manager.php?scope=loadPageData`, {
                    method: 'POST',
                    body: JSON.stringify({
                        UID: window.UID,
                        subject: window.SUBJECT ?? "",
                        appLoadProfile: (!!window.PROFILE && window.PROFILE != false) ? window.PROFILE : undefined
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
                window.PROFILE = window.isCustomProfile;
                window.notifications = new PushNotifications(window.UID, "manager.php");
                if (!!window.UID && window.UID.length > 0) localStorage["lastUID"] = window.UID;
                localStorage["lastPathName"] = location.pathname;

                document.querySelector('#changeProfileButton').classList.add("hided");
                document.querySelector('#changeProfileButton').parentNode.classList.remove("inline");
                document.querySelectorAll('#changeSubjectButton').forEach(e=>e.classList.add("hided"));
                document.querySelectorAll('#changeSubjectButton:not(.notInlineBtn)').forEach(e=>e.parentNode.classList.remove("inline"));
                document.querySelector('select.id-select-profilelist').querySelectorAll('option:not(option[selected])').forEach(e=>e.remove());
                document.querySelector('select.id-select-subjectlist').querySelectorAll('option:not(option[selected])').forEach(e=>e.remove());
                document.querySelector('select.id-select-daylist').querySelectorAll('option:not(option[selected])').forEach(e=>e.remove());
                for (var profile in window.pageData.profileList) {
                    document.querySelector('#changeProfileButton').classList.remove("hided");
                    document.querySelector('#changeProfileButton').parentNode.classList.add("inline");
                    document.querySelector('select.id-select-profilelist').innerHTML += `<option value="${window.pageData.profileList[profile].name}">${profile.admin ? `<b>Admin</b> ` : ``}${window.pageData.profileList[profile].name}</option>`;
                }
                var subjcount = 0;
                for (var subject of window.pageData.subjectList) {
                    subjcount++;
                    if (subjcount > 1) {
                        document.querySelectorAll('#changeSubjectButton').forEach(e=>e.classList.remove("hided"));
                        document.querySelectorAll('#changeSubjectButton:not(.notInlineBtn)').forEach(e=>e.parentNode.classList.add("inline"));
                    }
                    document.querySelector('select.id-select-subjectlist').innerHTML += `<option value="${subject}">${subject}</option>`;
                }
                for (var day in window.pageData.subject.days) {
                    document.querySelector('select.id-select-daylist').innerHTML += `<option value="${day}" ${window.pageData.subject.days[day].availability.split('/')[0] === "0" ? "disabled" : ""}>(${window.pageData.subject.days[day].availability === "-1/-1" ? "∞" : window.pageData.subject.days[day].availability} Liberi) ${window.pageData.subject.days[day].dayName == "-" ? "" : `${window.pageData.subject.days[day].dayName} `}${day}</option>`;
                }

                document.querySelectorAll('#javascript-change-user-name').forEach(e=>e.innerHTML = window.userData.name);
                document.querySelectorAll('#javascript-change-schedule-data').forEach(e=>e.innerHTML = window.SUBJECT);
                document.querySelectorAll('#javascript-change-schedule-data-day').forEach(e=>e.innerHTML = window.userData.subjectData.day);
                document.querySelectorAll('#javascript-change-schedule-text').forEach(e=>e.innerHTML = window.pageData.subject.type === "subject" ? "Che giorno vuoi farti interrogare?" : "Come vuoi rispondere?");
                document.querySelectorAll('#javascript-change-schedule-alreadychosen-text').forEach(e=>e.innerHTML = window.pageData.subject.type === "subject" ? "Sarai interrogato in data: " : "Hai risposto con: ");
                document.querySelectorAll('#javascript-change-schedule-confirmed1-text').forEach(e=>e.innerHTML = window.pageData.subject.type === "subject" ? "Ti sei prenotato a " : "Hai già risposto a ");
                document.querySelectorAll('#javascript-change-schedule-confirmed2-text').forEach(e=>e.innerHTML = window.pageData.subject.type === "subject" ? " per il " : " con ");

                await window.notifications.status().then(async r=>{
                    if (r != true) return;
                    const sw = await navigator.serviceWorker.getRegistration();
                    if (!window.userData.pushSubscriptions || !sw) return window.notifications.unsubscribe();
                    if (!'pushManager' in sw) return;
                    const sub = await sw.pushManager.getSubscription();
                    const userSubscriptions = new Set();
                    for (var rsub of window.userData.pushSubscriptions) userSubscriptions.add(JSON.stringify(rsub));
                    if (!userSubscriptions.has(JSON.stringify(sub))) return window.notifications.unsubscribe(false);
                    window.notifications.update();
                    navigator.serviceWorker.addEventListener("message", (e)=>console.log(JSON.parse(e.data)));
                })

                function analizzaDati(options = {}) {
                    const defaultOptions = {
                        clipboard: false,
                        copy: "json",
                        log: true,
                        data: undefined,
                        subject: undefined,
                        users: undefined,
                        minimal: false,
                    };
                    options = {...defaultOptions, ...options};
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
                            header: options.minimal 
                                ? `${datiMateria.days[data].dayName == "-" ? "" : `${datiMateria.days[data].dayName} `}${data}`
                                : `[${datiMateria.days[data].availability}] ${datiMateria.days[data].dayName == "-" ? "" : `${datiMateria.days[data].dayName} `}${data}`,
                            answers: []
                        }
                    }

                    for (var utente in datiMateria.answers) {
                        let date = datiMateria.answers[utente].date;
                        listaPrenotazioni[datiMateria.answers[utente].date] ??= {
                            header: options.minimal 
                                ? `${datiMateria.answers[utente].name ?? new Date(`${date.split("-")[1]}-${date.split("-")[0]}-${date.split("-")[2]}`).toLocaleString("it-IT", {weekday: "long"})} ${datiMateria.answers[utente].date}`
                                : `[-] ${datiMateria.answers[utente].name ?? new Date(`${date.split("-")[1]}-${date.split("-")[0]}-${date.split("-")[2]}`).toLocaleString("it-IT", {weekday: "long"})} ${datiMateria.answers[utente].date}`,
                            answers: []
                        };
                        if (!utenti[utente].watcherAcc || date != "Esclusi") listaPrenotazioni[datiMateria.answers[utente].date].answers.push(
                            options.minimal
                                ? `${utenti[utente].name}`
                                : `[${datiMateria.answers[utente].answerNumber}] ${utenti[utente].name}`
                        );
                    }

                    var listaPrenotazioniText = options.minimal ? `${materia}: ---\n` : `[${materia}] Prenotati (Numero Risposta, Nome): ---\n`;
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

                window.btnDiv = window.btnDiv ?? document.createElement("div");
                    btnDiv.style.display = "flex";
                    btnDiv.style.position = "fixed";
                    btnDiv.style.bottom = "10px";
                    btnDiv.style.right = "10px";
                    btnDiv.style.gap = "2.5px";
                    btnDiv.innerHTML = "";

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
                                    updateCallback: async (type, fullData, fileData, forceBlockRefresh = false)=>{
                                        console.log(fullData, fileData);
                                        const r = await fetch(`manager.php?UID=${window.UID}&scope=updateSettings&type=${type}`, {
                                            method: "POST",
                                            body: JSON.stringify([fileData])
                                        }).then(r=>r.json());
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

                if (window.ManifestLink != null) window.ManifestLink.remove();
                if ((window.firstRender || window.prevUID != window.UID) && window.pageData.section != "login-account-not-found" && window.pageData.section != "login") {
                    window.ManifestLink = document.createElement('link');
                    window.ManifestLink.rel = 'manifest';
                    window.ManifestLink.href = `/assets/manifest.php?UID=${window.UID}`;
                    document.head.appendChild(window.ManifestLink);
                }
            }

            window.actionQueue = {
                queue: [],
                isProcessing: false,
                immediateActions: new Set([]) ?? new Set([
                    'changeProfile',
                    'changeSubject',
                    'changeDay'
                ]),

                // Add action to queue
                add(actionName, args) {
                    const action = { actionName, args };
                    this.queue.push(action);
                    
                    // Store queue in localStorage
                    this.saveQueue();
                    
                    // If online, process queue
                    if (navigator.onLine) {
                        this.processQueue();
                    }
                },

                // Save queue to localStorage
                saveQueue() {
                    localStorage.setItem('actionQueue', JSON.stringify(this.queue));
                },

                // Load queue from localStorage
                loadQueue() {
                    const savedQueue = localStorage.getItem('actionQueue');
                    if (savedQueue) {
                        this.queue = JSON.parse(savedQueue);
                    }
                },

                // Process all queued actions
                async processQueue() {
                    if (this.isProcessing || !navigator.onLine) return;
                    
                    this.isProcessing = true;
                    
                    while (this.queue.length > 0) {
                        const action = this.queue[0];
                        try {
                            await window.actions[action.actionName](...action.args);
                            // Action successful, remove from queue
                            this.queue.shift();
                            this.saveQueue();
                        } catch (error) {
                            console.error('Failed to process action:', error);
                            // Stop processing on error
                            break;
                        }
                    }
                    
                    this.isProcessing = false;
                }
            };
            
            window.actionManager = [async (promise = false)=>{
                if (!window.navigator.onLine) return window.actionManager[0].push(promise);
                // Logic to execute all promises
            }, function(promise) {
                if (!navigator.onLine) {
                    const actionName = promise._actionName; // We'll set this below
                    const args = promise._actionArgs;  // We'll set this below
                    
                    if (window.actionQueue.immediateActions.has(actionName)) {
                        return promise;
                    }

                    window.actionQueue.add(actionName, args);
                    return Promise.resolve({ status: true, offline: true, message: 'Action queued' });
                }
                return promise;
            }];

            // Initialize when page loads
            window.addEventListener('load', () => {
                // Register service worker
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.register('/push-service-worker.js')
                    .then(registration => console.log('ServiceWorker registered', registration))
                    .catch(error => console.error('ServiceWorker registration failed:', error));
                }
                
                // Load saved queue
                window.actionQueue.loadQueue();
            });

            // Handle online/offline events
            window.addEventListener('online', () => {
                console.log('Back online');
                window.actionQueue.processQueue();
            });

            window.addEventListener('offline', () => {
                console.log('Gone offline');
            });

            window.actions = {
                welcomeCreate: function(elThis) {
                    const promise = new Promise(async (re)=>{
                        const body = {};
                        body[elThis.parentNode.querySelector('input[name=\'uid\']').value] = {
                            name: elThis.parentNode.querySelector('input[name=\'name\']').value,
                            admin: true,
                            answers: {}
                        };
                        const r = await fetch(`manager.php?scope=updateSettings&type=users`, {method: 'POST', body: JSON.stringify([body])}).then(r=>r.json());
                        if (!r.status) return r(alert('Impossibile completare l\'azione!'));
                        re(await window.renderPage(elThis.parentNode.querySelector('input[name=\'uid\']').value));
                    });
                    promise._actionName = 'welcomeCreate';
                    promise._actionArgs = [elThis];
                    window.actionManager[1](promise);
                    return promise;
                },
                login: function(elThis) {
                    const promise = new Promise(async (r)=>{
                        elThis.parentNode.querySelector('#UID').value =
                            elThis.parentNode.querySelector('#UID').value.indexOf('UID=') != -1
                                ? (new URLSearchParams(`?${elThis.parentNode.querySelector('#UID').value.split('?', 2)[1]}`)).get('UID')
                                : elThis.parentNode.querySelector('#UID').value;
                        r(await window.renderPage(elThis.parentNode.querySelector('#UID').value));
                    });
                    promise._actionName = 'login';
                    promise._actionArgs = [elThis];
                    window.actionManager[1](promise);
                    return promise;
                },
                changeUser: function(elThis) {
                    const promise = new Promise(async (r)=>{
                        r(await window.renderPage(''));
                    });
                    promise._actionName = 'changeUser';
                    promise._actionArgs = [elThis];
                    window.actionManager[1](promise);
                    return promise;
                },
                changeProfile: function(profile = window.PROFILE) {
                    const promise = new Promise(async (r)=>{
                        r(await window.renderPage(undefined, undefined, profile));
                    });
                    promise._actionName = 'changeProfile';
                    promise._actionArgs = [profile];
                    window.actionManager[1](promise);
                    return promise;
                },
                changeSubject: function(subject = window.SUBJECT) {
                    const promise = new Promise(async (r)=>{
                        r(await window.renderPage(undefined, subject));
                    });
                    promise._actionName = 'changeSubject';
                    promise._actionArgs = [subject];
                    window.actionManager[1](promise);
                    return promise;
                },
                changeDay: function() {
                    const promise = new Promise(async (r)=>{
                        r(await window.renderPage());
                    });
                    promise._actionName = 'changeDay';
                    promise._actionArgs = [];
                    window.actionManager[1](promise);
                    return promise;
                },
                scheduleDay: function(day) {
                    const promise = new Promise(async (r)=>{
                        const res = await fetch(`manager.php?UID=${window.UID}&scope=schedule&subject=${window.SUBJECT}&day=${day}`).then(r=>r.json());
                        if (res.status === true) {
                            document.querySelectorAll('#javascript-change-schedule-data').forEach(e=>e.innerHTML = window.SUBJECT);
                            document.querySelectorAll('#javascript-change-schedule-data-day').forEach(e=>e.innerHTML = day);
                            return r(CHANGESEC("scheduleconfirmed"));
                        }
                        if (res.message === "Invalid Day!") return r(CHANGESEC("dayunavailable"));
                        return r(CHANGESEC("schedulefailed"));
                    });
                    promise._actionName = 'scheduleDay';
                    promise._actionArgs = [day];
                    window.actionManager[1](promise);
                    return promise;
                }
            };

            CHANGESEC(window.pageData.section);
        });
        window.renderPage();

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