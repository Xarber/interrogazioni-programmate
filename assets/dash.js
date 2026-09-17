class PushNotifications {
    /**
     * Initializes a PushNotifications object.
     * @param {string} identifier - Unique identifier, e.g. a username.
     */
    constructor(identifier, fetchPrefix, classId) {
        this.id = identifier;
        this.fetchPrefix = fetchPrefix ?? "";
        this.classId = classId ?? "";
    }

    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/\-/g, '+')
            .replace(/_/g, '/');
    
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
    
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    async clearCache() {
        // Clear cache
        return new Promise(async (resolve, reject) => {
            caches.keys().then(cacheNames => Promise.all(cacheNames.map(async cacheName => {
                caches.delete(cacheName).then(()=>{
                    console.log(`Cache ${cacheName} deleted.`);
                });
            }))).then(resolve);
        });
    }

    async subscribe() {
        // Check if service worker and push messaging is supported
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.error('Push notifications not supported');
            return {status: false, userError: true, message: "Unsupported Device!"};
        }
    
        try {
            // Register service worker
            const registration = await navigator.serviceWorker.getRegistration() ?? await navigator.serviceWorker.register('/push-service-worker.js');
            const readyRegistration = await navigator.serviceWorker.ready;
            (navigator.serviceWorker.controller ?? readyRegistration.active)?.postMessage({
                pathname: window.location.pathname,
                uid: this.id,
                classId: this.classId
            });
            console.log('Service Worker registered');
    
            // Request notification permission
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return {status: false, userError: true, message: permission === "default" ? "Notification Permission Dialog Dismissed!" : "Notification Permission Denied!"};
                throw new Error('Notification permission denied');
            }
    
            // Get push subscription
            if (!('pushManager' in registration)) {
                console.error('Push notifications not supported');
                return {status: false, userError: true, message: "Unsupported Device!"};
            }
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array((await fetch(`${this.fetchPrefix}?${new URLSearchParams({scope: "notifications", UID: this.id, class: this.classId}).toString()}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({action: "VAPIDkey"})
                }).then(r=>r.json())).publicKey)
            });
            
            // Send subscription to your server
            const response = await fetch(`${this.fetchPrefix}?${new URLSearchParams({scope: "notifications", UID: this.id, class: this.classId}).toString()}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({subscription, action: "subscribe"})
            }).then(r=>r.json());
            return {...response, subscription};
        } catch (error) {
            return {status: false, message: error.toString(), localError: true};
        }
    }

    async unsubscribe(sendRequestToServer = true) {
        if (!(await this.status())) return {status: true, message: null};
        /*
        const subscription = (await (await navigator.serviceWorker.ready).pushManager.getSubscription());
        */
        return new Promise((resolve) => {
            navigator.serviceWorker.ready.then(d=>{
                if (!('pushManager' in d)) return resolve({status: true, message: null});
                d.pushManager.getSubscription().then(async sub => {
                    const subscription = sub;
                    if (!subscription) return resolve({status: true, message: null});
    
                    const response = (!!sendRequestToServer) ? await fetch(`${this.fetchPrefix}?${new URLSearchParams({scope: "notifications", UID: this.id, class: this.classId}).toString()}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({subscription, action: "unsubscribe"})
                    }).then(r=>r.json()).catch(e=>{return {status: false, message: e.toString()}}) : {status: true, message: null};
                    await subscription.unsubscribe();
                    resolve(response);
                }).catch(error => resolve({status: false, message: error.toString()}));
            }).catch(error => resolve({status: false, message: error.toString()}));
        });
    }

    async update() {
        navigator.serviceWorker.getRegistrations().then(registrations => {
            for (const registration of registrations) {
                registration.update();
            } 
        });
    }

    async requestSend(users, data) {
        const response = await fetch(`${this.fetchPrefix}?${new URLSearchParams({scope: "notifications", UID: this.id, class: this.classId}).toString()}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({...data, users, action: "sendNotifications"})
        }).then(r=>r.json()).catch(e=>{return {status: false, message: e.toString()}});

        return response;
    }

    async status() {
        return new Promise(async (resolve, reject) => {
            navigator.serviceWorker.getRegistrations().then(r=>{
                if (r.length === 0) return resolve(false);
                /*
                    const subscription = (await (await navigator.serviceWorker.ready).pushManager.getSubscription());
                    return !!subscription;
                */
                navigator.serviceWorker.ready.then(d=>{
                    if (!('pushManager' in d)) return resolve(false);
                    d.pushManager.getSubscription().then(sub => {
                        resolve(!!sub);
                    });
                })
            });

        })
    }

    available() {
        return (('serviceWorker' in navigator) && ('PushManager' in window));
    }
}

class UserDashboard {
    /**
     * UserDashboard constructor.
     * @param {HTMLElement} [containerDiv=document.documentElement] - The container div where the dashboard will be rendered
     * @param {Object} userData - The user data containing name, answers, and admin status
     */
    constructor(containerDiv = null, userData, notificationClass) {
        this.userData = userData;
        this.container = containerDiv || document.documentElement;
        this.notificationClass = notificationClass;
        this.dashboard = null;
        this.render();
    }
  
    render() {
        this.dashboard = this.dashboard || document.createElement('div');
        this.dashboard.className = 'user-dashboard';
        this.dashboard.innerHTML = `
            <div class="user-dashboard-content">
                <div class="user-dashboard-header">
                    <div>
                        <span class="dashboard-kicker">Dati utente</span>
                        <h2>${this.userData.name}</h2>
                        ${this.userData.className ? `<span class="dashboard-class-name" title="Classe attiva">${this.userData.className}</span>` : ''}
                    </div>
                    <button class="user-close-btn dashboard-icon-btn" title="Chiudi" aria-label="Chiudi">&times;</button>
                </div>
                <h3>Prenotazioni</h3>
                <div class="user-dashboard-appointments">
                    ${this.renderAppointments()}
                </div>
                <div class="user-dashboard-actions">
                    ${this.notificationClass ? `<button onclick="" id="dash-notifications-btn" class="dashboard-action-btn" ${this.notificationClass.available() ? "" : 'style="display: none"'} title="Gestisci notifiche"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6v-5a7 7 0 0 0-5-6.71V3a2 2 0 1 0-4 0v1.29A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg><span>Notifiche</span></button>` : ""}
                    <button class="dashboard-action-btn" onclick="window.open(${/Android/i.test(navigator.userAgent)
                        ? `\`manager.php?scope=redirectToCalendar&UID=\${window.UID}&class=\${window.CLASS}\`, '_blank'`
                        : `\`webcal://\${location.hostname}/manager.php?scope=syncICal&UID=\${window.UID}&class=\${window.CLASS}\``
                    })" id="dash-calendar-btn" title="Aggiungi al calendario"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3V2Zm13 8H4v10h16V10ZM4 8h16V6h-3v1h-2V6H9v1H7V6H4v2Z"/></svg><span>Calendario</span></button>
                    <button id="dash-switch-user-btn" class="dashboard-action-btn" title="Cambia utente"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16 13c2.67 0 8 1.34 8 4v3h-2v-3c0-.74-3.09-2-6-2-.82 0-1.66.1-2.43.26a8.1 8.1 0 0 0-1.67-1.64A14.4 14.4 0 0 1 16 13ZM8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.42 0-8 1.79-8 4v2h16v-2c0-2.21-3.58-4-8-4Zm8-3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg><span>Cambia utente</span></button>
                    ${this.userData.admin ? '<button onclick="" id="dash-admin-view-btn" class="dashboard-action-btn dashboard-action-primary" title="Apri dashboard amministratore"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 3h8v8H3V3Zm10 0h8v5h-8V3ZM3 13h8v8H3v-8Zm10-3h8v11h-8V10Z"/></svg><span>Dashboard</span></button>' : ""}
                </div>
            </div>
        `;

        this.applyStyles();
        this._listenersAttached = false;
        this.attachEventListeners();
        if (!this.appended) this.container.appendChild(this.dashboard);
        this.appended = true;
        if (this.dashboard.querySelector("button#dash-admin-view-btn")) this.dashboard.querySelector("button#dash-admin-view-btn").onclick = this.userData.onOpenAdminDash;
        this.dashboard.querySelector("button#dash-switch-user-btn").onclick = this.userData.onSwitchUser;
        (async ()=>{
            if (!this.notificationClass) return;
            const status = await this.notificationClass.status();
            const notificationButton = this.dashboard.querySelector("button#dash-notifications-btn");
            if (!notificationButton) return;
            notificationButton.querySelector('span').textContent = status ? "Disattiva notifiche" : "Attiva notifiche";
            notificationButton.onclick = async ()=>{
                notificationButton.disabled = true;
                notificationButton.querySelector('span').textContent = "Attendi...";
                const response = !status ? await this.notificationClass.subscribe() : await this.notificationClass.unsubscribe();
                if (!response.status) alert(!!response.userError ? response.message : `Impossibile attivare le notifiche! Ricarica la pagina e riprova.`);
                this.render();
            };
        })();
        this.closed = false;
    }
  
    renderAppointments() {
        const output = Object.entries(this.userData.answers)
        .map(([subject, dates]) => {
            const dateOut = dates.map(date => `<li>${this.formatDate(date)}</li>`).join('');
            return dateOut.length > 0 ? `
                <div class="user-appointment-group">
                    <h4>${subject}</h4>
                    <ul>
                        ${dateOut /* dateOut.length > 0 ? dateOut : `<li>Nessun interrogazione per questa materia!</li>` */}
                    </ul>
                </div>
            ` : "";
        })
        .join('');
        return output.length > 0 ? output : `
            <div class="user-appointment-group">
                <h4>Nessuna prenotazione!</h4>
            </div>
        `;
    }
  
    formatDate(dateString) {
        const [day, month, year] = dateString.split('-');
        return new Date(year, month - 1, day).toLocaleDateString('it-IT', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
  
    applyStyles() {
        const style = document.createElement('style');
        document.getElementById('user-dashboard-styles')?.remove();
        style.id = 'user-dashboard-styles';
        style.textContent = `
            .user-dashboard {
                position: fixed;
                inset: 0;
                z-index: 1000;
                display: grid;
                place-items: center;
                padding: 24px;
                color: var(--text, #fffaf2);
                background: rgba(8, 8, 8, .66);
                backdrop-filter: blur(18px);
                -webkit-backdrop-filter: blur(18px);
            }
            .user-dashboard-content {
                position: relative;
                width: min(720px, 100%);
                max-height: min(780px, calc(100dvh - 48px));
                display: flex;
                flex-direction: column;
                overflow: hidden;
                padding: clamp(20px, 4vw, 34px);
                border: 1px solid rgba(255,255,255,.12);
                border-radius: 24px;
                background: linear-gradient(145deg, rgba(48, 40, 32, .97), rgba(24, 23, 22, .98));
                box-shadow: 0 28px 90px rgba(0, 0, 0, .52);
            }
            .user-dashboard-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 16px;
                margin-bottom: 24px;
            }
            .user-dashboard-header h2 {
                margin: 3px 0 6px;
                color: var(--text, #fffaf2);
                font-size: clamp(1.55rem, 5vw, 2.2rem);
                letter-spacing: -.025em;
            }
            .dashboard-kicker {
                color: #e4ad68;
                font-size: .7rem;
                font-weight: 800;
                letter-spacing: .13em;
                text-transform: uppercase;
            }
            .dashboard-class-name {
                display: inline-flex;
                align-items: center;
                max-width: 100%;
                padding: 5px 9px;
                overflow: hidden;
                color: #d9d1c7;
                border: 1px solid rgba(255,255,255,.11);
                border-radius: 999px;
                background: rgba(255,255,255,.055);
                font-size: .78rem;
                font-weight: 700;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .user-close-btn {
                position: static;
                min-width: 42px;
                min-height: 42px;
                margin: 0;
                padding: 0;
                color: #d9d1c7;
                border: 1px solid rgba(255,255,255,.11);
                border-radius: 13px;
                background: rgba(255,255,255,.055);
                box-shadow: none;
                font-size: 25px;
            }
            .user-dashboard-content h3 {
                margin: 0 0 12px;
                color: #efe8df;
                font-size: .95rem;
            }
            .user-dashboard-appointments {
                flex-grow: 1;
                min-height: 130px;
                padding: 14px;
                overflow-y: auto;
                border: 1px solid rgba(255,255,255,.1);
                border-radius: 17px;
                background: rgba(7, 7, 7, .2);
            }
            .user-appointment-group {
                margin: 0 0 14px;
                padding: 13px 14px;
                border: 1px solid rgba(255,255,255,.08);
                border-radius: 13px;
                background: rgba(255,255,255,.035);
            }
            .user-appointment-group:last-child { margin-bottom: 0; }
            .user-appointment-group h4 {
                margin: 0 0 8px;
                color: #f1e8dd;
                font-size: .95rem;
            }
            .user-appointment-group ul {
                margin: 0;
                list-style-type: none;
                padding: 0;
            }
            .user-appointment-group li {
                margin-bottom: 5px;
                color: #c9c0b5;
                font-size: .86rem;
            }
            .user-dashboard-actions {
                display: grid;
                grid-template-columns: repeat(${this.userData.admin ? '4' : '3'}, minmax(0, 1fr));
                gap: 9px;
                margin-top: 14px;
            }
            .dashboard-action-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-width: 0;
                min-height: 48px;
                margin: 0;
                padding: 10px 12px;
                color: #eee5da;
                border: 1px solid rgba(255,255,255,.11);
                border-radius: 14px;
                background: rgba(255,255,255,.06);
                box-shadow: none;
                font-size: .83rem;
            }
            .dashboard-action-btn svg { width: 19px; height: 19px; flex: 0 0 auto; fill: currentColor; }
            .dashboard-action-primary { color: #23170b; border-color: transparent; background: linear-gradient(135deg, #e3ad68, #c98a3b); }
            .dashboard-action-btn:disabled { opacity: .5; }
            @media (max-width: 680px) {
                .user-dashboard { place-items: stretch; padding: 0; }
                .user-dashboard-content {
                    width: 100%;
                    max-height: 100dvh;
                    min-height: 100dvh;
                    padding: max(18px, env(safe-area-inset-top)) 16px max(16px, env(safe-area-inset-bottom));
                    border: 0;
                    border-radius: 0;
                }
                .user-dashboard-header { margin-bottom: 18px; }
                .user-dashboard-appointments { min-height: 0; }
                .user-dashboard-actions { grid-template-columns: 1fr; }
                .dashboard-action-btn { justify-content: flex-start; padding-inline: 16px; }
            }
        `;
        document.head.appendChild(style);
    }
  
    attachEventListeners() {
        const closeBtn = this.dashboard.querySelector('.user-close-btn');
        closeBtn.addEventListener('click', () => this.close());
    
        // Close the dashboard when clicking outside of it
        this._outsideClickHandler ??= (event) => {
            if (!this.dashboard.contains(event.target) && this.container === document.body) {
                this.close();
            }
        };
        if (!this._outsideClickAttached) {
            document.addEventListener('click', this._outsideClickHandler);
            this._outsideClickAttached = true;
        }
        this._listenersAttached = true;
    }
  
    close() {
        if (this.dashboard.isConnected) this.dashboard.remove();
        this.closed = true;
    }

    update(userData, notificationClass) {
        this.userData = userData || this.userData;
        this.notificationClass = notificationClass || this.notificationClass;
        this.closed = false;
        // this.dashboard.remove();
        // this.dashboard = null;
        this.render();
    }
}

class AdminDashboard {
    /**
     * Create a new AdminDashboard instance.
     * @param {HTMLElement} [containerDiv=document.documentElement] - The container to render the dashboard in.
     * @param {Object} options - Options for the dashboard.
     * @param {Array.<Object>} [options.subjects] - An array of subjects to display. Each subject must have a "fileName", "data" and "cleared" property.
     * @param {function} [options.updateCallback] - A callback to call when a subject's json file is updated. The callback takes two arguments: the full data and the file data.
     * @param {Object} [options.users] - An object containing user data.
     * @param {function} [options.analysisFunction] - A callback to call when the user wants to analyze the data. The callback takes one argument: an object with properties subject, users, data, log, clipboard and copy.
     * @param {function} [options.refreshUsers] - A callback to refresh users when syncing settings.
     * @param {function} [options.refreshProfiles] - A callback to refresh profiles when syncing settings.
     * @param {function} [options.notificationClass] - A class to add to the notifications.
     * @param {bool} [options.isCustomProfile] - A callback to check if a profile is a custom profile.
     * @param {string} [options.fetchPrefix] - The page to make fetch requests to
    */
    constructor(containerDiv = null, options) {
        var jsonFiles = options.subjects;
        var update = options.updateCallback;
        var userData = options.users;
        var profiles = options.profiles;
        var dataAnalysis = options.analysisFunction;
        var refreshUsers = options.refreshUsers;
        var refreshProfiles = options.refreshProfiles;
        var isCustomProfile = options.isCustomProfile;
        var notificationClass = options.notificationClass;
        var fetchPrefix = options.fetchPrefix ?? "";
        this.jsonFiles = jsonFiles;
        this.userData = userData ?? {};
        this.profiles = profiles ?? [];
        this.onJsonUpdate = update ?? ((fullData, fileData)=>console.log('Updated JSON:', fullData, fileData));
        this.currentFileIndex = -1;
        this.userEditList = [];
        this.container = containerDiv || document.documentElement;
        this.dataAnalysis = dataAnalysis;
        this.refreshUsers = refreshUsers;
        this.refreshProfiles = refreshProfiles;
        this.isCustomProfile = isCustomProfile;
        this.notificationClass = notificationClass;
        this.fetchPrefix = fetchPrefix;
        this.className = options.className ?? '';
        this.dashboard = null;
        this.render();
    }

    icons = {
        menu: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e8eaed"><path d="M120-240v-80h720v80H120Zm0-200v-80h720v80H120Zm0-200v-80h720v80H120Z"/></svg>`,
        plus: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M444-444H240v-72h204v-204h72v204h204v72H516v204h-72v-204Z"/></svg>`,
        trash: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M312-144q-29.7 0-50.85-21.15Q240-186.3 240-216v-480h-48v-72h192v-48h192v48h192v72h-48v479.57Q720-186 698.85-165T648-144H312Zm336-552H312v480h336v-480ZM384-288h72v-336h-72v336Zm120 0h72v-336h-72v336ZM312-696v480-480Z"/></svg>`,
        copy: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M360-240q-29.7 0-50.85-21.15Q288-282.3 288-312v-480q0-29.7 21.15-50.85Q330.3-864 360-864h384q29.7 0 50.85 21.15Q816-821.7 816-792v480q0 29.7-21.15 50.85Q773.7-240 744-240H360Zm0-72h384v-480H360v480ZM216-96q-29.7 0-50.85-21.15Q144-138.3 144-168v-552h72v552h456v72H216Zm144-216v-480 480Z"/></svg>`,
        swap: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="m336-168-51-51 105-105H96v-72h294L285-501l51-51 192 192-192 192Zm288-240L432-600l192-192 51 51-105 105h294v72H570l105 105-51 51Z"/></svg>`,
        shuffle: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M576-192v-72h69L531-378l51-51 114 114v-69h72v192H576Zm-333 0-51-51 453-453h-69v-72h192v192h-72v-69L243-192Zm135-339L192-717l51-51 186 186-51 51Z"/></svg>`,
        clear: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="m675-144-51-51 69-69-69-69 51-51 69 69 69-69 51 51-69 69 69 69-51 51-69-69-69 69Zm-195 0q-140 0-238-98t-98-238h72q0 109 77.5 186.5T480-216q19 0 37-2.5t35-7.5v74q-17 4-35 6t-37 2ZM144-576v-240h72v130q46-60 114.5-95T480-816q140 0 238 98t98 238h-72q0-109-77.5-186.5T480-744q-62 0-114.5 25.5T277-648h107v72H144Zm409 205L444-480v-192h72v162l74 75-37 64Z"/></svg>`,
        upload: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M444-336v-342L339-573l-51-51 192-192 192 192-51 51-105-105v342h-72ZM263.72-192Q234-192 213-213.15T192-264v-72h72v72h432v-72h72v72q0 29.7-21.16 50.85Q725.68-192 695.96-192H263.72Z"/></svg>`,
        download: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M480-336 288-528l51-51 105 105v-342h72v342l105-105 51 51-192 192ZM263.72-192Q234-192 213-213.15T192-264v-72h72v72h432v-72h72v72q0 29.7-21.16 50.85Q725.68-192 695.96-192H263.72Z"/></svg>`,
        edit: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M216-216h51l375-375-51-51-375 375v51Zm-72 72v-153l498-498q11-11 23.84-16 12.83-5 27-5 14.16 0 27.16 5t24 16l51 51q11 11 16 24t5 26.54q0 14.45-5.02 27.54T795-642L297-144H144Zm600-549-51-51 51 51Zm-127.95 76.95L591-642l51 51-25.95-25.05Z"/></svg>`,
        invite: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M168-192q-29.7 0-50.85-21.16Q96-234.32 96-264.04v-432.24Q96-726 117.15-747T168-768h624q29.7 0 50.85 21.16Q864-725.68 864-695.96v432.24Q864-234 842.85-213T792-192H168Zm312-240L168-611v347h624v-347L480-432Zm0-85 312-179H168l312 179Zm-312-94v-85 432-347Z"/></svg>`,
        admin: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M672-288q25 0 42.5-17.5T732-348q0-25-17.5-42.5T672-408q-25 0-42.5 17.5T612-348q0 25 17.5 42.5T672-288Zm-.09 120Q704-168 731-184t43-42q-23-13-48.72-19.5t-53.5-6.5q-27.78 0-53.28 7T570-226q16 26 42.91 42 26.91 16 59 16ZM480-96q-133-30-222.5-150.5T168-515v-229l312-120 312 120v221q-22-10-39-16t-33-8v-148l-240-92-240 92v180q0 49 12.5 96t36.5 88.5q24 41.5 58.5 76T425-194q8 23 25.5 48.5T489-98l-4.5 1-4.5 1Zm191.77 0Q592-96 536-152.23q-56-56.22-56-136Q480-368 536.23-424q56.22-56 136-56Q752-480 808-423.77q56 56.22 56 136Q864-208 807.77-152q-56.22 56-136 56ZM480-480Z"/></svg>`,
        notification: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M192-216v-72h48v-240q0-87 53.5-153T432-763v-53q0-20 14-34t34-14q20 0 34 14t14 34v53q85 16 138.5 82T720-528v240h48v72H192Zm288-276Zm-.21 396Q450-96 429-117.15T408-168h144q0 30-21.21 51t-51 21ZM312-288h336v-240q0-70-49-119t-119-49q-70 0-119 49t-49 119v240Z"></path></svg>`,
        warn: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="m48-144 432-720 432 720H48Zm127-72h610L480-724 175-216Zm304.79-48q15.21 0 25.71-10.29t10.5-25.5q0-15.21-10.29-25.71t-25.5-10.5q-15.21 0-25.71 10.29t-10.5 25.5q0 15.21 10.29 25.71t25.5 10.5ZM444-384h72v-192h-72v192Zm36-86Z"/></svg>`,
        fix: 
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e8eaed"><path d="M748-144 531-361l68-68 217 217-68 68Zm-536 0-68-68 268-268-64-64-38 38-52-52v70l-26 26-112-112 26-26h70l-36-37 144-144q17-17 38.5-26t45.5-9q24 0 45.5 9t38.5 26l-87 86 47 47-36 36 64 64 83-83q-5-13-8-26t-3-27q0-55 38.5-93.5T684-816q14 0 27 3t26 8l-87 87 68 68 87-87q6 12 8.5 25.5T816-684q0 55-38.5 93T684-553q-14 0-27-2.5t-26-8.5L212-144Z"/></svg>`,
        lock:
        `<svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="M240-96q-30 0-51-21t-21-51v-384q0-30 21-51t51-21h48v-96q0-80 56-136t136-56q80 0 136 56t56 136v96h48q30 0 51 21t21 51v384q0 30-21 51t-51 21H240Zm0-72h480v-384H240v384Zm240-120q30 0 51-21t21-51q0-30-21-51t-51-21q-30 0-51 21t-21 51q0 30 21 51t51 21ZM360-624h240v-96q0-50-35-85t-85-35q-50 0-85 35t-35 85v96ZM240-168v-384 384Z"/></svg>`,
        visibility:
        `<svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="M480-312q75 0 127.5-52.5T660-492q0-75-52.5-127.5T480-672q-75 0-127.5 52.5T300-492q0 75 52.5 127.5T480-312Zm0-72q-45 0-76.5-31.5T372-492q0-45 31.5-76.5T480-600q45 0 76.5 31.5T588-492q0 45-31.5 76.5T480-384Zm0 216q-146 0-264-82.5T48-492q50-126 168-208.5T480-783q146 0 264 82.5T912-492q-50 126-168 208.5T480-168Zm0-72q113 0 207.5-61T831-492q-49-130-143.5-191.5T480-745q-113 0-207.5 61.5T129-492q49 130 143.5 191T480-240Zm0-252Z"/></svg>`,
        schedule:
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="M612-168q-70 0-119-49t-49-119q0-70 49-119t119-49q70 0 119 49t49 119q0 70-49 119t-119 49Zm56-80 40-40-68-68v-100h-56v124l84 84ZM216-96q-30 0-51-21t-21-51v-528q0-30 21-51t51-21h72v-96h72v96h240v-96h72v96h72q30 0 51 21t21 51v231q-18-17-34.5-28T744-514v-38H216v384h191q12 21 27 38.5t34 33.5H216Zm0-528h528v-72H216v72Zm0 0v-72 72Z"/></svg>`,
        play:
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="M320-203v-554l435 277-435 277Zm72-277Zm0 146 228-146-228-146v292Z"/></svg>`,
        close:
        `<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="m291-240-51-51 189-189-189-189 51-51 189 189 189-189 51 51-189 189 189 189-51 51-189-189-189 189Z"/></svg>`,
    }
  
    render() {
        this.dashboard = this.dashboard || document.createElement('div');
        this.dashboard.className = 'admin-dashboard';
        this.dashboard.innerHTML = `
            <div class="admin-dashboard-sidebar">
                <div class="admin-inline admin-dashboard-main-header">
                    <div>
                        <span class="admin-kicker">Amministrazione</span>
                        <h3>Dashboard</h3>
                    </div>
                    <button class="admin-dashboardMenuBtn admin-icon-button" title="Apri il menu" aria-label="Apri il menu">
                        ${this.icons.menu}
                    </button>
                </div>
                <div class="admin-active-class" title="Classe attualmente selezionata">
                    <span></span>
                    <div><small>Classe attiva</small><strong>${this.className || 'Non disponibile'}</strong></div>
                </div>
                <div class="admin-dashboard-sidebar-content">
                    <ul class="admin-json-file-list">
                        <li data-index="-1" class="${this.currentFileIndex === -1 ? 'admin-active' : ''}">👥 <span>Utenti</span></li>
                        ${!Array.isArray(this.profiles) ? "" : `
                            <li data-index="-2" class="${this.currentFileIndex === -2 ? 'admin-active' : ''}">🏫 <span>Classi</span></li>
                        `}
                        ${this.jsonFiles.map((file, index) => `
                            <li data-index="${index}" class="${index === this.currentFileIndex ? 'admin-active' : ''}">📚 <span>${file.fileName}</span></li>
                        `).join('')}
                        ${this.jsonFiles.length === 0 ? "<li data-index=\"-1\" class=\"\">Nessuna materia!</li>" : ""}
                    </ul>
                    <div class="inline admin-json-file-list-actions">
                        <button id="addFileBtn" class="admin-action-button is-primary" title="Crea una nuova materia" aria-label="Crea una nuova materia">
                            ${this.icons.plus}<span>Materia</span>
                        </button>
                        <button id="removeFileBtn" class="admin-action-button is-danger is-icon" title="Elimina la materia selezionata" aria-label="Elimina la materia selezionata">
                            ${this.icons.trash}
                        </button>
                    </div>
                </div>
            </div>
            <div class="admin-dashboard-content">
                <div class="admin-dashboard-header">
                    <div>
                        <span class="admin-kicker">${this.className || 'Dashboard classe'}</span>
                        <h2 id="admin-dashboard-header-title" title="Clicca per rinominare la materia">Dashboard</h2>
                    </div>
                    <button class="admin-close-btn admin-icon-button" title="Chiudi la dashboard" aria-label="Chiudi la dashboard">${this.icons.close}</button>
                </div>
                <div class="admin-dashboard-subject-section" data-section="${this.dashboardStayOnAnswers ? "answers" : "days"}">
                    <div class="admin-dashboard-controls">
                        <div class="admin-state-grid">
                            <label id="lockControl" class="admin-state-control" title="Impedisce temporaneamente nuove risposte">
                                <input type="checkbox" id="lockSwitch">
                                <span class="admin-state-icon">${this.icons.lock}</span>
                                <span class="admin-state-copy"><strong>Blocco voti</strong><small id="lockHint">Caricamento…</small></span>
                                <span class="admin-toggle" aria-hidden="true"></span>
                            </label>
                            <label id="hideControl" class="admin-state-control" title="Nasconde una materia vuota agli utenti">
                                <input type="checkbox" id="hideSwitch">
                                <span class="admin-state-icon">${this.icons.visibility}</span>
                                <span class="admin-state-copy"><strong>Visibilità</strong><small id="hideHint">Caricamento…</small></span>
                                <span class="admin-toggle" aria-hidden="true"></span>
                            </label>
                        </div>
                    </div>
                    <section class="admin-automation-card">
                        <div class="admin-section-heading">
                            <span class="admin-section-icon">${this.icons.schedule}</span>
                            <div><span class="admin-kicker">Promemoria e priorità</span><h3>Automazione</h3></div>
                        </div>
                        <div id="campaignStatus" class="admin-campaign-status admin-static-element"></div>
                        <div class="admin-inline admin-toolbar admin-automation-actions">
                            <button id="scheduleCampaignBtn" class="admin-action-button is-primary" title="Programma apertura, priorità e promemoria">${this.icons.schedule}<span>Programma</span></button>
                            <button id="startCampaignBtn" class="admin-action-button is-success" title="Apri subito la fase per gli utenti prioritari">${this.icons.play}<span>Apri ora</span></button>
                            <button id="cancelCampaignBtn" class="admin-action-button is-danger" title="Annulla l’automazione attiva">${this.icons.close}<span>Annulla</span></button>
                        </div>
                    </section>
                    <div class="admin-inline admin-toolbar admin-answer-actions" aria-label="Azioni sulle risposte">
                        ${typeof this.dataAnalysis === "function" ? `
                            <button id="copyAnswersBtn" class="admin-action-button is-primary" title="Copia l’elenco delle risposte">${this.icons.copy}<span>Copia</span></button>
                        ` : ""}
                        <button id="editAnswersBtn" class="admin-action-button is-primary" title="Visualizza e modifica le risposte">${this.icons.swap}<span>Risposte</span></button>
                        <button id="filloutAnswersBtn" class="admin-action-button is-warning" title="Assegna automaticamente gli utenti mancanti">${this.icons.shuffle}<span>Compila</span></button>
                        <button id="clearAnswersBtn" class="admin-action-button is-danger" title="Cancella tutte le risposte della materia">${this.icons.clear}<span>Svuota</span></button>
                    </div>
                    <div class="admin-subject-text-prompts-container" style="display: none">
                        <h3>Domande</h3>
                        <input type="text" name="subjectTextPromptBeforeAnswering" id="subjectTextPromptBeforeAnswering" placeholder="Testo prima di scegliere un opzione: 'Quale opzione vuoi scegliere?'">
                        <input type="text" name="subjectTextPromptAfterAnswering" id="subjectTextPromptAfterAnswering" placeholder="Testo dopo aver scelto l'opzione: 'Hai scelto la tua opzione!'">
                        <input type="text" name="subjectTextPromptAlreadyAnswered" id="subjectTextPromptAlreadyAnswered" placeholder="Testo se l'utente ha già scelto l'opzione: 'Hai già scelto la tua opzione, e non puoi cambiarla!'">
                    </div>
                    <div class="admin-days-container">
                        <h3>Scelte</h3>
                        <div id="daysList"></div>
                        <div class="admin-inline inline admin-toolbar">
                            <button id="fixAnswersBtn" class="admin-action-button is-muted is-icon" title="Correggi le risposte e le disponibilità" aria-label="Correggi le risposte e le disponibilità">
                                ${this.icons.fix}
                            </button>
                            <button id="addDayBtn" class="admin-action-button is-primary" title="Aggiungi una scelta">
                                ${this.icons.plus}<span>Aggiungi scelta</span>
                            </button>
                        </div>
                    </div>
                    <div class="admin-dashboard-subject-answers-section">
                        <span class="admin-dashboard-subject-answers-header clickable-span" id="editAnswersLeaveBtn">&lt; Scelte</span>
                        <div class="admin-days-container">
                            <div id="subjectAnswerList"></div>
                        </div>
                    </div>
                </div>
                <div class="admin-dashboard-user-section">
                    <div class="admin-days-container">
                        <div id="userList"></div>
                        <button id="addUserBtn" class="admin-action-button is-primary" title="Aggiungi un utente">
                            ${this.icons.plus}<span>Aggiungi utente</span>
                        </button>
                    </div>
                </div>
                <div class="admin-dashboard-profile-section">
                    <p>Per entrare in una classe, clicca il suo nome.</p>
                    <div class="admin-days-container">
                        <div id="profileList"></div>
                        <div class="admin-inline inline admin-toolbar">
                            <button id="uploadProfileBtn" class="admin-action-button is-muted" title="Importa una classe">
                                ${this.icons.upload}<span>Importa</span>
                            </button>
                            <button id="addProfileBtn" class="admin-action-button is-primary" title="Crea una nuova classe">
                                ${this.icons.plus}<span>Nuova classe</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    
        this.applyStyles();
        this._listenersAttached = false;
        this.attachEventListeners();
        if (!this.appended) this.container.appendChild(this.dashboard);
        this.appended = true;
        this.updateDashboard();
    }

    sortSubjectDates(daysObject) {
        return Object.keys(daysObject).sort((a, b) => {
            const [dayA, monthA, yearA] = a.split('-').map(Number);
            const [dayB, monthB, yearB] = b.split('-').map(Number);
            
            // Compare years
            if (yearA !== yearB) return yearA - yearB;
            
            // If years are the same, compare months
            if (monthA !== monthB) return monthA - monthB;
            
            // If months are the same, compare days
            return dayA - dayB;
        }).reduce((sortedObj, key) => {
            sortedObj[key] = daysObject[key];
            return sortedObj;
        }, {});
    }

    sortUserDates(dates) {
        return dates.sort((a, b) => {
            const [dayA, monthA, yearA] = a.split('-').map(Number);
            const [dayB, monthB, yearB] = b.split('-').map(Number);
            
            // Compare years
            if (yearA !== yearB) return yearA - yearB;
            
            // If years are the same, compare months
            if (monthA !== monthB) return monthA - monthB;
            
            // If months are the same, compare days
            return dayA - dayB;
        });
    }

    async mergeUserEdits() {
        var mergeList = (typeof this.refreshUsers === "function") ? (await this.refreshUsers()) : {};
        for (var elUUID in mergeList) {
            if (this.userEditList.includes(elUUID)) continue;
            this.userData[elUUID] = mergeList[elUUID];
        }
        for (var usUUID in this.userData) {
            if (this.userEditList.includes(usUUID)) continue;
            if (!mergeList[usUUID]) delete this.userData[usUUID];
        }
        this.userEditList = [];
    }

    getSubjectControlState(customIndex = this.currentFileIndex) {
        const file = this.jsonFiles[customIndex];
        const data = file?.data ?? {};
        const answers = Array.isArray(data.answers) ? {} : (data.answers ?? {});
        const days = Array.isArray(data.days) ? {} : (data.days ?? {});
        const eligibleUsers = Object.keys(this.userData).filter(uuid => !this.userData[uuid]?.watcherAcc);
        const answeredUsers = eligibleUsers.filter(uuid => Object.prototype.hasOwnProperty.call(answers, uuid));
        const answerCount = answeredUsers.length;
        const totalUsers = eligibleUsers.length;
        const allAnswered = totalUsers > 0 && answerCount >= totalUsers;
        const partiallyAnswered = answerCount > 0 && !allAnswered;
        const empty = Object.keys(days).length === 0 && Object.keys(answers).length === 0;
        return {answerCount, totalUsers, allAnswered, partiallyAnswered, empty};
    }
  
    updateDashboard() {
        this.dashboardStayOnAnswers ??= false;
        const useSubjects = (this.currentFileIndex > -1 && this.jsonFiles[this.currentFileIndex]);
        if (this.currentFileIndex > -1 && !this.jsonFiles[this.currentFileIndex]) this.currentFileIndex = this.jsonFiles.length - 1;
        if (this.jsonFiles.length < 1 && this.currentFileIndex > -1) this.currentFileIndex = -1;
        const currentFile = useSubjects ? this.jsonFiles[this.currentFileIndex] : this.userData;
        this.updateHeader();
        if (this.dashboard.querySelector('li.admin-active')) this.dashboard.querySelector('li.admin-active').classList.remove("admin-active");
        this.dashboard.querySelector(`li[data-index="${this.currentFileIndex}"]`)?.classList.add("admin-active");
        this.dashboard.querySelector(".admin-dashboard-subject-section").dataset.section = this.dashboardStayOnAnswers ? "answers" : "days";
        this.dashboardStayOnAnswers = false;
        if (useSubjects) {
            this.dashboard.querySelector(".admin-dashboard-profile-section").classList.add("hided");
            this.dashboard.querySelector(".admin-dashboard-user-section").classList.add("hided");
            this.dashboard.querySelector(".admin-dashboard-subject-section").classList.remove("hided");
            const lockSwitch = this.dashboard.querySelector('#lockSwitch');
            const hideSwitch = this.dashboard.querySelector('#hideSwitch');
            const clearAnswersBtn = this.dashboard.querySelector('#clearAnswersBtn');
            const copyAnswersBtn = this.dashboard.querySelector('#copyAnswersBtn');
            const editAnswersBtn = this.dashboard.querySelector('#editAnswersBtn');
            const filloutAnswersBtn = this.dashboard.querySelector('#filloutAnswersBtn');
            const scheduleCampaignBtn = this.dashboard.querySelector('#scheduleCampaignBtn');
            const startCampaignBtn = this.dashboard.querySelector('#startCampaignBtn');
            const cancelCampaignBtn = this.dashboard.querySelector('#cancelCampaignBtn');
            const lockControl = this.dashboard.querySelector('#lockControl');
            const hideControl = this.dashboard.querySelector('#hideControl');
            const lockHint = this.dashboard.querySelector('#lockHint');
            const hideHint = this.dashboard.querySelector('#hideHint');

            lockSwitch.checked = Boolean(currentFile.data.lock);
            hideSwitch.checked = Boolean(currentFile.data.hide);
            const state = this.getSubjectControlState();
            lockControl.classList.remove('is-warning', 'is-error', 'is-active');
            lockControl.classList.toggle('is-active', lockSwitch.checked);
            lockSwitch.disabled = state.allAnswered && !lockSwitch.checked;
            if (state.allAnswered) {
                lockControl.classList.add('is-error');
                lockHint.textContent = lockSwitch.checked
                    ? 'Tutti hanno risposto: puoi solo sbloccare'
                    : 'Tutti hanno risposto: blocco non necessario';
                lockControl.title = lockHint.textContent;
            } else if (state.partiallyAnswered) {
                lockControl.classList.add('is-warning');
                lockHint.textContent = `${state.answerCount} su ${state.totalUsers} hanno già risposto: bloccare può interrompere la votazione`;
                lockControl.title = lockHint.textContent;
            } else {
                lockHint.textContent = lockSwitch.checked ? 'Nuove risposte bloccate' : 'Nessuna risposta: puoi bloccare in sicurezza';
                lockControl.title = lockHint.textContent;
            }

            hideControl.classList.remove('is-error', 'is-active');
            hideControl.classList.toggle('is-active', hideSwitch.checked);
            hideSwitch.disabled = !hideSwitch.checked && !state.empty;
            if (state.empty) {
                hideHint.textContent = hideSwitch.checked ? 'Materia nascosta agli utenti' : 'Materia vuota: può essere nascosta';
                hideControl.title = hideHint.textContent;
            } else {
                hideControl.classList.add('is-error');
                hideHint.textContent = hideSwitch.checked
                    ? 'Materia nascosta: puoi renderla visibile'
                    : 'Svuota prima tutte le date e le risposte';
                hideControl.title = hideHint.textContent;
            }
            const campaign = currentFile.data.campaign ?? {status: "idle", enabled: false};
            const campaignStatus = this.dashboard.querySelector('#campaignStatus');
            const unlockText = campaign.unlockAt ? new Date(campaign.unlockAt * 1000).toLocaleString('it-IT') : "non programmata";
            const campaignLabels = {
                idle: 'Non attiva', scheduled: 'Programmata', priority: 'Fase prioritaria',
                general: 'Aperta a tutti', paused: 'In pausa', complete: 'Completata', cancelled: 'Annullata'
            };
            const campaignLabel = campaignLabels[campaign.status] ?? campaign.status ?? 'Non attiva';
            campaignStatus.innerHTML = `<span class="admin-status-pill" data-status="${campaign.status ?? 'idle'}">${campaignLabel}</span><span><strong>Apertura:</strong> ${unlockText}</span>`;
            const hasChoices = Object.keys(Array.isArray(currentFile.data.days) ? {} : (currentFile.data.days ?? {})).length > 0;
            scheduleCampaignBtn.disabled = !hasChoices;
            startCampaignBtn.disabled = !hasChoices;
            scheduleCampaignBtn.title = hasChoices ? 'Programma apertura, priorità e promemoria' : 'Aggiungi almeno una scelta prima di programmare';
            startCampaignBtn.title = hasChoices ? 'Apri subito la fase per gli utenti prioritari' : 'Aggiungi almeno una scelta prima di aprire';
            cancelCampaignBtn.classList.toggle('hided', !campaign.enabled || ['idle', 'complete', 'cancelled'].includes(campaign.status));
            filloutAnswersBtn.classList.toggle('hided', !hasChoices || this.getMissingAnswers().length === 0);
            if (Object.keys(Array.isArray(currentFile.data.answers) ? {} : currentFile.data.answers).length > 0) {
                clearAnswersBtn.classList.remove("hided");
                copyAnswersBtn?.classList.remove("hided");
                editAnswersBtn.classList.remove("hided");
            } else {
                clearAnswersBtn.classList.add("hided");
                copyAnswersBtn?.classList.add("hided");
                editAnswersBtn.classList.add("hided");
            }
            if (this.getMissingAnswers().length > 1) editAnswersBtn.classList.remove("hided");
        
            this.renderDays();
            this.renderAnswers();
        } else if (this.currentFileIndex === -1) {
            this.dashboard.querySelector(".admin-dashboard-profile-section").classList.add("hided");
            this.dashboard.querySelector(".admin-dashboard-user-section").classList.remove("hided");
            this.dashboard.querySelector(".admin-dashboard-subject-section").classList.add("hided");

            this.renderUsers();
        } else if (this.currentFileIndex === -2) {
            this.dashboard.querySelector(".admin-dashboard-profile-section").classList.remove("hided");
            this.dashboard.querySelector(".admin-dashboard-user-section").classList.add("hided");
            this.dashboard.querySelector(".admin-dashboard-subject-section").classList.add("hided");

            this.renderProfiles();
        }
    }

    updateHeader() {
        const useSubjects = (this.currentFileIndex > -1 && this.jsonFiles[this.currentFileIndex]);
        const currentFile = useSubjects ? this.jsonFiles[this.currentFileIndex] : this.userData;
        this.dashboard.querySelector('h2#admin-dashboard-header-title').textContent = useSubjects ? currentFile.fileName :
            (this.currentFileIndex === -1 ? `Utenti (${Object.keys(this.userData).length})` : `Classi (${this.profiles.length})`);
    }
  
    renderDays() {
        const daysList = this.dashboard.querySelector('#daysList');
        const currentFile = this.jsonFiles[this.currentFileIndex];
        daysList.innerHTML = '';
        const objEntries = Object.entries(currentFile.data.days);
        objEntries.forEach(([date, dayData]) => {
            const dayElement = document.createElement('div');
            dayElement.className = 'admin-day-item';
            dayElement.innerHTML = `
                <span>${date}${dayData.dayName != "-" ? ` ${dayData.dayName}` : ""}</span>
                <span class="admin-availability">Posti liberi: ${dayData.availability === "-1/-1" ? "∞" : dayData.availability}</span>
                <div class="admin-inline admin-user-actions">
                    <button class="admin-edit-day-btn is-primary" data-date="${date}" title="Modifica o sposta questa scelta">
                        ${this.icons.edit}
                    </button>
                    ${dayData.availability.split('/')[0] < dayData.availability.split('/')[1] ? `<button class="admin-clear-day-btn is-warning" data-date="${date}" title="Svuota le risposte per questa scelta">
                        ${this.icons.clear}
                    </button>` : ""}
                    <button class="admin-delete-day-btn is-danger" data-date="${date}" title="Elimina questa scelta">
                        ${this.icons.trash}
                    </button>
                </div>
            `;
            daysList.appendChild(dayElement);
        });
        if (objEntries.length === 0) {
            daysList.innerHTML = `
                <div class="admin-day-item">
                    <span>Non ci sono ancora possibili risposte in questo file!</span>
                </div>
            `;
        }
    }

    renderUsers() {
        const userList = this.dashboard.querySelector('#userList');
        const currentFile = this.userData;
        userList.innerHTML = '';
        const objEntries = Object.entries(currentFile);
        objEntries.forEach(([userUUID, userData]) => {
            const userElement = document.createElement('div');
            var userAnswerNumber = Array.isArray(userData.answers) ? userData.answers.length : ((answers)=>{
                var returnNumber = 0;
                for (var subject in answers) if (answers[subject].length > 0) returnNumber++;
                return returnNumber;
            })(userData.answers);
            userElement.className = 'admin-day-item';
            var userFlags = [];
            userData.admin && userFlags.push("A");
            userData.watcherAcc && userFlags.push("W");
            userData.priority && userFlags.push("P");
            userFlags = userFlags.length > 0 ? `[${userFlags.join("] [")}] ` : "";
            userElement.innerHTML = `
                <span data-user="${userUUID}" title="Clicca per cambiare il nome utente" oldtitle="Clicca per copiare il link d'accesso dell'utente" oldonclick="if (confirm(\`Vuoi copiare un testo con il link d'accesso per ${userData.name}?\`)) {navigator.clipboard.writeText('${location.href.split('?')[0]}?UID=${userUUID}${!this.isCustomProfile ? '' : `&profile=${this.isCustomProfile}`}');alert('Il link per ${userData.name} è stato copiato!')}" style="cursor: pointer;">${userFlags}${userData.name}</span>
                <span class="admin-availability">Risposte: ${userAnswerNumber}</span>
                <div class="admin-inline admin-user-actions">
                    <button class="admin-priority-btn ${userData.priority ? 'is-warning' : 'is-muted'}" data-user="${userUUID}" title="${userData.priority ? 'Rimuovi la priorità' : 'Rendi questo utente prioritario'}">
                        ${userData.priority ? '★' : '☆'}
                    </button>
                    <button class="admin-invite-btn ${!userData.pushSubscriptions ? 'is-muted' : 'admin-notify-user-btn is-warning'}" data-user="${userUUID}" title="${!userData.pushSubscriptions ? 'Copia il link di invito' : 'Invia una notifica di accesso'}">
                        ${!userData.pushSubscriptions ? this.icons.invite : this.icons.notification}
                    </button>
                    <button class="admin-admin-btn is-primary" data-user="${userUUID}" title="Modifica i permessi amministratore">
                        ${this.icons.admin}
                    </button>
                    <button class="admin-delete-day-btn is-danger" data-user="${userUUID}" title="Elimina questo utente">
                        ${this.icons.trash}
                    </button>
                </div>
            `;
            userElement.title = userUUID;
            userList.appendChild(userElement);
        });
        if (objEntries.length === 0) {
            userList.innerHTML = `
                <div class="admin-day-item">
                    <span>Weird.. No users were found!</span>
                </div>
            `;
        }
    }

    renderProfiles() {
        const profileList = this.dashboard.querySelector('#profileList');
        const currentFile = this.profiles;
        profileList.innerHTML = '';
        currentFile.forEach((e) => {
            const profileId = typeof e === "string" ? e : e.id;
            const profileName = typeof e === "string" ? e : e.name;
            const profileElement = document.createElement('div');
            profileElement.className = 'admin-day-item';
            profileElement.innerHTML = `
                <span title="Apri questa classe" onclick="if (confirm('Vuoi entrare nella classe ${profileName}?')) location.href = location.href.split('?')[0]+'?class=${profileId}&UID='+window.UID;">${profileName}</span>
                <div class="admin-inline admin-user-actions">
                    <button class="admin-download-file-btn is-muted" data-profile="${profileId}" title="Scarica un backup della classe">
                        ${this.icons.download}
                    </button>
                    <button class="admin-edit-day-btn is-primary" data-profile="${profileId}" data-profile-name="${profileName}" title="Rinomina la classe">
                        ${this.icons.edit}
                    </button>
                    <button class="admin-delete-day-btn is-danger" data-profile="${profileId}" data-profile-name="${profileName}" title="Elimina la classe">
                        ${this.icons.trash}
                    </button>
                </div>
            `;
            profileList.appendChild(profileElement);
        });
        if (currentFile.length === 0) {
            profileList.innerHTML = `
                <div class="admin-day-item">
                    <span>Non ci sono profili!</span>
                </div>
            `;
        }
        if (this.currentFileIndex === -2) this.updateHeader();
    }

    renderAnswers() {
        const answerList = this.dashboard.querySelector('#subjectAnswerList');
        const currentFile = this.jsonFiles[this.currentFileIndex];
        answerList.innerHTML = '';
        const objEntries = Object.entries(currentFile.data.answers);
        const dayDividedAnswers = {};
        objEntries.forEach(([UUID, answerData]) => {
            dayDividedAnswers[answerData.date] ??= [];
            dayDividedAnswers[answerData.date].push({UUID, answerData});
        });
        dayDividedAnswers["Esclusi"] ??= [];
        const dayList = Object.entries(currentFile.data.days);
        dayList.forEach(([day, data])=>{
            dayDividedAnswers[day] ??= [];
        });
        const missingUsers = this.getMissingAnswers();
        if (missingUsers.length > 0) answerList.innerHTML += `<div class="admin-inline inline">
            <h2 style="flex: 1;">In attesa di risposta</h2>
            <button class="admin-edit-day-btn admin-notify-all-btn is-warning" data-user="unset" title="Invia un promemoria a tutti gli utenti mancanti" aria-label="Invia un promemoria a tutti gli utenti mancanti">
                ${this.icons.notification}
            </button>
        </div>`
        missingUsers.forEach(userUUID => {
            const answerElement = document.createElement('div');
            answerElement.className = 'admin-day-item';
            answerElement.classList.add('admin-static-element');
            answerElement.innerHTML = `
                <span>${this.userData[userUUID].name}</span>
                <div class="admin-inline admin-user-actions">
                    <button class="admin-edit-day-btn admin-add-answer-btn is-primary" data-user="${userUUID}" title="Aggiungi una risposta">
                        ${this.icons.plus}
                    </button>
                    <button class="admin-edit-day-btn admin-notify-user-btn is-warning ${!this.userData[userUUID].pushSubscriptions ? 'admin-disabled' : ''}" ${!this.userData[userUUID].pushSubscriptions ? 'disabled' : ''} data-user="${userUUID}" title="Invia un promemoria">
                        ${!this.userData[userUUID].pushSubscriptions ? 
                            this.icons.warn
                            : this.icons.notification
                        }
                    </button>
                </div>
            `;
            answerList.appendChild(answerElement);
        });
        const dayEntries = Object.entries(this.sortSubjectDates(dayDividedAnswers));
        dayEntries.forEach(([day, dayData]) => {
            const dayDividerElement = document.createElement('h2');
            dayDividerElement.innerText = day;
            answerList.appendChild(dayDividerElement);
            dayData.forEach(e=>{
                const {UUID, answerData} = e;
                if (!this.userData[UUID]) return this.removeUserAnswer(UUID, true);
                const answerElement = document.createElement('div');
                answerElement.className = 'admin-day-item';
                answerElement.innerHTML = `
                    <span>[${answerData.answerNumber}] ${this.userData[UUID].name}</span>
                    <span class="admin-availability">${answerData.date}</span>
                    <div class="admin-inline admin-user-actions">
                        <button class="admin-edit-day-btn is-primary" data-user="${UUID}" title="Modifica la risposta">
                            ${this.icons.swap}
                        </button>
                        <button class="admin-delete-day-btn is-danger" data-user="${UUID}" title="Elimina la risposta">
                            ${this.icons.trash}
                        </button>
                    </div>
                `;
                answerList.appendChild(answerElement);
                dayDividedAnswers[day] = dayDividedAnswers[day] || [];
                dayDividedAnswers[day].push(e);
            });
            if (dayData.length === 0) dayDividerElement.classList.add('admin-day-divider-empty-day');
            answerList.innerHTML += `
                <div class="admin-day-item admin-switch-to-date">
                    <span>Sposta la risposta qui</span>
                    <span class="admin-availability">${day === "Esclusi" ? "Infiniti" : this.jsonFiles[this.currentFileIndex].data.days[day].availability} Posti liberi</span>
                    <div class="admin-inline admin-user-actions">
                        <button class="admin-edit-day-btn is-primary" data-user="0/swapToDate-${day}" title="Sposta la risposta qui">
                            ${this.icons.swap}
                        </button>
                    </div>
                </div>
            `;
        });
        if (objEntries.length === 0 && missingUsers.length < 2) {
            answerList.innerHTML = `
                <div class="admin-day-item">
                    <span>Non ci sono risposte per questa materia!</span>
                </div>
            `;
        }
    }
  
    applyStyles() {
        const style = document.createElement('style');
        document.getElementById('admin-dashboard-styles')?.remove();
        style.id = 'admin-dashboard-styles';
        style.textContent = `
            .clickable-span {
                cursor: pointer;
                color: dodgerblue;
                font-weight: bold;
            }
            .admin-disabled {
                background-color: rgba(100, 100, 100, 0.5) !important;
                cursor: not-allowed !important;
                pointer-events: visible;
            }
            .admin-inline {
                display: flex;
            }
            .admin-dashboard {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(40, 40, 40, 0.9);
                backdrop-filter: blur(10px);
                display: flex;
                font-family: Arial, sans-serif;
                color: white;
                z-index: 1000;
            }
            .admin-dashboard-sidebar {
                width: 200px;
                background-color: rgba(30, 30, 30, 0.8);
                backdrop-filter: blur(10px);
                padding: 20px;
                overflow-y: auto;
            }
            .admin-dashboard-content {
                flex-grow: 1;
                padding: 20px;
                overflow-y: auto;
            }
            .admin-dashboard-main-header h3 {
                flex: 1;
            }
            .admin-dashboard-main-header button {
                display: none;
                padding: 10px 15px;
                background-color: transparent;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                margin-bottom: 0;
                height: 100%;
            }
            .admin-dashboard-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            .admin-dashboard-header > .admin-close-btn {
                background: none;
                border: none;
                font-size: 24px;
                position: inherit;
                cursor: pointer;
                color: #999;
            }
            .admin-dashboard-subject-section {
                position: relative;
            }
            .admin-dashboard-subject-section[data-section]:not([data-section="days"]) > * {display: none;}
            .admin-dashboard-subject-answers-section {
                display: none;
                padding: 10px;
                width: calc(100% - 20px);
                background-color: rgba(40, 40, 40, 1);
                left: 0;
                position: absolute;
                top: 0;
            }
            .admin-day-divider-empty-day {display: none;}
            .admin-swapping-user-answer > .admin-day-divider-empty-day {display: block !important;}
            .admin-swapping-user-answer > .admin-day-item {
                transition: none;
                border: 2px solid transparent;
            }
            .admin-swapping-user-answer > .admin-day-item .admin-delete-day-btn {
                display: none;
            }
            .admin-swapping-user-answer > .admin-day-item .admin-add-answer-btn:not(.admin-current-swapping-element) {display: none !important;}
            .admin-current-swapping-element {
                background-color: rgba(100, 100, 100, 0.5) !important;
            }
            .admin-switch-to-date:not(.admin-swapping-user-answer > .admin-switch-to-date) {display: none !important;}
            .admin-switch-to-date {
                background-color: rgba(0, 0, 0, 0.2) !important;
                border: 2px rgba(255, 255, 255, 0.2) solid !important;
                border-style: dashed !important;
            }
            .admin-day-item:has(.admin-current-swapping-element), .admin-swapping-user-answer > .admin-day-item:hover, .admin-switch-to-date {
                background-color: rgba(60, 60, 60, 0.3);
                border: 2px rgba(255, 255, 255, 0.7) solid;
                border-style: dashed;
            }
            .admin-dashboard-subject-section[data-section="answers"] > .admin-dashboard-subject-answers-section {
                display: block;
            }
            .admin-dashboard-controls {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-bottom: 20px;
            }
            .admin-control-row {
                display: flex;
                align-items: center;
                gap: 20px;
            }
            .admin-switch-container {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .admin-switch {
                position: relative;
                display: inline-block;
                width: 60px;
                height: 34px;
            }
            .admin-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .admin-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
            }
            .admin-slider:before {
                position: absolute;
                content: "";
                height: 26px;
                width: 26px;
                left: 4px;
                bottom: 4px;
                background-color: white;
                transition: .4s;
            }
            input:checked + .admin-slider {
                background-color: #2196F3;
            }
            input:checked + .admin-slider:before {
                transform: translateX(26px);
            }
            .admin-slider.admin-round {
                border-radius: 34px;
            }
            .admin-slider.admin-round:before {
                border-radius: 50%;
            }
            .admin-action-button {
                padding: 10px 15px;
                background-color: rgba(255, 255, 255, 0.3);
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                margin-bottom: 20px;
            }
            .admin-days-container {
                margin-top: 20px;
            }
            .admin-day-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
                padding: 10px;
                background-color: rgba(60, 60, 60, 0.8);
                border-radius: 5px;
            }
            .admin-user-actions {
                display: flex;
                margin-left: auto;
                gap: 5px;
            }
            .admin-delete-day-btn, .admin-clear-day-btn, .admin-edit-day-btn, .admin-download-file-btn, .admin-admin-btn, .admin-invite-btn, .admin-priority-btn {
                padding: 5px 10px;
                background-color: #f44336;
                color: white;
                border: none;
                border-radius: 3px;
                margin-top: 0;
                cursor: pointer;
            }
            .admin-admin-btn {
                background-color: dodgerblue;
            }
            .admin-edit-day-btn, .admin-download-file-btn {
                background-color: dodgerblue;
            }
            .admin-invite-btn {
                background-color: rgba(255, 255, 255, 0.3);
            }
            .admin-user-actions > button {
                margin-left: 0;
            }
            .admin-json-file-list {
                list-style-type: none;
                padding: 0;
                margin-bottom: 20px;
            }
            .admin-json-file-list li {
                padding: 10px;
                cursor: pointer;
            }
            .admin-json-file-list li.admin-active {
                background-color: rgba(70, 70, 70, 0.8);
            }
            @media (max-width: 819px) {
                .admin-dashboard {
                    flex-direction: column;
                }
                .admin-dashboard-main-header button {
                    display: block;
                }
                .admin-dashboard-sidebar {
                    position: fixed;
                    z-index: 15;
                    width: calc(100% - 40px);
                    height: 58px; /*max-height: 30%;*/
                    overflow-y: hidden;
                }
                .admin-dashboard-sidebar.extend {
                    height: calc(100% - 40px);
                }
                .admin-dashboard-sidebar-content {
                    height: calc(100% - 45px);
                    display: flex;
                    flex-direction: column;
                }
                .admin-json-file-list {
                    /*max-height: calc(100% - 65px - 68px - 20px - 20px);*/
                    overflow-y: auto;
                    flex: 1;
                }
                .admin-dashboard-content {
                    height: 70%;
                    margin-top: 100px;
                }
                .admin-day-item {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .admin-day-item button {
                    margin-top: 10px;
                }
                .admin-control-row {
                    flex-direction: column;
                    align-items: flex-start;
                }
            }
            @media (min-width: 820px) {
                .admin-availability::before {
                    content: "|";
                    margin: 0 10px;
                    color: #999;
                }
            }
        `;
        style.textContent += `
            .admin-dashboard, .admin-dashboard * { box-sizing: border-box; }
            .admin-dashboard {
                --admin-bg: #130e0b;
                --admin-panel: rgba(31, 25, 22, .94);
                --admin-card: rgba(255, 255, 255, .055);
                --admin-border: rgba(255, 255, 255, .11);
                --admin-text: #fffaf5;
                --admin-muted: #b9afa7;
                --admin-primary: #6d63e8;
                --admin-success: #168b63;
                --admin-warning: #b66b12;
                --admin-danger: #b83a43;
                background:
                    radial-gradient(circle at 18% 8%, rgba(109, 65, 25, .24), transparent 38%),
                    linear-gradient(145deg, #291400 0%, #18110d 36%, #101011 100%);
                color: var(--admin-text);
                font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                overflow: hidden;
            }
            .admin-dashboard button, .admin-dashboard input, .admin-dashboard select { font: inherit; }
            .admin-dashboard button { min-width: 0; }
            .admin-dashboard-sidebar {
                width: 270px;
                flex: 0 0 270px;
                padding: 24px 18px;
                background: rgba(15, 13, 12, .78);
                border-right: 1px solid var(--admin-border);
                backdrop-filter: blur(24px);
            }
            .admin-dashboard-main-header { align-items: center; gap: 12px; }
            .admin-dashboard-main-header h3,
            .admin-dashboard-header h2,
            .admin-section-heading h3 { margin: 2px 0 0; letter-spacing: -.02em; }
            .admin-dashboard-main-header h3 { font-size: 1.35rem; }
            .admin-kicker {
                display: block;
                color: #d9a66e;
                font-size: .7rem;
                font-weight: 750;
                letter-spacing: .13em;
                text-transform: uppercase;
            }
            .admin-active-class {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 20px 0 14px;
                padding: 11px 12px;
                min-width: 0;
                border: 1px solid rgba(217, 166, 110, .24);
                border-radius: 14px;
                background: rgba(217, 166, 110, .08);
            }
            .admin-active-class > span { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 50%; background: #45c98d; box-shadow: 0 0 0 5px rgba(69, 201, 141, .12); }
            .admin-active-class div { min-width: 0; }
            .admin-active-class small { display: block; color: var(--admin-muted); font-size: .68rem; }
            .admin-active-class strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .88rem; }
            .admin-dashboard-sidebar-content { display: flex; min-height: 0; flex-direction: column; }
            .admin-json-file-list { display: grid; gap: 5px; margin: 0 0 16px; }
            .admin-json-file-list li {
                display: flex;
                align-items: center;
                gap: 9px;
                padding: 10px 12px;
                min-width: 0;
                border: 1px solid transparent;
                border-radius: 11px;
                color: var(--admin-muted);
                transition: background .16s ease, border-color .16s ease, color .16s ease;
            }
            .admin-json-file-list li span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .admin-json-file-list li:hover { background: rgba(255,255,255,.055); color: var(--admin-text); }
            .admin-json-file-list li.admin-active { background: rgba(109, 99, 232, .17); border-color: rgba(132, 123, 244, .35); color: #fff; }
            .admin-json-file-list-actions { display: flex; gap: 8px; margin-top: auto; }
            .admin-dashboard-content { min-width: 0; padding: 28px clamp(18px, 3vw, 44px) 48px; }
            .admin-dashboard-content > * { width: min(1100px, 100%); margin-left: auto; margin-right: auto; }
            .admin-dashboard-header {
                position: sticky;
                top: -28px;
                z-index: 4;
                isolation: isolate;
                margin: 0 -4px 22px;
                padding: 18px 4px 14px;
                background: transparent;
            }
            .admin-dashboard-header::before {
                content: "";
                position: absolute;
                z-index: -1;
                top: -28px;
                bottom: 0;
                left: 50%;
                width: 100vw;
                transform: translateX(-50%);
                pointer-events: none;
                background: linear-gradient(180deg, rgba(19,14,11,.98) 70%, transparent);
            }
            .admin-dashboard-header h2 { max-width: min(70vw, 720px); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: clamp(1.55rem, 3vw, 2.3rem); }
            .admin-icon-button, .admin-dashboard-header > .admin-close-btn, .admin-dashboard-main-header button {
                display: inline-grid;
                place-items: center;
                width: 42px;
                height: 42px;
                padding: 0;
                border: 1px solid var(--admin-border);
                border-radius: 12px;
                background: rgba(255,255,255,.06);
                color: var(--admin-text);
                cursor: pointer;
            }
            .admin-dashboard-main-header .admin-dashboardMenuBtn { display: none; }
            .admin-state-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .admin-state-control {
                position: relative;
                display: grid;
                grid-template-columns: 44px minmax(0, 1fr) auto;
                align-items: center;
                gap: 12px;
                min-height: 78px;
                padding: 14px;
                border: 1px solid var(--admin-border);
                border-radius: 17px;
                background: var(--admin-card);
                cursor: pointer;
                transition: border-color .18s ease, background .18s ease, transform .18s ease;
            }
            .admin-state-control:hover { transform: translateY(-1px); border-color: rgba(255,255,255,.2); }
            .admin-state-control > input { position: absolute; opacity: 0; pointer-events: none; }
            .admin-state-control:has(input:disabled) { cursor: not-allowed; opacity: .82; }
            .admin-state-control.is-active { background: rgba(109, 99, 232, .15); border-color: rgba(132, 123, 244, .38); }
            .admin-state-control.is-warning { background: rgba(182, 107, 18, .13); border-color: rgba(245, 158, 11, .42); }
            .admin-state-control.is-error { background: rgba(184, 58, 67, .12); border-color: rgba(239, 68, 68, .42); }
            .admin-state-icon { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 13px; background: rgba(255,255,255,.07); color: #f2c18d; }
            .admin-state-control.is-warning .admin-state-icon { color: #f7b955; }
            .admin-state-control.is-error .admin-state-icon { color: #ff737c; }
            .admin-state-copy { min-width: 0; }
            .admin-state-copy strong, .admin-state-copy small { display: block; }
            .admin-state-copy strong { font-size: .93rem; }
            .admin-state-copy small { margin-top: 3px; color: var(--admin-muted); line-height: 1.3; }
            .admin-toggle { position: relative; width: 42px; height: 24px; border-radius: 999px; background: rgba(255,255,255,.18); transition: background .18s ease; }
            .admin-toggle::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: white; transition: transform .18s ease; }
            .admin-state-control input:checked ~ .admin-toggle { background: var(--admin-primary); }
            .admin-state-control input:checked ~ .admin-toggle::after { transform: translateX(18px); }
            .admin-automation-card, .admin-days-container {
                padding: 18px;
                border: 1px solid var(--admin-border);
                border-radius: 18px;
                background: var(--admin-card);
            }
            .admin-automation-card { margin: 14px 0; }
            .admin-section-heading { display: flex; align-items: center; gap: 12px; }
            .admin-section-icon { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: rgba(109,99,232,.16); color: #b5afff; }
            .admin-campaign-status { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin: 15px 0; color: var(--admin-muted); font-size: .88rem; }
            .admin-status-pill { padding: 5px 9px; border: 1px solid var(--admin-border); border-radius: 999px; background: rgba(255,255,255,.06); color: var(--admin-text); font-size: .74rem; font-weight: 700; }
            .admin-status-pill[data-status="priority"], .admin-status-pill[data-status="scheduled"] { border-color: rgba(245,158,11,.4); background: rgba(182,107,18,.18); }
            .admin-status-pill[data-status="general"], .admin-status-pill[data-status="complete"] { border-color: rgba(69,201,141,.4); background: rgba(22,139,99,.18); }
            .admin-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
            .admin-answer-actions { margin: 0 0 16px; }
            .admin-action-button,
            .admin-delete-day-btn, .admin-clear-day-btn, .admin-edit-day-btn, .admin-download-file-btn, .admin-admin-btn, .admin-invite-btn, .admin-priority-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-height: 42px;
                padding: 9px 13px;
                margin: 0;
                border: 1px solid rgba(255,255,255,.13);
                border-radius: 11px;
                background: rgba(255,255,255,.08);
                color: var(--admin-text);
                cursor: pointer;
                transition: filter .15s ease, transform .15s ease, opacity .15s ease;
            }
            .admin-action-button:hover:not(:disabled),
            .admin-user-actions button:hover:not(:disabled) { filter: brightness(1.14); transform: translateY(-1px); }
            .admin-action-button:disabled, .admin-user-actions button:disabled { cursor: not-allowed; opacity: .48; }
            .admin-action-button.is-icon { width: 42px; padding: 0; }
            .admin-action-button.is-primary, .admin-user-actions .is-primary { background: #5750bd; border-color: #7169d8; }
            .admin-action-button.is-success, .admin-user-actions .is-success { background: var(--admin-success); border-color: #26a77b; }
            .admin-action-button.is-warning, .admin-user-actions .is-warning { background: #8e5510; border-color: #bd761d; }
            .admin-action-button.is-danger, .admin-user-actions .is-danger { background: #8f2c34; border-color: #c34750; }
            .admin-action-button.is-muted, .admin-user-actions .is-muted { background: rgba(255,255,255,.075); }
            .admin-days-container { margin-top: 14px; }
            .admin-days-container > h3 { margin-top: 0; }
            .admin-day-item {
                display: grid;
                grid-template-columns: minmax(140px, 1fr) auto auto;
                align-items: center;
                gap: 10px;
                min-width: 0;
                margin: 0 0 8px;
                padding: 11px 12px;
                border: 1px solid rgba(255,255,255,.075);
                border-radius: 13px;
                background: rgba(255,255,255,.045);
            }
            .admin-day-item > span:first-child { min-width: 0; overflow-wrap: anywhere; }
            .admin-availability { color: var(--admin-muted); font-size: .84rem; }
            .admin-availability::before { display: none; }
            .admin-user-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-left: auto; }
            .admin-user-actions > button { width: 40px; min-height: 40px; padding: 0; }
            .admin-dashboard-subject-answers-section { width: 100%; padding: 0; background: var(--admin-bg); }
            .clickable-span { display: inline-flex; margin-bottom: 12px; color: #aaa3ff; }
            .admin-disabled { background: rgba(255,255,255,.05) !important; pointer-events: auto; }
            .admin-subject-text-prompts-container input { width: 100%; margin: 5px 0; }
            .hided { display: none !important; }
            @media (max-width: 819px) {
                .admin-dashboard { display: block; overflow-x: hidden; overflow-y: auto; }
                .admin-dashboard-sidebar {
                    position: fixed;
                    z-index: 15;
                    top: max(8px, env(safe-area-inset-top));
                    left: 10px;
                    right: 10px;
                    width: auto;
                    height: 62px;
                    padding: 10px 12px;
                    overflow: hidden;
                    border: 1px solid var(--admin-border);
                    border-radius: 16px;
                    box-shadow: 0 12px 35px rgba(0,0,0,.32);
                    transition: height .22s ease;
                }
                .admin-dashboard-sidebar.extend { height: min(78dvh, 620px); }
                .admin-dashboard-main-header { height: 40px; }
                .admin-dashboard-main-header .admin-dashboardMenuBtn { display: inline-grid; margin-left: auto; }
                .admin-dashboard-main-header h3 { font-size: 1.08rem; }
                .admin-dashboard-main-header .admin-kicker { font-size: .58rem; }
                .admin-active-class { margin: 14px 0 8px; }
                .admin-dashboard-sidebar-content { height: calc(100% - 104px); }
                .admin-json-file-list { flex: 1; overflow-y: auto; }
                .admin-dashboard-content { width: 100%; height: auto; min-height: 100dvh; margin: 0; padding: calc(84px + env(safe-area-inset-top)) 12px calc(28px + env(safe-area-inset-bottom)); overflow: visible; }
                .admin-dashboard-header { top: 0; padding-top: 12px; }
                .admin-dashboard-header::before { top: 0; }
                .admin-dashboard-header h2 { max-width: 70vw; font-size: 1.55rem; }
                .admin-state-grid { grid-template-columns: 1fr; }
                .admin-state-control { min-height: 72px; padding: 11px; }
                .admin-toolbar { width: 100%; }
                .admin-automation-actions .admin-action-button { flex: 1 1 calc(50% - 8px); }
                .admin-answer-actions .admin-action-button { flex: 1 1 calc(50% - 8px); }
                .admin-day-item { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
                .admin-day-item .admin-availability { grid-column: 1; }
                .admin-day-item .admin-user-actions { grid-column: 2; grid-row: 1 / span 2; }
                .admin-day-item button { margin-top: 0; }
                .admin-days-container { padding: 13px; }
                .admin-dashboard-subject-answers-section { position: static; }
            }
            @media (max-width: 430px) {
                .admin-state-control { grid-template-columns: 40px minmax(0, 1fr) auto; gap: 9px; }
                .admin-state-icon { width: 40px; height: 40px; }
                .admin-toggle { width: 38px; height: 22px; }
                .admin-toggle::after { width: 16px; height: 16px; }
                .admin-state-control input:checked ~ .admin-toggle::after { transform: translateX(16px); }
                .admin-automation-card { padding: 14px; }
                .admin-action-button span { font-size: .8rem; }
                .admin-day-item { grid-template-columns: 1fr; align-items: start; }
                .admin-day-item .admin-availability, .admin-day-item .admin-user-actions { grid-column: 1; grid-row: auto; }
                .admin-day-item .admin-user-actions { width: 100%; margin-left: 0; justify-content: flex-end; }
            }
        `;
        document.head.appendChild(style);
    }

    async richPrompt(text, options = {}) {
        options.type ??= "input";
        options.textOverride ??= text;
        options.isTitleDesc ??= false;
        options.descText ??= false;
        options.confirmText ??= "Confirm";
        options.cancelText ??= "Cancel";
        options.inputPlaceholder ??= "Write text here";
        options.awaitClose ??= false;
        options.pickAnswers ??= [];
        var {type, descText, confirmText, cancelText, inputPlaceholder, textOverride, isTitleDesc, awaitClose, pickAnswers} = options;
        if (isTitleDesc) {
            descText = text;
            if (textOverride === text) textOverride = "Notification";
        }
        text = textOverride;
        return new Promise(async function(resolve, reject) {
            try {
                text ??= type === "pick" ? "Select an option" : (type === "confirm" ? "Confirm the action" : "Input some text...");
                var backgroundDiv = document.createElement('div');
                backgroundDiv.style = "background-color: rgba(0, 0, 0, 0.5);position: fixed;top: 0;left: 0;z-index: 999;width: 100%;height: 100%;";
                var promptDiv = document.createElement('div');
                promptDiv.style = "background-color: rgba(40, 40, 40);position: relative;top: 50%;left: 50%;transform: translate(-50%, -50%);border-radius: 5px;padding: 10px;max-width: calc(90% - 25px);max-height: calc(90% - 25px);overflow-y: auto;";
                var promptTitle = document.createElement('h1');
                promptTitle.style = "";
                promptTitle.innerText = text;
                var promptDesc = document.createElement('p');
                promptDesc.style = "margin: 10px 5px;";
                promptDesc.innerText = descText;
                if (type === "pick") {
                    var promptInput = document.createElement('select');
                    for (var ans of pickAnswers) {
                        var option = document.createElement('option');
                        option.value = ans.value;
                        option.innerText = ans.text;
                        promptInput.appendChild(option);
                    }
                    promptInput.style = "color: white;border: 1px solid gray;border-radius: 5px;padding: 5px 10px;";
                } else {
                    var promptInput = document.createElement('input');
                    promptInput.style = "color: white;border: 1px solid gray;border-radius: 5px;padding: 5px 10px;";
                    promptInput.placeholder = inputPlaceholder ?? "Write text here";
                }
                var promptActionDiv = document.createElement('div');
                promptActionDiv.style = "display: block;margin-left: auto;";
                var promptConfirm = document.createElement('button');
                promptConfirm.style = "background-color: rgba(0, 70, 170);border-radius: 5px;border: 1px solid gray;color: white;margin: 5px;";
                promptConfirm.innerText = confirmText;
                promptConfirm.addEventListener('click', ()=>{backgroundDiv.remove();resolve(type != "confirm" ? promptInput.value : true)});
                var promptCancel = document.createElement('button');
                promptCancel.style = "background-color: rgba(20, 20, 20);border-radius: 5px;border: 1px solid gray;color: white;margin: 5px;";
                promptCancel.innerText = cancelText;
                promptCancel.addEventListener('click', ()=>{backgroundDiv.remove();resolve(false);});
            
                promptDiv.appendChild(promptTitle);
                if (!!descText && descText.length > 0) promptDiv.appendChild(promptDesc);
                if (type != "confirm") promptDiv.appendChild(promptInput);
                if (cancelText !== false) promptActionDiv.appendChild(promptCancel);
                if (confirmText !== false) promptActionDiv.appendChild(promptConfirm);
                if (cancelText !== false || confirmText !== false) promptDiv.appendChild(promptActionDiv)
                backgroundDiv.appendChild(promptDiv);
                document.body.appendChild(backgroundDiv);
                if (!!awaitClose && typeof awaitClose.then === "function") {await awaitClose;promptConfirm.click();}
            }catch(e){reject(e)}
        });
    }
  
    attachEventListeners() {
        if (this._listenersAttached) return;
        this._listenersAttached = true;

        const closeBtn = this.dashboard.querySelector('.admin-close-btn');
        closeBtn.addEventListener('click', () => this.close());
    
        const dashHeader = this.dashboard.querySelector(`h2#admin-dashboard-header-title`);
        dashHeader.addEventListener('click', async ()=>{
            if (this.currentFileIndex > -1) await this.editSubject();
        });

        const dashMenuBTN = this.dashboard.querySelector('.admin-dashboardMenuBtn');
        dashMenuBTN.addEventListener('click', ()=>{
            this.dashboard.querySelector(".admin-dashboard-sidebar").classList.toggle("extend");
        });

        const lockSwitch = this.dashboard.querySelector('#lockSwitch');
        lockSwitch.addEventListener('change', async (e) => {
            const state = this.getSubjectControlState();
            if (e.target.checked && state.allAnswered) {
                e.target.checked = false;
                this.updateDashboard();
                return alert('Tutti gli utenti hanno già risposto: non è necessario bloccare la materia.');
            }
            const previousValue = !e.target.checked;
            this.jsonFiles[this.currentFileIndex].data.lock = e.target.checked;
            try {
                await this.updateJSON();
            } catch (error) {
                this.jsonFiles[this.currentFileIndex].data.lock = previousValue;
                e.target.checked = previousValue;
            }
        });
    
        const hideSwitch = this.dashboard.querySelector('#hideSwitch');
        hideSwitch.addEventListener('change', async (e) => {
            const state = this.getSubjectControlState();
            if (e.target.checked && !state.empty) {
                e.target.checked = false;
                this.updateDashboard();
                return alert('Per nascondere la materia devi prima eliminare tutte le date e tutte le risposte.');
            }
            const previousValue = !e.target.checked;
            this.jsonFiles[this.currentFileIndex].data.hide = e.target.checked;
            try {
                await this.updateJSON();
            } catch (error) {
                this.jsonFiles[this.currentFileIndex].data.hide = previousValue;
                e.target.checked = previousValue;
            }
        });
    
        const clearAnswersBtn = this.dashboard.querySelector('#clearAnswersBtn');
        clearAnswersBtn.addEventListener('click', async () => {
            await this.clearSubjectAnswers();
        });

        const filloutAnswersBtn = this.dashboard.querySelector('#filloutAnswersBtn');
        filloutAnswersBtn.addEventListener('click', async () => {
            await this.filloutAnswers();
        });

        const scheduleCampaignBtn = this.dashboard.querySelector('#scheduleCampaignBtn');
        scheduleCampaignBtn.addEventListener('click', async () => await this.configureCampaign(false));
        const startCampaignBtn = this.dashboard.querySelector('#startCampaignBtn');
        startCampaignBtn.addEventListener('click', async () => await this.configureCampaign(true));
        const cancelCampaignBtn = this.dashboard.querySelector('#cancelCampaignBtn');
        cancelCampaignBtn.addEventListener('click', async () => {
            if (!confirm('Vuoi annullare questa automazione? Le risposte non verranno cancellate.')) return;
            await this.campaignRequest('cancel');
        });

        if (typeof this.dataAnalysis === "function") {
            const copyAnswersBtn = this.dashboard.querySelector('#copyAnswersBtn');
            copyAnswersBtn.addEventListener('click', () => {
                if (!confirm(`Vuoi copiare le prenotazioni per questa materia?`)) return;
                this.dataAnalysis({
                    clipboard: true, 
                    copy: "prenotazioni", 
                    log: false,
                    data: this.jsonFiles[this.currentFileIndex].data,
                    subject: this.jsonFiles[this.currentFileIndex].fileName,
                    users: this.userData,
                    minimal: !confirm(`Vuoi copiare la versione completa? (Annulla = Minimale)`)
                });
                alert("Prenotazioni utente copiate!");
            });
        }

        const editAnswersBtn = this.dashboard.querySelector('#editAnswersBtn');
        editAnswersBtn.addEventListener('click', () => {
            this.renderAnswers();
            this.dashboard.querySelector(".admin-dashboard-subject-section").dataset.section = "answers";
        });
        const editAnswersLeaveBtn = this.dashboard.querySelector('#editAnswersLeaveBtn');
        editAnswersLeaveBtn.addEventListener('click', () => {
            this.renderDays();
            this.dashboard.querySelector(".admin-dashboard-subject-section").dataset.section = "days";
        });
    
        const addUserBtn = this.dashboard.querySelector('#addUserBtn');
        addUserBtn.addEventListener('click', async () => await this.addUser());

        const addDayBtn = this.dashboard.querySelector('#addDayBtn');
        addDayBtn.addEventListener('click', async () => await this.addDay());

        const fixAnswersBtn = this.dashboard.querySelector('#fixAnswersBtn');
        fixAnswersBtn.addEventListener('click', async () => {
            await this.fixUserDataAnswers();
            await this.fixSubjectAvailability(true);
        });

        const addProfileBtn = this.dashboard.querySelector('#addProfileBtn');
        addProfileBtn.addEventListener('click', async () => await this.addProfile());

        const uploadProfileBtn = this.dashboard.querySelector('#uploadProfileBtn');
        uploadProfileBtn.addEventListener('click', () => this.uploadProfile());
    
        const daysList = this.dashboard.querySelector('#daysList');
        daysList.addEventListener('click', async (e) => {
            const target = e.target.closest('[data-date]');
            if (!target) return;
            if (target.classList.contains('admin-delete-day-btn')) {
                await this.deleteDay(target.dataset.date);
            }
            if (target.classList.contains('admin-clear-day-btn')) {
                await this.clearDayAnswers(target.dataset.date);
            }
            if (target.classList.contains('admin-edit-day-btn')) {
                await this.editDay(target.dataset.date);
            }
        });

        const userList = this.dashboard.querySelector('#userList');
        userList.addEventListener('click', async (e) => {
            const target = e.target.closest('[data-user]');
            if (!target) return;
            if (target.tagName.toLowerCase() == "span") {
                await this.editUser(target.dataset.user);
            }
            if (target.classList.contains('admin-delete-day-btn')) {
                await this.deleteUser(target.dataset.user);
            }
            if (target.classList.contains('admin-admin-btn')) {
                await this.toggleAdminUser(target.dataset.user);
            }
            if (target.classList.contains('admin-priority-btn')) {
                this.userData[target.dataset.user].priority = !this.userData[target.dataset.user].priority;
                if (!this.userEditList.includes(target.dataset.user)) this.userEditList.push(target.dataset.user);
                await this.updateJSON();
                this.renderUsers();
            }
            if (target.classList.contains('admin-invite-btn')) {
                const name = this.userData[target.dataset.user].name.split(' ');
                if (target.classList.contains('admin-notify-user-btn')) {
                    if (!confirm(`Vuoi mandare una notifica di accesso a ${name.join(" ")}?`)) return;
                    await this.sendSubjectNotification([target.dataset.user], this.currentFileIndex, {
                        title: "Nuova Notifica",
                        desc: "Questa notifica ti è stata inviata da un admin per entrare nel sito. Vai a dare un occhiata!"
                    });
                    return;
                }
                if (!confirm(`Vuoi copiare un testo con il link d'accesso per ${name.join(" ")}?`)) return;
                navigator.clipboard.writeText(`Ciao, ${name[name.length - 1]}!\nQuesto è il tuo link di accesso per la pagina delle prenotazioni delle interrogazioni programmate:\n${location.href.split('?')[0]}?UID=${target.dataset.user}&class=${window.CLASS}\nNON CONDIVIDERLO ALTRIMENTI DARAI IL TUO ACCESSO AD ALTRE PERSONE!\nNon perdere troppo tempo a rispondere siccome i posti sono limitati!`);
                alert(`Il testo con il link d'accesso di ${name.join(" ")} è stato copiato!`);
            }
        });

        const answerList = this.dashboard.querySelector('#subjectAnswerList');
        answerList.addEventListener('click', async (e) => {
            const target = e.target.closest('[data-user]');
            if (!target) return;
            if (target.classList.contains('admin-notify-all-btn')) {
                if (confirm("Sei sicuro di voler inviare una notifica a TUTTI gli utenti mancanti?")) await this.sendSubjectNotification(this.getMissingAnswers(), undefined, {urgency: "high"});
            } else if (target.classList.contains('admin-notify-user-btn')) {
                if (target.classList.contains("admin-disabled")) return alert("Questo utente non ha attivato le notifiche!");
                if (confirm(`Sei sicuro di voler inviare una notifica a ${this.userData[target.dataset.user].name}?`)) await this.sendSubjectNotification([target.dataset.user], undefined, {urgency: "high"});
            } else if (target.classList.contains("admin-add-answer-btn") && false) { //! This is disabled because swapping to a day actually has better UI.
                const day = prompt(`Che giorno vuoi prenotare ${this.userData[target.dataset.user].name}? DD-MM-YYYY`);
                if (!prompt) return;
                await this.moveUserToDate(target.dataset.user, day, true);
            } else if (target.classList.contains('admin-edit-day-btn')) {
                this.dashboard.querySelector('#subjectAnswerList').classList.toggle("admin-swapping-user-answer");
                if (this.dashboard.querySelector('#subjectAnswerList').classList.contains("admin-swapping-user-answer")) 
                    target.classList.add("admin-current-swapping-element");
                else {
                    const firstUserElement = this.dashboard.querySelector('#subjectAnswerList').querySelector(".admin-current-swapping-element");
                    const user2UUID = firstUserElement.dataset.user;
                    firstUserElement.classList.remove("admin-current-swapping-element");
                    if (target.dataset.user.indexOf("0/swapToDate-") === 0) {
                        await this.moveUserToDate(user2UUID, target.dataset.user.split("swapToDate-")[1]);
                    } else {
                        if (user2UUID != target.dataset.user) await this.swapUserAnswer(target.dataset.user, user2UUID);
                    }
                }
            }
            if (target.classList.contains('admin-delete-day-btn')) {
                await this.removeUserAnswer(target.dataset.user);
            }
        });

        const profileList = this.dashboard.querySelector('#profileList');
        profileList.addEventListener('click', async (e) => {
            const target = e.target.closest('[data-profile]');
            if (!target) return;
            if (target.classList.contains('admin-download-file-btn')) {
                this.downloadProfile(target.dataset.profile);
            }
            if (target.classList.contains('admin-edit-day-btn')) {
                await this.editProfile(target.dataset.profile);
            }
            if (target.classList.contains('admin-delete-day-btn')) {
                await this.deleteProfile(target.dataset.profile);
            }
        });
    
        const fileList = this.dashboard.querySelector('.admin-json-file-list');
        fileList.addEventListener('click', (e) => {
            const target = e.target.closest('li[data-index]');
            if (target && !target.getAttribute('preventDefault')) {
                this.currentFileIndex = parseInt(target.dataset.index);
                fileList.querySelectorAll('li').forEach(li => li.classList.remove('admin-active'));
                target.classList.add('admin-active');
                this.dashboard.querySelector(".admin-dashboard-sidebar").classList.remove("extend");
                this.updateDashboard();
            }
        });
    
        const addFileBtn = this.dashboard.querySelector('#addFileBtn');
        addFileBtn.addEventListener('click', async () => await this.addFile());
    
        const removeFileBtn = this.dashboard.querySelector('#removeFileBtn');
        removeFileBtn.addEventListener('click', async () => await this.removeFile());
    }

    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    isSubjectNameAvailable(name) {
        for (var subj of this.jsonFiles) {
            if (subj.fileName === name) return false;
        }
        return true;
    }

    async campaignRequest(action, settings) {
        const subject = this.jsonFiles[this.currentFileIndex].fileName;
        const response = await fetch(`${this.fetchPrefix}?scope=campaign&UID=${window.UID}&class=${window.CLASS}&subject=${encodeURIComponent(subject)}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action, settings})
        }).then(r=>r.json()).catch(error=>({status: false, message: error.toString()}));
        if (!response.status) return alert(response.message ?? 'Impossibile aggiornare l’automazione.');
        this.jsonFiles[this.currentFileIndex].data.campaign = response.campaign;
        if (action === 'start') this.jsonFiles[this.currentFileIndex].data.lock = false;
        if (action === 'configure') this.jsonFiles[this.currentFileIndex].data.lock = true;
        this.updateDashboard();
        return response;
    }

    async configureCampaign(startNow = false) {
        if (startNow) {
            if (!confirm('Aprire ora la fase prioritaria e inviare le notifiche previste?')) return;
            return await this.campaignRequest('start');
        }
        const defaultDate = new Date(Date.now() + 60 * 60 * 1000);
        const localDefault = new Date(defaultDate.getTime() - defaultDate.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        const unlockAt = prompt('Data e ora di apertura (YYYY-MM-DDTHH:MM):', localDefault);
        if (!unlockAt) return;
        const priorityWindowMinutes = Number(prompt('Durata massima della fase prioritaria in minuti:', '180'));
        const priorityReminderMinutes = Number(prompt('Ogni quanti minuti ricordare agli utenti prioritari?', '60'));
        const regularReminderMinutes = Number(prompt('Ogni quanti minuti ricordare agli altri utenti?', '180'));
        const settings = {
            unlockAt,
            timezone: 'Europe/Rome',
            preNoticeMinutes: 60,
            priorityWindowMinutes,
            priorityReminderMinutes,
            regularReminderMinutes,
            notifyCoordinator: confirm('Vuoi ricevere una notifica quando finiscono i prioritari e quando finiscono tutti?')
        };
        const response = await this.campaignRequest('configure', settings);
        if (response?.status) alert('Apertura programmata. La materia è stata bloccata fino all’orario scelto.');
    }
  
    async addDay() {
        const notUseDates = this.jsonFiles[this.currentFileIndex].data.usesDays === false;
        const date = prompt(notUseDates ? "Inserisci il nome dell'opzione:" : 'Inserisci la data (DD-MM-YYYY):');
        if (date) {
            if (this.jsonFiles[this.currentFileIndex].data.days[date]) {
                alert(`Questa opzione è già esistente!`);
                return await this.addDay();
            }
            const formattedDate = `${date.split("-")[1]}/${date.split("-")[0]}/${date.split("-")[2]}`;
            let dayName = notUseDates ? "-" : (new Date(formattedDate)).toLocaleString("it-IT", {weekday: "long"});
            dayName = dayName.substring(0, 1).toUpperCase() + dayName.substring(1, dayName.length);
            let availability = prompt('Quanti posti dovrebbero essere disponibili? (Ex. 3):\n(-1 = Nessun Limite)');
            if (dayName && availability) {
                if (availability.length < 1) availability = "3";
                availability = `${availability}/${availability}`;
                if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
                if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
                this.jsonFiles[this.currentFileIndex].data.days[date] = { dayName, availability };
                await this.updateJSON();
                this.renderDays();
                this.renderAnswers();
            }
        }
    }

    async addUser() {
        const name = prompt('Inserisci il nome dell\'utente:');
        if (name) {
            const newUUID = this.generateUUID();
            this.userData[newUUID] = { name, admin: false, answers: {} };
            this.userEditList.push(newUUID);
            await this.updateJSON();
            this.renderUsers();
        }
    }

    async addProfile(customName) {
        const profile = customName ?? prompt(`Inserisci il nome della classe:`);
        if (profile) {
            if (profile === "default" || profile === "" || this.profiles.some(e=>(typeof e === "string" ? e : e.name) === profile)) {
                alert("Questo nome non è disponibile!");
                return await this.editProfile(profile);
            }
            const r = await fetch(`${this.fetchPrefix}?scope=profileMGMT&UID=${window.UID}&class=${window.CLASS}`, {
                method: "POST",
                body: JSON.stringify({
                    action: "newprofile",
                    method: confirm("Vuoi copiare in questa classe i dati della classe attuale? (Annulla = No)") ? "import" : "new",
                    profile
                })
            }).then(r=>r.json());
            if (!r.status) return alert('Impossibile completare l\'azione!');
            this.profiles.push({id: r.classId, name: profile, admin: true});
            this.profiles.sort((a,b)=>(a.name ?? a).localeCompare(b.name ?? b));
            this.renderProfiles();
        }
    }

    async deleteProfile(profile, force = false) {
        const profileEntry = this.profiles.find(e=>(typeof e === "string" ? e : e.id) === profile);
        if (!profileEntry) return alert("Non puoi cancellare questa classe!");
        if (!force && !confirm(`Sicuro di voler eliminare la classe ${profileEntry.name ?? profileEntry}?`)) return;
        const r = await fetch(`${this.fetchPrefix}?scope=profileMGMT&UID=${window.UID}&class=${window.CLASS}`, {
            method: "POST",
            body: JSON.stringify({
                action: "deleteprofile",
                profile
            })
        }).then(r=>r.json());
        if (!r.status) return alert('Impossibile completare l\'azione!');
        this.profiles.splice(this.profiles.indexOf(profileEntry), 1);
        this.profiles.sort((a,b)=>(a.name ?? a).localeCompare(b.name ?? b));
        this.renderProfiles();
    }
  
    async deleteDay(date) {
        if (confirm(`Sicuro di voler cancellare ${date}?`)) {
            await this.clearDayAnswers(date, true);
            if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
            delete this.jsonFiles[this.currentFileIndex].data.days[date];
            await this.updateJSON();
            this.renderDays();
            this.renderAnswers();
        }
    }
    
    async clearSubjectAnswers(force = false, customIndex = this.currentFileIndex) {
        if (!force && !confirm(`Sei sicuro di voler svuotare tutte le risposte per ${this.jsonFiles[this.currentFileIndex].fileName}?`)) return;
        this.jsonFiles[customIndex].data.answers = {};
        this.jsonFiles[customIndex].data.answerCount = 0;
        for (var day in this.jsonFiles[customIndex].data.days) {
            var max = this.jsonFiles[customIndex].data.days[day].availability.split("/")[1];
            this.jsonFiles[customIndex].data.days[day].availability = max + "/" + max;
        }
        this.jsonFiles[customIndex].cleared = true;
        await this.updateJSON();
        delete this.jsonFiles[customIndex].cleared;
        this.render();
    }

    async clearDayAnswers(day, force) {
        if (!force && !confirm(`Sei sicuro di voler svuotare tutte le risposte per ${this.jsonFiles[this.currentFileIndex].fileName}: ${day}?`)) return;
        var count = 0;
        for (var answer in this.jsonFiles[this.currentFileIndex].data.answers) {
            if (this.jsonFiles[this.currentFileIndex].data.answers[answer].date == day) {
                delete this.jsonFiles[this.currentFileIndex].data.answers[answer];
                this.jsonFiles[this.currentFileIndex].data.answerCount = this.jsonFiles[this.currentFileIndex].data.answerCount - 1;

                for (var user in this.userData) {
                    if (Array.isArray(this.userData[user].answers) && this.userData[user].answers.length === 0) this.userData[user].answers = {};
                    if (this.userData[user].answers[this.jsonFiles[this.currentFileIndex].fileName]) {
                        var index = this.userData[user].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==day);
                        if (index != -1) {
                            this.userData[user].answers[this.jsonFiles[this.currentFileIndex].fileName].splice(index, 1);
                            if (!this.userEditList.includes(user)) this.userEditList.push(user);
                        }
                        count = count + 1;
                    }
                }
            }
        }
        if (count > 0) {
            var tmpIndex = this.currentFileIndex;
            this.currentFileIndex = -1;
            await this.updateJSON(undefined, false, true);
            this.currentFileIndex = tmpIndex;
        }
        var max = this.jsonFiles[this.currentFileIndex].data.days[day].availability.split("/")[1];
        this.jsonFiles[this.currentFileIndex].data.days[day].availability = max + "/" + max;
        await this.updateJSON(undefined, false, !!force);
        this.render();
    }

    async removeUserAnswer(userUUID, force = false) {
        if (!this.userData[userUUID] && !this.jsonFiles[this.currentFileIndex].data.answers[userUUID]) return alert(`Questo utente non esiste!`);
        if (!this.jsonFiles[this.currentFileIndex].data.answers[userUUID]) return alert(`Questa risposta non esiste!`);
        if (!force && !confirm(`Sei sicuro di voler rimuovere questa risposta?`)) return;
        
        const day = this.jsonFiles[this.currentFileIndex].data.answers[userUUID].date;
        const answerPriority = this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber;
        
        if (!!this.userData[userUUID]) {
            if (Array.isArray(this.userData[userUUID].answers) && this.userData[userUUID].answers.length === 0) this.userData[userUUID].answers = {};
            if (day != "Esclusi" && this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName]) {
                var index = this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==day);
                if (index != -1) {
                    this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].splice(index, 1);
                    if (!this.userEditList.includes(userUUID)) this.userEditList.push(userUUID);
                }
            }
        
            var tmpIndex = this.currentFileIndex;
            this.currentFileIndex = -1;
            await this.updateJSON(undefined, false, true);
            this.currentFileIndex = tmpIndex;
        }

        delete this.jsonFiles[this.currentFileIndex].data.answers[userUUID];
        if (day != "Esclusi") {
            this.jsonFiles[this.currentFileIndex].data.answerCount = this.jsonFiles[this.currentFileIndex].data.answerCount - 1;
            if (this.jsonFiles[this.currentFileIndex].data.days[day].availability != "-1/-1") this.jsonFiles[this.currentFileIndex].data.days[day].availability = `${Number(this.jsonFiles[this.currentFileIndex].data.days[day].availability.split("/")[0]) + 1}/${this.jsonFiles[this.currentFileIndex].data.days[day].availability.split("/")[1]}`;
            
            const objEntries = Object.entries(this.jsonFiles[this.currentFileIndex].data.answers);
            objEntries.forEach(([userUUID, userData]) => {
                if (userData.answerNumber > answerPriority) this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber = userData.answerNumber - 1;
            });
        }
        
        await this.updateJSON(undefined, false, true);
        this.render();
        this.dashboardStayOnAnswers = true;
    }

    async swapUserAnswer(user1UUID, user2UUID) {
        if (!this.userData[user1UUID]) return alert(`Questo utente non esiste!`);
        if (!this.userData[user2UUID]) return alert(`Questo utente non esiste!`);
        if (Array.isArray(this.userData[user1UUID].answers) && this.userData[user1UUID].answers.length === 0) this.userData[user1UUID].answers = {};
        if (Array.isArray(this.userData[user2UUID].answers) && this.userData[user2UUID].answers.length === 0) this.userData[user2UUID].answers = {};
        if (
            (!this.jsonFiles[this.currentFileIndex].data.answers[user1UUID] || !this.jsonFiles[this.currentFileIndex].data.answers[user2UUID]) ||
            (!this.userData[user1UUID].answers[this.jsonFiles[this.currentFileIndex].fileName] || !this.userData[user2UUID].answers[this.jsonFiles[this.currentFileIndex].fileName])
        ) return alert(`Le risposte non esistono!`);

        let user1Index = -1;
        const u1Day = this.jsonFiles[this.currentFileIndex].data.answers[user1UUID].date;
        if (this.userData[user1UUID].answers[this.jsonFiles[this.currentFileIndex].fileName]) {
            user1Index = this.userData[user1UUID].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==u1Day);
        }
        let user2Index = -1;
        const u2Day = this.jsonFiles[this.currentFileIndex].data.answers[user2UUID].date;
        if (this.userData[user2UUID].answers[this.jsonFiles[this.currentFileIndex].fileName]) {
            user2Index = this.userData[user2UUID].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==u2Day);
        }

        if (user1Index < 0 || user2Index < 0) return alert(`Le risposte non esistono!`);
        if (!confirm(`Sei sicuro di voler scambiare queste risposte?`)) return;

        const tmpU = this.userData[user1UUID].answers[this.jsonFiles[this.currentFileIndex].fileName][user1Index];
        this.userData[user1UUID].answers[this.jsonFiles[this.currentFileIndex].fileName][user1Index] = this.userData[user2UUID].answers[this.jsonFiles[this.currentFileIndex].fileName][user2Index];
        this.userData[user2UUID].answers[this.jsonFiles[this.currentFileIndex].fileName][user2Index] = tmpU;
        if (!this.userEditList.includes(user1UUID)) this.userEditList.push(user1UUID);
        if (!this.userEditList.includes(user2UUID)) this.userEditList.push(user2UUID);

        const tmpF = this.jsonFiles[this.currentFileIndex].data.answers[user1UUID];
        const tmpUserAnswerOrder = [this.jsonFiles[this.currentFileIndex].data.answers[user1UUID].answerNumber, this.jsonFiles[this.currentFileIndex].data.answers[user2UUID].answerNumber];
        this.jsonFiles[this.currentFileIndex].data.answers[user1UUID] = this.jsonFiles[this.currentFileIndex].data.answers[user2UUID];
        this.jsonFiles[this.currentFileIndex].data.answers[user2UUID] = tmpF;
        this.jsonFiles[this.currentFileIndex].data.answers[user1UUID].answerNumber = tmpUserAnswerOrder[0];
        this.jsonFiles[this.currentFileIndex].data.answers[user2UUID].answerNumber = tmpUserAnswerOrder[1];

        var tmpIndex = this.currentFileIndex;
        this.currentFileIndex = -1;
        await this.updateJSON(undefined, false, true);
        this.currentFileIndex = tmpIndex;

        await this.updateJSON();
        this.render();
        this.dashboardStayOnAnswers = true;
    }

    async moveUserToDate(userUUID, date, force) {
        if (!this.userData[userUUID]) return alert(`Questo utente non esiste!`);
        if (!force && !confirm((date != "Esclusi" && Number(this.jsonFiles[this.currentFileIndex].data.days[date].availability.split('/')[0]) != 0) ? `Sei sicuro di voler spostare questa risposta?` : `L'opzione selezionata è piena, sei sicuro di voler spostare questa risposta?`)) return;
        
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
        if (Array.isArray(this.userData[userUUID].answers) && this.userData[userUUID].answers.length === 0) this.userData[userUUID].answers = {};
        if (!!this.jsonFiles[this.currentFileIndex].data.answers[userUUID]) await this.removeUserAnswer(userUUID, true);

        if (date != "Esclusi") {
            this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName] ??= [];
            let oldUserAnswerIndex = this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==date);
            if (oldUserAnswerIndex > -1) this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName][oldUserAnswerIndex] = date;
            else this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].push(date);
            if (!this.userEditList.includes(userUUID)) this.userEditList.push(userUUID);
        }

        var tmpIndex = this.currentFileIndex;
        this.currentFileIndex = -1;
        await this.updateJSON(undefined, false, true);
        this.currentFileIndex = tmpIndex;

        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
        this.jsonFiles[this.currentFileIndex].data.answers[userUUID] ??= {};
        this.jsonFiles[this.currentFileIndex].data.answers[userUUID].date = date;
        if (date != "Esclusi") this.jsonFiles[this.currentFileIndex].data.answerCount++;
        else this.jsonFiles[this.currentFileIndex].data.answers[userUUID].name = date;
        this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber = date === "Esclusi" ? "-" : this.jsonFiles[this.currentFileIndex].data.answerCount;

        if (date != "Esclusi") {
            const tmpCurrentAvailability = Number(this.jsonFiles[this.currentFileIndex].data.days[date].availability.split("/")[0]);
            const newAvailability = tmpCurrentAvailability != -1 ? tmpCurrentAvailability - 1 : tmpCurrentAvailability;
            this.jsonFiles[this.currentFileIndex].data.days[date].availability = `${(newAvailability > -2) ? newAvailability.toString() : "0"}/${this.jsonFiles[this.currentFileIndex].data.days[date].availability.split("/")[1]}`;    
        }

        await this.updateJSON();
        this.render();
        this.dashboardStayOnAnswers = true;
    }
    
    async filloutAnswers() {
        if (!confirm(`Sei sicuro di voler riempire i posti rimanenti con utenti casuali?`)) return;

        const currentSubject = this.jsonFiles[this.currentFileIndex].fileName;
        const availableUsers = Object.keys(this.userData).filter(uuid => 
            !this.jsonFiles[this.currentFileIndex].data.answers[uuid]
        );
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
    
        for (let day in this.jsonFiles[this.currentFileIndex].data.days) {
            let [current, max] = this.jsonFiles[this.currentFileIndex].data.days[day].availability.split("/").map(Number);
    
            while ((current > 0 || current == -1) && availableUsers.length > 0) {
                const randomIndex = Math.floor(Math.random() * availableUsers.length);
                const userUUID = availableUsers[randomIndex];
    
                // Add answer for this user
                if (!this.jsonFiles[this.currentFileIndex].data.answers[userUUID]) {
                    this.jsonFiles[this.currentFileIndex].data.answers[userUUID] = {};
                }
                this.jsonFiles[this.currentFileIndex].data.answers[userUUID].date = day;
                this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber = "F";

                // Update counters
                if (current != -1) current--;
                this.jsonFiles[this.currentFileIndex].data.answerCount++;
                this.jsonFiles[this.currentFileIndex].data.days[day].availability = `${current}/${max}`;
                await this.updateJSON(undefined, false, true);
    
                // Update user's answers
                if (Array.isArray(this.userData[userUUID].answers) && this.userData[userUUID].answers.length === 0) this.userData[userUUID].answers = {};
                if (!this.userData[userUUID].answers[currentSubject]) {
                    this.userData[userUUID].answers[currentSubject] = [];
                }
                this.userData[userUUID].answers[currentSubject].push(day);
                if (!this.userEditList.includes(userUUID)) this.userEditList.push(userUUID);
                var tmpIndex = this.currentFileIndex;
                this.currentFileIndex = -1;
                await this.updateJSON(undefined, false, true);
                this.currentFileIndex = tmpIndex;
    
                // Remove user from available list
                availableUsers.splice(randomIndex, 1);
            }
        }

        this.render();
    }

    getMissingAnswers(customIndex = this.currentFileIndex) {
        return (Object.keys(this.jsonFiles[customIndex].data.days).length < 1) ? [] : Object.keys(this.userData)
            .filter(e => 
                !Object.keys(this.jsonFiles[customIndex].data.answers).includes(e)
            );
    }

    async editDay(oldDate) {
        const notUseDates = this.jsonFiles[this.currentFileIndex].data.usesDays === false;
        const date = prompt(notUseDates ? "Inserisci il nome dell'opzione:" : 'Inserisci la data (DD-MM-YYYY):');
        if (date) {
            if (this.jsonFiles[this.currentFileIndex].data.days[date] && oldDate != date) {
                alert(`Questa opzione è già esistente!`);
                return await this.editDay(oldDate);
            }
            let dayName = notUseDates ? "-" : new Date(`${date.split("-")[1]}-${date.split("-")[0]}-${date.split("-")[2]}`).toLocaleString("it-IT", {weekday: "long"});
            dayName = dayName.substring(0, 1).toUpperCase() + dayName.substring(1, dayName.length);
            let availability = prompt('Quanti posti dovrebbero essere disponibili? (Ex. 3):\n(-1 = Nessun Limite)');
            if (dayName && availability) {
                if (availability.length < 1) availability = "3";
                var oldUsedSpots = this.jsonFiles[this.currentFileIndex].data.days[oldDate].availability.split("/")[1] - this.jsonFiles[this.currentFileIndex].data.days[oldDate].availability.split("/")[0];
                if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
                if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
                if ((availability < oldUsedSpots && availability != "-1") && !confirm(`La disponibilità scelta (${availability}) è più bassa dei posti occupati (${oldUsedSpots}), questo cancellerà tutte le prenotazioni per questa data. Sicuro di voler continuare?`)) return;
                else if ((availability < oldUsedSpots && availability != "-1")) {
                    await this.clearDayAnswers(oldDate, true);
                    availability = `${availability}/${availability}`;
                } else if (availability == "-1") {
                    availability = "-1/-1";
                } else {
                    availability = `${availability - oldUsedSpots}/${availability}`;
                }
                for (var answer in this.jsonFiles[this.currentFileIndex].data.answers) {
                    if (this.jsonFiles[this.currentFileIndex].data.answers[answer].date == oldDate) {
                        this.jsonFiles[this.currentFileIndex].data.answers[answer].date = date;
                    }
                }
                for (var user in this.userData) {
                    if (Array.isArray(this.userData[user].answers) && this.userData[user].answers.length === 0) this.userData[user].answers = {};
                    if (this.userData[user].answers[this.jsonFiles[this.currentFileIndex].fileName]) {
                        var index = this.userData[user].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==oldDate);
                        if (index != -1) {
                            this.userData[user].answers[this.jsonFiles[this.currentFileIndex].fileName][index] = date;
                            if (!this.userEditList.includes(user)) this.userEditList.push(user);
                        }
                    }
                }

                var tmpIndex = this.currentFileIndex;
                this.currentFileIndex = -1;
                await this.updateJSON(undefined, false, true);
                this.currentFileIndex = tmpIndex;

                this.jsonFiles[this.currentFileIndex].data.days[date] = { dayName, availability };
                if (oldDate != date) delete this.jsonFiles[this.currentFileIndex].data.days[oldDate];
                await this.updateJSON();

                this.renderDays();
                this.renderAnswers();
            }
        }
    }

    async editUser(uuid) {
        const newName = prompt(`Come vuoi rinominare ${this.userData[uuid].name}?`);
        if (newName) {
            this.userData[uuid].name = newName;
            if (!this.userEditList.includes(uuid)) this.userEditList.push(uuid);
            await this.updateJSON();
            this.render();
        }
    }

    async editSubject(customIndex = this.currentFileIndex) {
        if (customIndex < 0) return;
        const oldName = this.jsonFiles[customIndex].fileName;
        const newName = prompt(`Come vuoi rinominare ${oldName}?`);
        if (newName) {
            if (!this.isSubjectNameAvailable(newName)) {
                alert(`Questo nome è già in utilizzo!`);
                return await this.editSubject(customIndex);
            }
            await this.addFile(newName, this.jsonFiles[customIndex].data);
            let userEditCount = 0;
            for (var userUUID in this.userData) {
                if (Array.isArray(this.userData[userUUID].answers) && this.userData[userUUID].answers.length === 0) this.userData[userUUID].answers = {};
                if (!!this.userData[userUUID].answers[oldName]) {
                    userEditCount = userEditCount + 1;
                    if (!this.userEditList.includes(userUUID)) this.userEditList.push(userUUID);
                    this.userData[userUUID].answers[newName] = this.userData[userUUID].answers[oldName];
                    delete this.userData[userUUID].answers[oldName];
                }
            }
            if (userEditCount > 0) {
                var tmpIndex = this.currentFileIndex;
                this.currentFileIndex = -1;
                await this.updateJSON(undefined, false, true);
                this.render();
                this.currentFileIndex = tmpIndex;
            }
            await this.removeFile(customIndex, true);
        }
    }

    async editProfile(profile, customName) {
        const profileEntry = this.profiles.find(e=>(typeof e === "string" ? e : e.id) === profile);
        const oldName = profileEntry?.name ?? profile;
        const newName = customName ?? prompt(`Come vuoi rinominare ${oldName}?`);
        if (newName) {
            if (newName === "default" || newName === "" || this.profiles.some(e=>(e.name ?? e) === newName)) {
                alert("Questo nome non è disponibile!");
                return await this.editProfile(profile);
            }
            const r = await fetch(`${this.fetchPrefix}?scope=profileMGMT&UID=${window.UID}&class=${window.CLASS}`, {
                method: "POST",
                body: JSON.stringify({
                    action: "renameprofile",
                    profile,
                    newName
                })
            }).then(r=>r.json());
            if (!r.status) return alert('Impossibile completare l\'azione!');
            if (profileEntry) profileEntry.name = newName;
            this.profiles.sort((a,b)=>(a.name ?? a).localeCompare(b.name ?? b));
            this.renderProfiles();
        }
    }

    async fixUserDataAnswers(force = false, customIndex = this.currentFileIndex) {
        if (
            !force &&
            (
                !confirm(`Sicuro di voler eseguire una correzione forzata delle risposte?`) ||
                !confirm(`Questa azione cancellerà tutte le vecchie risposte degli utenti per questa materia!`)
            )
        ) return;
        if (customIndex < 0) return;
        if (Object.keys(this.jsonFiles[customIndex].data.answers).length === 0) return;
        
        for (var userUUID in this.userData) {
            if (Array.isArray(this.userData[userUUID].answers) && this.userData[userUUID].answers.length === 0) this.userData[userUUID].answers = {};
            this.userData[userUUID].answers[this.jsonFiles[customIndex].fileName] = [];
            if (this.jsonFiles[customIndex].data.answers[userUUID])
                this.userData[userUUID].answers[this.jsonFiles[customIndex].fileName].push(this.jsonFiles[customIndex].data.answers[userUUID].date);
            if (!this.userEditList.includes(userUUID)) this.userEditList.push(userUUID);
        }

        var tmpIndex = this.currentFileIndex;
        this.currentFileIndex = -1;
        await this.updateJSON(undefined, false, true);
        this.currentFileIndex = tmpIndex;
    }

    async fixSubjectAvailability(force = false, customIndex = this.currentFileIndex) {
        if (!force && !confirm(`Sicuro di voler provare a correggere le disponibilità delle risposte per questa materia?\nSe ci sono più prenotazioni della disponibilità, quest'ultima verrà aumentata.`)) return;
        if (customIndex < 0) return;
        
        for (var day in this.jsonFiles[customIndex].data.days) {
            let availabilityForDay = Number(this.jsonFiles[customIndex].data.days[day].availability.split("/")[1]);
            if (availabilityForDay == -1) continue;
            let currentAvailability = availabilityForDay;
            for (var answer in this.jsonFiles[customIndex].data.answers) {
                if (this.jsonFiles[customIndex].data.answers[answer].date == day) {
                    currentAvailability--;
                    if (currentAvailability < 0) {
                        availabilityForDay++;
                        currentAvailability = 0;
                    }
                }
            }
            this.jsonFiles[customIndex].data.days[day].availability = `${currentAvailability}/${availabilityForDay}`;
        }

        await this.updateJSON();
        this.render();
    }

    async deleteUser(uuid) {
        if (confirm(`Sicuro di voler cancellare ${this.userData[uuid].name}?`)) {
            delete this.userData[uuid];
            if (!this.userEditList.includes(uuid)) this.userEditList.push(uuid);
            await this.updateJSON();
            this.renderUsers();
        }
    }

    async toggleAdminUser(uuid) {
        if (confirm(this.userData[uuid].admin ? `Sicuro di voler togliere i permessi di admin da ${this.userData[uuid].name}?` : `Sicuro di voler rendere ${this.userData[uuid].name} admin?`)) {
            this.userData[uuid].admin = !this.userData[uuid].admin;
            this.userData[uuid].watcherAcc = this.userData[uuid].admin === true && !!confirm(`Vuoi rendere ${this.userData[uuid].name} un account spettatore? Verrà aggiunto agli utenti esclusi di default per ogni materia.\n(Annulla = No)`);

            if (!this.userEditList.includes(uuid)) this.userEditList.push(uuid);
            await this.updateJSON();
            this.renderUsers();
        }
        if (this.userData[uuid].watcherAcc && !!confirm(`Vuoi modificare le vecchie risposte di ${this.userData[uuid].name} per escluderlo?`)) {
            var tmpIndex = this.currentFileIndex;
            for (var i = 0; i < this.jsonFiles.length; i++) {
                this.currentFileIndex = i;
                await this.moveUserToDate(uuid, "Esclusi", true);
            }
            this.currentFileIndex = tmpIndex;
        }
    }
  
    async addFile(fileN = prompt('Inserisci il nome della materia:'), customData, fileType = "subject") {
        const fileName = fileN;
        if (fileName) {
            if (!this.isSubjectNameAvailable(fileName)) {
                alert(`Questo nome è già in utilizzo!`);
                return await this.addFile(undefined, customData);
            }
            const newFile = {
                fileName: fileName,
                data: customData ?? {
                    lock: false,
                    hide: false,
                    answerCount: 0,
                    answers: {},
                    days: {},
                    type: fileType,
                    usesDays: ["subject"].includes(fileType)
                }
            };
            this.jsonFiles.push(newFile);
            this.currentFileIndex = this.jsonFiles.length - 1;
            await this.updateJSON(newFile);

            for (var userUUID in this.userData) {
                if (this.userData[userUUID].watcherAcc === true) await this.moveUserToDate(userUUID, "Esclusi", true);
            }

            this.render();
        }
    }
  
    async removeFile(customIndex = this.currentFileIndex, force) {
        if (this.jsonFiles.length > 1 || true) { // Allow deleting all files.
            if (customIndex < 0) return alert("Non puoi cancellare questa sezione!");
            if (!force && !confirm(`Sicuro di voler cancellare ${this.jsonFiles[customIndex] ? this.jsonFiles[customIndex].fileName : "questa sezione"}?`)) return;

            this.clearSubjectAnswers(true, customIndex);

            await this.updateJSON({fileName: this.jsonFiles[customIndex] && this.jsonFiles[customIndex].fileName, data: "removed"});
            if (customIndex > -1) {
                this.jsonFiles.splice(customIndex, 1);
                this.currentFileIndex = Math.max(0, customIndex - 1);
            }
            this.render();
        } else {
            alert('Non puoi cancellare l\'ultimo file.');
        }
    }

    downloadProfile(profileName) {
        if (!confirm(`Vuoi scaricare questa classe?`)) return;
        window.open(`${this.fetchPrefix}?scope=downloadProfile&UID=${window.UID}&class=${profileName}`, "_blank");
    }
    
    uploadProfile() {
        const maxUploadBytes = 1024 * 1024;
        const form = document.createElement("form");
        const fileInput = document.createElement("input");

        fileInput.type = "file";
        fileInput.name = "profileData";
        fileInput.accept = ".zip";
        form.appendChild(fileInput);

        form.action = `${this.fetchPrefix}?scope=uploadProfile&UID=${window.UID}&class=${window.CLASS}`;
        form.method = "post";
        form.setAttribute("onsubmit", "return false")
        form.enctype = "multipart/form-data";

        fileInput.addEventListener("change", async () => {
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                if (!/\.zip$/i.test(file.name)) {
                    alert("Seleziona un profilo classe in formato ZIP.");
                    return;
                }
                if (file.size < 1 || file.size > maxUploadBytes) {
                    alert("Il profilo classe non può superare 1 MB.");
                    return;
                }
                const allowedTypes = new Set(["", "application/zip", "application/x-zip", "application/x-zip-compressed", "application/octet-stream"]);
                if (!allowedTypes.has(file.type)) {
                    alert("Il file selezionato non viene riconosciuto come archivio ZIP.");
                    return;
                }
                const formData = new FormData();
                formData.append("profileData", file);
                const response = await fetch(form.action, {method: "POST", body: formData});
                const r = await response.json().catch(()=>({status: false, message: "Risposta non valida dal server."}));
                if (!response.ok || !r.status) alert(r.message ?? "Impossibile completare l'azione!");
                else {
                    this.profiles = await this.refreshProfiles();
                    this.profiles.sort((a,b)=>(a.name ?? a).localeCompare(b.name ?? b));
                    this.renderProfiles();
                    form.remove();
                }
            }
        });

        fileInput.click();
    }

    async sendSubjectNotification(users = [], customIndex = this.currentFileIndex, data = {}) {
        if (!this.notificationClass) return alert("Le notifiche non sono state configurate correttamente!");
        if (users.length < 1) return alert("Non ci sono notifiche da inviare!");

        const result = await this.notificationClass.requestSend(users, {
            title: data.title ?? ((this.jsonFiles[customIndex].data.type ?? "subject") === "subject" ? "Nuova interrogazione!" : "Nuova votazione!"),
            tag: data.tag,
            body: data.desc ?? (
                (this.jsonFiles[customIndex].data.hide ||
                    this.jsonFiles[customIndex].data.lock) 
                    ? `Questo è un promemoria per ${(this.jsonFiles[customIndex].data.type ?? "subject") === "subject" ? "prenotarti per l'interrogazione di" : "rispondere per"} ${this.jsonFiles[customIndex].fileName}. La votazione non è ancora possibile, ma sarà attivata a breve.` :
                    `Controlla il sito, ${(this.jsonFiles[customIndex].data.type ?? "subject") === "subject" ? "c'è una nuova interrogazione per" : "è stata aperta una votazione per"} ${this.jsonFiles[customIndex].fileName} a cui non hai risposto!`
                ),
            lang: data.lang,
            badge: data.badge,
            icon: data.icon ?? `${location.href.replace(location.pathname, '').replace(location.search, '')}/images/maskable_icon_x512.png`,
            image: data.image,
            url: data.url ?? "",
            requireInteraction: data.requireInteraction ?? true,
            timestamp: data.timestamp,
            silent: data.silent ?? false,
            subject: customIndex > -1 ? this.jsonFiles[customIndex].fileName : undefined,
            vibrate: data.vibrate ?? (!data.silent ? [100, 50, 100] : undefined),
            renotify: data.renotify,
            actions: data.actions ?? [],
            urgency: data.urgency ?? "normal"
        });

        if (!result.status) return alert(result.message);
        if (result.message.total === 0) return alert("Nessuna notificha è stata inviata!");
        alert(result.message.sent == result.message.total ? "Notifiche inviate!" : `${result.message.sent} notific${result.message.sent === 1 ? "a" : "he"} inviate su ${result.message.total}!`);
        return result;
    }
  
    async updateJSON(customData, skipUpdateFunction = false, forceSkipUpdateRefresh = false) {
        customData ??= this.currentFileIndex > -1 ? this.jsonFiles[this.currentFileIndex] : this.userData;
        while (!!this.updating) {
            await new Promise((resolve, reject)=>{
                setTimeout(resolve, 100);
            })
        }
        this.updating = true;

        try {
            if (customData.data && customData.data.days) {
                if (Array.isArray(customData.data.days) && customData.data.days.length === 0) customData.data.days = {};
                if (Array.isArray(customData.data.answers) && customData.data.answers.length === 0) customData.data.answers = {};
                customData.data.days = this.sortSubjectDates(customData.data.days);
            } else {
                await this.mergeUserEdits();
                for (var usr in customData) {
                    if (Array.isArray(customData[usr].answers) && customData[usr].answers.length === 0) customData[usr].answers = {};
                    for (var subj in customData[usr].answers) {
                        customData[usr].answers[subj] = this.sortUserDates(customData[usr].answers[subj]);
                    }
                }
            }

            if (!skipUpdateFunction) await this.onJsonUpdate(this.currentFileIndex > -1 ? "subject" : "users", this.jsonFiles, customData, forceSkipUpdateRefresh);
            return [(this.currentFileIndex > -1 ? "subject" : "users"), this.jsonFiles, customData];
        } catch (error) {
            console.error('Errore durante il salvataggio della dashboard:', error);
            if (!error?.reported) alert('Il salvataggio non è riuscito. I comandi restano disponibili: riprova tra poco.');
            throw error;
        } finally {
            this.updating = false;
        }
    }
  
    close() {
        this.dashboard.remove();
    }
  
    getData() {
        return this.jsonFiles;
    }

    update(options) {
        var jsonFiles = options.subjects;
        var userData = options.users;
        var profiles = options.profiles;
        var update = options.updateCallback;
        var dataAnalysis = options.analysisFunction;
        var refreshUsers = options.refreshUsers;
        var refreshProfiles = options.refreshProfiles;
        var notificationClass = options.notificationClass;
        var fetchPrefix = options.fetchPrefix;
        var className = options.className;
        this.jsonFiles = jsonFiles || this.jsonFiles;
        this.userData = userData || this.userData;
        this.profiles = profiles || this.profiles;
        this.onJsonUpdate = update || this.onJsonUpdate || ((fullData, fileData)=>console.log('Updated JSON:', fullData, fileData));
        this.dataAnalysis = dataAnalysis || this.dataAnalysis;
        this.refreshUsers = refreshUsers || this.refreshUsers;
        this.refreshProfiles = refreshProfiles || this.refreshProfiles;
        this.notificationClass = notificationClass || this.notificationClass;
        this.fetchPrefix = fetchPrefix || this.fetchPrefix;
        this.className = className || this.className;
        if (this.currentFileIndex > this.jsonFiles.length - 1) this.currentFileIndex = -1;
        // this.dashboard.remove();
        // this.dashboard = null;
        this.render();
    }
}
