<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <link rel="shortcut icon" href="images/original-app-hd.png" type="image/x-icon">
    <link rel="icon" href="images/original-app-hd.png" type="image/x-icon">
    <title>Interrogazioni Programmate</title>
    <link rel="stylesheet" href="/assets/app.css?v=4">
</head>
<body>
    <nav class="flow-progress hided" id="flow-progress" aria-label="Avanzamento prenotazione">
        <span data-flow-step="1"><b>1</b> Materia</span>
        <i></i>
        <span data-flow-step="2"><b>2</b> Giorno</span>
        <i></i>
        <span data-flow-step="3"><b>3</b> Fatto</span>
    </nav>
    <div class="mainDiv hided" id="welcome">
        <h1>Benvenuto, crea il primo account admin per continuare!</h1>
        <p>Inserisci il tuo nome per iniziare!</p>
        <input type="text" name="name" id="name" placeholder="Nome Utente">
        <p>Il tuo UserID / Chiave di accesso (clicca per copiare):</p>
        <input type="text" name="uid" id="uid" style="cursor: pointer;" readonly onclick="navigator.clipboard.writeText(this.value);alert('UserID Copiato!');">
        <button onclick="window.actions.welcomeCreate(this);">Accedi</button>
    </div>
    <div class="mainDiv hided" id="login">
        <div class="eyebrow">Accesso classe</div>
        <h1>Ciao! Accedi per continuare.</h1>
        <p>Inserisci un ID oppure usa un link diretto se ne hai uno a disposizione.</p>
        <label for="login-uid">ID o link di accesso</label>
        <input type="text" name="UID" id="login-uid" class="login-uid-input" autocomplete="off" aria-describedby="login-error">
        <p class="form-error hided" id="login-error" role="alert">Inserisci un ID valido.</p>
        <div class="inline">
            <button class="button-secondary" onclick="CHANGESEC('create-class');">Crea una classe</button>
            <button onclick="window.actions.login(this);">Accedi</button>
        </div>
    </div>
    <div class="mainDiv hided" id="login-account-not-found">
        <div class="eyebrow eyebrow-danger">ID non riconosciuto</div>
        <h1>Non troviamo questo account</h1>
        <p>Controlla l’ID o incolla di nuovo il link completo. Se stai creando un nuovo gruppo, puoi aprire una classe separata.</p>
        <label for="retry-login-uid">ID o link di accesso</label>
        <input type="text" name="UID" id="retry-login-uid" class="login-uid-input" autocomplete="off" aria-invalid="true" aria-describedby="retry-login-error">
        <p class="form-error" id="retry-login-error" role="alert">L’ID inserito non appartiene a nessuna classe.</p>
        <div class="inline">
            <button class="button-secondary" onclick="CHANGESEC('create-class');">Crea una classe</button>
            <button onclick="window.actions.login(this);">Accedi</button>
        </div>
    </div>
    <div class="mainDiv hided" id="create-class">
        <div class="eyebrow">Nuovo spazio</div>
        <h1>Crea la tua classe</h1>
        <p>Avrai un archivio separato e diventerai il primo amministratore.</p>
        <label for="new-class-name">Nome della classe</label>
        <input type="text" id="new-class-name" maxlength="100" placeholder="Es. 5ª A">
        <label for="new-admin-name">Il tuo nome</label>
        <input type="text" id="new-admin-name" maxlength="100" placeholder="Nome e cognome">
        <div class="inline">
            <button class="button-secondary" onclick="CHANGESEC(window.UID ? 'login-account-not-found' : 'login');">Indietro</button>
            <button onclick="window.actions.createClass(this);">Crea e accedi</button>
        </div>
    </div>
    <div class="mainDiv hided" id="changeprofile">
        <h1>Scegli una classe</h1>
        <p>Questo codice appartiene a più classi. Scegli dove vuoi entrare.</p>
        <select name="profile" id="profile" class="id-select-profilelist" required>
            <option value="" selected disabled>Scegli una classe</option>
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
            <button type="button" id="changeProfileButton" onclick="CHANGESEC('changeprofile');">Cambia Classe</button>
            <button type="submit" onclick="window.actions.changeSubject(document.getElementById('subject').value);">Conferma</button>
        </div>
    </div>
    <div class="mainDiv hided" id="dayunavailable">
        <h1>Questa scelta non è disponibile!</h1>
        <div class="inline">
            <button class="button-secondary" onclick="window.actions.changeSubject('');">← Torna alle materie</button>
            <button onclick="window.actions.changeDay();">Cambia scelta</button>
        </div>
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
        <div class="inline">
            <button class="button-secondary" onclick="window.actions.changeSubject('');">← Torna alle materie</button>
            <button onclick="window.actions.changeDay();">Cambia opzione</button>
        </div>
    </div>
    <div class="mainDiv hided" id="nodays">
        <h1 id="nodays-title">Questa materia è bloccata o non ha possibili risposte!</h1>
        <p id="nodays-message"></p>
        <button id="changeSubjectButton" class="notInlineBtn" onclick="window.actions.changeSubject('');">← Torna alle materie</button>
    </div>
    <div class="mainDiv hided" id="priority-wait">
        <div class="eyebrow">Accesso prioritario in corso</div>
        <h1>La scelta aprirà tra poco</h1>
        <p>Gli utenti prioritari stanno scegliendo. Potrai rispondere appena avranno finito oppure allo scadere della finestra riservata.</p>
        <p class="status-pill" id="priority-window-time"></p>
        <button id="changeSubjectButton" class="notInlineBtn" onclick="window.actions.changeSubject('');">Cambia Materia</button>
    </div>
    <div class="mainDiv hided" id="schedule-day">
        <h1 id="javascript-change-schedule-text">Che giorno vuoi farti interrogare?</h1>
        <p>Non potrai cambiare la tua scelta.</p>
        <select name="day" id="day" class="id-select-daylist">
            <option value="" selected disabled>Scegli un opzione</option>
        </select>
        <div class="inline">
            <button id="changeSubjectButton" class="button-secondary" onclick="window.actions.changeSubject('');">← Materie</button>
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
    <script src="/assets/dash.js?v=4"></script>
    <script>
        const uid = ('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        }));
        document.querySelector('input[name=\'uid\']').value = uid;
    </script>
    <script>
        const PWA = window.matchMedia('(display-mode: standalone)').matches;
        window.UID = new URLSearchParams(location.search).get('UID') ?? localStorage["cachedUID"] ?? "";
        window.SUBJECT = new URLSearchParams(location.search).get('subject');
        window.CLASS = new URLSearchParams(location.search).get('class') ?? new URLSearchParams(location.search).get('profile') ?? localStorage["cachedClass"] ?? false;
        window.PROFILE = window.CLASS;

        function CHANGESEC(section) {
            if (!document.querySelector('.mainDiv#'+section)) return false;
            document.querySelectorAll('.mainDiv').forEach(e=>e.classList.add('hided'));
            document.querySelector('.mainDiv#'+section).classList.remove('hided');
            document.body.dataset.section = section;
            const flow = document.getElementById('flow-progress');
            const flowSections = new Set(['schedule-subject', 'schedule-day', 'priority-wait', 'dayunavailable', 'nodays', 'scheduleconfirmed', 'alreadyscheduled', 'alreadyscheduled-excluded']);
            flow.classList.toggle('hided', !flowSections.has(section));
            const step = section === 'schedule-subject' ? 1 : (['schedule-day', 'priority-wait', 'dayunavailable', 'nodays'].includes(section) ? 2 : 3);
            flow.dataset.currentStep = String(step);
            flow.querySelectorAll('[data-flow-step]').forEach(el=>el.classList.toggle('active', Number(el.dataset.flowStep) <= step));
        }

        window.renderPage = async (...arguments)=>{
            var UID = arguments[0] ?? window.UID;
            var subject = arguments[1] ?? window.SUBJECT;
            var classId = arguments[2] ?? window.CLASS;
            console.log(subject);

            window.firstRender = window.firstRender === undefined;
            window.prevUID = window.UID;
            window.UID = UID;
            window.SUBJECT = subject;
            window.CLASS = classId;
            window.PROFILE = classId;
            window.isAdmin = false;
            window.pageData = {section: "login"};
            if (window.UID) {
                try {
                    const pageResponse = await fetch(`manager.php?scope=loadPageData`, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            UID: window.UID,
                            subject: window.SUBJECT ?? "",
                            appLoadClass: (!!window.CLASS && window.CLASS != false) ? window.CLASS : undefined,
                            appLoadProfile: new URLSearchParams(location.search).get('profile') ?? undefined
                        })
                    });
                    window.pageData = await pageResponse.json();
                    if (!pageResponse.ok || window.pageData.status === false) {
                        throw new Error(window.pageData.message ?? 'Impossibile accedere.');
                    }
                } catch (error) {
                    console.error('Accesso non riuscito:', error);
                    window.pageData = {
                        status: false,
                        section: 'login-account-not-found',
                        message: error.message ?? 'Impossibile verificare questo ID.',
                        user: {subjectData: {day: false}}, users: [], profileList: [], profiles: [], subjectList: []
                    };
                }
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
                window.isCustomProfile = window.pageData.classId;
                window.CLASS = window.pageData.classId ?? window.CLASS;
                window.PROFILE = window.CLASS;
                if ((window.pageData.profileList ?? []).length > 0) localStorage["cachedUID"] = window.UID;
                if (window.CLASS) localStorage["cachedClass"] = window.CLASS;
                window.notifications = new PushNotifications(window.UID, "manager.php", window.CLASS);
                if (!!window.UID && window.UID.length > 0) localStorage["lastUID"] = window.UID;
                localStorage["lastPathName"] = location.pathname;

                document.querySelectorAll('.login-uid-input').forEach(input => input.value = window.UID ?? '');
                const retryError = document.querySelector('#retry-login-error');
                if (retryError && window.pageData.section === 'login-account-not-found') {
                    retryError.textContent = window.pageData.message && window.pageData.message !== 'Not Authorized!'
                        ? window.pageData.message
                        : 'L’ID inserito non appartiene a nessuna classe.';
                }

                document.querySelector('#changeProfileButton').classList.add("hided");
                document.querySelector('#changeProfileButton').parentNode.classList.remove("inline");
                document.querySelectorAll('#changeSubjectButton').forEach(e=>e.classList.add("hided"));
                document.querySelectorAll('#changeSubjectButton:not(.notInlineBtn)').forEach(e=>e.parentNode.classList.remove("inline"));
                document.querySelector('select.id-select-profilelist').querySelectorAll('option:not(option[selected])').forEach(e=>e.remove());
                document.querySelector('select.id-select-subjectlist').querySelectorAll('option:not(option[selected])').forEach(e=>e.remove());
                document.querySelector('select.id-select-daylist').querySelectorAll('option:not(option[selected])').forEach(e=>e.remove());
                for (var profile of window.pageData.profileList) {
                    document.querySelector('#changeProfileButton').classList.remove("hided");
                    document.querySelector('#changeProfileButton').parentNode.classList.add("inline");
                    document.querySelector('select.id-select-profilelist').add(new Option(`${profile.admin ? '(Admin) ' : ''}${profile.name}`, profile.id));
                }
                var subjcount = 0;
                for (var subject of window.pageData.subjectList) {
                    subjcount++;
                    if (subjcount > 1) {
                        document.querySelectorAll('#changeSubjectButton').forEach(e=>e.classList.remove("hided"));
                        document.querySelectorAll('#changeSubjectButton:not(.notInlineBtn)').forEach(e=>e.parentNode.classList.add("inline"));
                    }
                    document.querySelector('select.id-select-subjectlist').add(new Option(subject, subject));
                }
                // A locked or empty subject must always offer a way back, even when it is the only subject.
                document.querySelector('#nodays #changeSubjectButton').classList.remove('hided');
                for (var day in (window.pageData.subject?.days ?? {})) {
                    const dayData = window.pageData.subject.days[day];
                    const option = new Option(`(${dayData.availability === '-1/-1' ? '∞' : dayData.availability} Liberi) ${dayData.dayName == '-' ? '' : `${dayData.dayName} `}${day}`, day);
                    option.disabled = dayData.availability.split('/')[0] === '0';
                    document.querySelector('select.id-select-daylist').add(option);
                }

                document.querySelectorAll('#javascript-change-user-name').forEach(e=>e.textContent = window.userData.name ?? '');
                document.querySelectorAll('#javascript-change-schedule-data').forEach(e=>e.textContent = window.SUBJECT ?? '');
                document.querySelectorAll('#javascript-change-schedule-data-day').forEach(e=>e.textContent = window.userData.subjectData?.day ?? '');
                const currentType = window.pageData.subject?.type ?? "subject";
                document.querySelectorAll('#javascript-change-schedule-text').forEach(e=>e.innerHTML = currentType === "subject" ? "Che giorno vuoi farti interrogare?" : "Come vuoi rispondere?");
                document.querySelectorAll('#javascript-change-schedule-alreadychosen-text').forEach(e=>e.innerHTML = currentType === "subject" ? "Sarai interrogato in data: " : "Hai risposto con: ");
                document.querySelectorAll('#javascript-change-schedule-confirmed1-text').forEach(e=>e.innerHTML = currentType === "subject" ? "Ti sei prenotato a " : "Hai già risposto a ");
                document.querySelectorAll('#javascript-change-schedule-confirmed2-text').forEach(e=>e.innerHTML = currentType === "subject" ? " per il " : " con ");
                const opensAt = window.pageData.subject?.voting?.opensAt;
                document.querySelector('#priority-window-time').textContent = opensAt
                    ? `Accesso generale entro ${new Date(opensAt * 1000).toLocaleString('it-IT', {dateStyle: 'short', timeStyle: 'short'})}`
                    : '';
                const votingReason = window.pageData.subject?.voting?.reason;
                const noDaysTitle = document.querySelector('#nodays-title');
                const noDaysMessage = document.querySelector('#nodays-message');
                if (votingReason === 'scheduled' && opensAt) {
                    noDaysTitle.textContent = 'Apertura programmata';
                    noDaysMessage.textContent = `Potrai scegliere dal ${new Date(opensAt * 1000).toLocaleString('it-IT', {dateStyle: 'short', timeStyle: 'short'})}.`;
                } else if (votingReason === 'locked' || votingReason === 'paused') {
                    noDaysTitle.textContent = 'Le scelte sono temporaneamente bloccate';
                    noDaysMessage.textContent = 'La materia è visibile, ma un amministratore deve ancora aprire le prenotazioni.';
                } else {
                    noDaysTitle.textContent = 'Non ci sono scelte disponibili';
                    noDaysMessage.textContent = 'Riprova più tardi oppure scegli un’altra materia.';
                }

                if (window.CLASS && window.userData.name) await window.notifications.status().then(async r=>{
                    if (r != true) return;
                    const sw = await navigator.serviceWorker.getRegistration();
                    if (!sw) return;
                    if (!('pushManager' in sw)) return;
                    const sub = await sw.pushManager.getSubscription();
                    if (!sub) return;
                    const userSubscriptions = new Set();
                    for (var rsub of (window.userData.pushSubscriptions ?? [])) userSubscriptions.add(JSON.stringify(rsub));
                    if (!userSubscriptions.has(JSON.stringify(sub))) {
                        await fetch(`manager.php?${new URLSearchParams({scope: 'notifications', UID: window.UID, class: window.CLASS}).toString()}`, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({subscription: sub, action: 'subscribe'})
                        });
                    }
                    (navigator.serviceWorker.controller ?? sw.active)?.postMessage({pathname: location.pathname, uid: window.UID, classId: window.CLASS});
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
                    btnDiv.className = 'app-quick-actions';
                    btnDiv.innerHTML = "";

                const hasActiveClass = Boolean(window.CLASS && window.userData?.name && window.pageData?.className);
                btnDiv.classList.toggle('hided', !hasActiveClass);
                if (hasActiveClass) {
                    const classBadge = document.createElement('div');
                    classBadge.className = 'active-class-badge';
                    classBadge.title = `Classe attiva: ${window.pageData.className}`;
                    classBadge.innerHTML = `<span class="active-class-dot" aria-hidden="true"></span><span><small>Classe attiva</small><strong></strong></span>`;
                    classBadge.querySelector('strong').textContent = window.pageData.className;
                    btnDiv.appendChild(classBadge);
                }

                if (hasActiveClass && isAdmin) {
                    if (analizzaDati() != false) {
                        let btn = document.createElement("button");
                            btn.className = 'quick-action-button quick-action-secondary';
                            btn.title = 'Copia le prenotazioni della materia aperta';
                            btn.setAttribute('aria-label', 'Copia le prenotazioni');
                            btn.innerHTML = `<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-2v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h2Zm2 0h4a2 2 0 0 1 2 2v6h2V5h-8v2Zm4 2H6v10h8V9Z"/></svg><span>Copia</span>`;
                            btn.onclick = ()=>{
                                analizzaDati({
                                    clipboard: true, 
                                    copy: "prenotazioni", 
                                    log: false
                                });
                                btn.classList.add('is-complete');
                                btn.querySelector('span').textContent = 'Copiato';
                                setTimeout(()=>{
                                    btn.classList.remove('is-complete');
                                    btn.querySelector('span').textContent = 'Copia';
                                }, 2200);
                            };
                        btnDiv.appendChild(btn);
                    }
                }
                
                let btn = document.createElement("button");
                    btn.className = 'quick-action-button quick-action-primary';
                    btn.title = 'Apri i dati utente';
                    btn.setAttribute('aria-label', 'Apri i dati utente');
                    btn.innerHTML = `<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5.33 0-8 2.67-8 5v2h16v-2c0-2.33-2.67-5-8-5Z"/></svg><span>Dati utente</span>`;
                    btn.disabled = !hasActiveClass;
                    btn.onclick = ()=>{
                        if (!hasActiveClass) return;
                        window.dash = (!!(window.dash ?? {closed: true}).closed) ? new UserDashboard(null, {admin: isAdmin, onOpenAdminDash: ()=>{
                            fetch(`manager.php?UID=${window.UID}&class=${window.CLASS}&scope=getAllData`).then(r=>r.json()).then(r=>{
                                if (!Array.isArray(r)) throw new Error(r.message ?? 'Dati dashboard non disponibili.');
                                window.adminDash = new AdminDashboard(null, {
                                    fetchPrefix: "manager.php",
                                    subjects: r,
                                    updateCallback: async (type, fullData, fileData, forceBlockRefresh = false)=>{
                                        console.log(fullData, fileData);
                                        const r = await fetch(`manager.php?UID=${window.UID}&class=${window.CLASS}&scope=updateSettings&type=${type}`, {
                                            method: "POST",
                                            body: JSON.stringify([fileData])
                                        }).then(r=>r.json());
                                        console.log(r);
                                        if (r.status != true) {
                                            const error = new Error(r.message ?? "Impossibile completare l'azione!");
                                            error.reported = true;
                                            alert(error.message);
                                            throw error;
                                        }
                                        else {
                                            // alert("Dati aggiornati con successo!");
                                            !forceBlockRefresh && window.adminDash && window.adminDash.update({
                                                subjects: r.newData.subjects,
                                                users: r.newData.users,
                                                profiles: !!r.newData.profiles ? r.newData.profiles : undefined,
                                                className: window.pageData.className
                                            });
                                        }
                                        return r;
                                    },
                                    users: window.users,
                                    profiles: window.profiles,
                                    className: window.pageData.className,
                                    analysisFunction: analizzaDati,
                                    notificationClass: window.notifications,
                                    refreshUsers: async ()=>{
                                        const res = await fetch(`manager.php?UID=${window.UID}&class=${window.CLASS}&scope=getAllUsers`).then(r=>r.json());
                                        return (res.status === false) ? {} : res;
                                    },
                                    refreshProfiles: async()=>{
                                        const res = await fetch(`manager.php?UID=${window.UID}&class=${window.CLASS}&scope=profileMGMT`, {
                                            method: "POST",
                                            body: JSON.stringify({action: "listprofiles"})
                                        }).then(r=>r.json());
                                        return (res.status === false || !res.profiles) ? [] : res.profiles;
                                    },
                                    isCustomProfile: window.isCustomProfile
                                });
                            }).catch(error=>{
                                console.error(error);
                                alert('Impossibile aprire la dashboard. Riprova tra poco.');
                            });
                        }, className: window.pageData.className, ...window.userData}, window.notifications) : window.dash;
                    }
                    if (hasActiveClass) btnDiv.appendChild(btn);
                document.documentElement.appendChild(btnDiv);

                if (window.ManifestLink != null) window.ManifestLink.remove();
                if ((window.firstRender || window.prevUID != window.UID) && window.pageData.section != "login-account-not-found" && window.pageData.section != "login") {
                    window.ManifestLink = document.createElement('link');
                    window.ManifestLink.rel = 'manifest';
                    window.ManifestLink.href = `/assets/manifest.php?UID=${window.UID}&class=${window.CLASS ?? ''}`;
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
                createClass: function(elThis) {
                    const promise = new Promise(async (resolve)=>{
                        const className = document.getElementById('new-class-name').value.trim();
                        const adminName = document.getElementById('new-admin-name').value.trim();
                        if (!className || !adminName) {
                            alert('Inserisci il nome della classe e il tuo nome.');
                            return resolve(false);
                        }
                        elThis.disabled = true;
                        elThis.textContent = 'Creazione...';
                        const response = await fetch('manager.php?scope=createClass', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({className, adminName})
                        }).then(r=>r.json()).catch(error=>({status: false, message: error.toString()}));
                        elThis.disabled = false;
                        elThis.textContent = 'Crea e accedi';
                        if (!response.status) {
                            alert(response.message ?? 'Impossibile creare la classe.');
                            return resolve(false);
                        }
                        window.UID = response.UID;
                        window.CLASS = response.classId;
                        window.PROFILE = response.classId;
                        localStorage['cachedUID'] = response.UID;
                        localStorage['cachedClass'] = response.classId;
                        const link = `${location.origin}${location.pathname}?UID=${encodeURIComponent(response.UID)}&class=${encodeURIComponent(response.classId)}`;
                        await navigator.clipboard?.writeText(link).catch(()=>{});
                        alert(`Classe creata. Il link di accesso admin è stato copiato.\nConservalo: ${link}`);
                        resolve(await window.renderPage(response.UID, '', response.classId));
                    });
                    promise._actionName = 'createClass';
                    promise._actionArgs = [elThis];
                    window.actionManager[1](promise);
                    return promise;
                },
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
                        const input = elThis.closest('.mainDiv').querySelector('.login-uid-input');
                        const rawValue = input.value.trim();
                        const errorElement = elThis.closest('.mainDiv').querySelector('.form-error');
                        if (!rawValue) {
                            errorElement?.classList.remove('hided');
                            input.setAttribute('aria-invalid', 'true');
                            input.focus();
                            return r(false);
                        }
                        errorElement?.classList.add('hided');
                        input.removeAttribute('aria-invalid');
                        let loginCode = rawValue;
                        let selectedClass = false;
                        if (rawValue.includes('UID=')) {
                            const parameters = new URL(rawValue.includes('://') ? rawValue : `${location.origin}/${rawValue.replace(/^\/?/, '')}`).searchParams;
                            loginCode = parameters.get('UID') ?? '';
                            selectedClass = parameters.get('class') ?? parameters.get('profile') ?? false;
                        }
                        input.value = loginCode;
                        r(await window.renderPage(loginCode, '', selectedClass));
                    });
                    promise._actionName = 'login';
                    promise._actionArgs = [elThis];
                    window.actionManager[1](promise);
                    return promise;
                },
                changeUser: function(elThis) {
                    const promise = new Promise(async (r)=>{
                        localStorage.removeItem('cachedUID');
                        localStorage.removeItem('cachedClass');
                        window.CLASS = false;
                        window.PROFILE = false;
                        r(await window.renderPage(''));
                    });
                    promise._actionName = 'changeUser';
                    promise._actionArgs = [elThis];
                    window.actionManager[1](promise);
                    return promise;
                },
                changeProfile: function(profile = window.CLASS) {
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
                        const res = await fetch(`manager.php?UID=${window.UID}&class=${window.CLASS}&scope=schedule&subject=${encodeURIComponent(window.SUBJECT)}&day=${encodeURIComponent(day)}`).then(r=>r.json());
                        if (res.status === true) {
                            document.querySelectorAll('#javascript-change-schedule-data').forEach(e=>e.textContent = window.SUBJECT);
                            document.querySelectorAll('#javascript-change-schedule-data-day').forEach(e=>e.textContent = day);
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
        };
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
