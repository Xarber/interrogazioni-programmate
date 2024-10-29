class PushNotifications {
    /**
     * Initializes a PushNotifications object.
     * @param {string} identifier - Unique identifier, e.g. a username.
     */
    constructor(identifier) {
        this.id = identifier;
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

    async subscribe() {
        // Check if service worker and push messaging is supported
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.error('Push notifications not supported');
            return {status: false, userError: true, message: "Unsupported Device!"};
        }
    
        try {
            // Register service worker
            const registration = await navigator.serviceWorker.register('push-service-worker.js');
            while (!navigator.serviceWorker.controller) await new Promise(r=>setTimeout(r, 1000));
            navigator.serviceWorker.controller.postMessage({
                pathname: window.location.pathname,
                uid: new URLSearchParams(window.location.search).get('UID')
            });
            console.log('Service Worker registered');
    
            // Request notification permission
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return {status: false, userError: true, message: permission === "default" ? "Notification Permission Dialog Dismissed!" : "Notification Permission Denied!"};
                throw new Error('Notification permission denied');
            }
    
            // Get push subscription
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array((await fetch(`?${new URLSearchParams({scope: "notifications", UID: this.id}).toString()}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({action: "VAPIDkey"})
                }).then(r=>r.json())).publicKey)
            });
            
            // Send subscription to your server
            const response = await fetch(`?${new URLSearchParams({scope: "notifications", UID: this.id}).toString()}`, {
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
        if (!(await this.status())) return true;
        const subscription = (await (await navigator.serviceWorker.ready).pushManager.getSubscription());
        if (!subscription) return {status: true, message: null};

        const response = (!!sendRequestToServer) ? await fetch(`?${new URLSearchParams({scope: "notifications", UID: this.id}).toString()}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({subscription, action: "unsubscribe"})
        }).then(r=>r.json()).catch(e=>{return {status: false, message: e.toString()}}) : {status: true, message: null};
        subscription.unsubscribe();
        navigator.serviceWorker.getRegistrations().then(registrations => {
            for (const registration of registrations) {
                registration.unregister();
            } 
        });

        return response;
    }

    async update() {
        navigator.serviceWorker.getRegistrations().then(registrations => {
            for (const registration of registrations) {
                registration.update();
            } 
        });
    }

    async requestSend(users, data) {
        const response = await fetch(`?${new URLSearchParams({scope: "notifications", UID: this.id}).toString()}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({...data, users, action: "sendNotifications"})
        }).then(r=>r.json()).catch(e=>{return {status: false, message: e.toString()}});

        return response;
    }

    async status() {
        if ((await navigator.serviceWorker.getRegistrations()).length === 0) return false;
        const subscription = (await (await navigator.serviceWorker.ready).pushManager.getSubscription());
        return !!subscription;
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
                    <h2>${this.userData.name}</h2>
                    <button class="user-close-btn" title="Chiudi">&times;</button>
                </div>
                <h3>Prenotazioni</h3>
                <div class="user-dashboard-appointments">
                    ${this.renderAppointments()}
                </div>
                ${this.notificationClass && this.notificationClass.available() ? `<div class="inline">` : ""}
                    ${this.notificationClass ? `<button onclick="" id="dash-notifications-btn" ${this.notificationClass.available() ? "" : 'style="display: none"'} title="Notification Settings">Notifiche</button>` : ""}
                    ${this.userData.admin ? '<button onclick="" id="dash-admin-view-btn" title="Dashboard Admin">Dashboard</button>' : ""}
                ${this.notificationClass && this.notificationClass.available() ? `</div>` : ""}
            </div>
        `;

        this.applyStyles();
        this.attachEventListeners();
        if (!this.appended) this.container.appendChild(this.dashboard);
        this.appended = true;
        if (this.dashboard.querySelector("button#dash-admin-view-btn")) this.dashboard.querySelector("button#dash-admin-view-btn").onclick = this.userData.onOpenAdminDash;
        (async ()=>{
            if (!this.notificationClass) return;
            const status = await this.notificationClass.status();
            if (!status) document.querySelector("button#dash-notifications-btn").innerHTML = "Attiva Notifiche";
            else document.querySelector("button#dash-notifications-btn").innerHTML = "Disattiva Notifiche";
            document.querySelector("button#dash-notifications-btn").onclick = async ()=>{
                document.querySelector("button#dash-notifications-btn").innerHTML = "Attendi...";
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
            return `
                <div class="user-appointment-group">
                    <h4>${subject}</h4>
                    <ul>
                        ${dateOut.length > 0 ? dateOut : `<li>Nessun interrogazione per questa materia!</li>`}
                    </ul>
                </div>
            `;
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
        style.textContent = `
            .user-dashboard {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background-color: rgba(40, 40, 40, 0.9);
                backdrop-filter: blur(10px);
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                padding: 20px;
                width: 90%;
                min-height: 250px;
                max-width: 700px;
                max-height: calc(80vh${this.userData.admin ? ' - 40px' : ""});
                overflow-y: hidden;
                font-family: Arial, sans-serif;
                z-index: 1000;
            }
            .user-dashboard * {color: white;}
            .user-dashboard-content {
                position: relative;
                display: flex;
                flex-flow: column;
            }
            .user-dashboard-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            .user-dashboard-header h2 {
                margin: 0;
                font-size: 24px;
                color: #D7D7D8;
            }
            .user-close-btn {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #999;
                position: absolute;
                padding: 0;
                margin: 0;
                top: 0;
                right: 0;
            }
            .user-dashboard-content h3 {
                font-size: 18px;
                color: #fff;
                margin-bottom: 15px;
            }
            .user-dashboard-appointments {
                flex-grow: 1;
                max-height: calc(80vh - 140px${this.userData.admin ? ' - 20px' : ""});
                border: 1px solid gray;
                border-radius: 5px;
                padding: 10px;
                overflow-y: auto
            }
            .user-appointment-group {
                margin-bottom: 20px;
            }
            .user-appointment-group h4 {
                font-size: 16px;
                color: #DBDBDB;
                margin-bottom: 10px;
            }
            .user-appointment-group h4:first-child {
                margin-top: 0;
            }
            .user-appointment-group ul {
                list-style-type: none;
                padding: 0;
            }
            .user-appointment-group li {
                font-size: 14px;
                color: #C2C2C2;
                margin-bottom: 5px;
            }
            @media (max-width: 680px) {
                .user-dashboard {
                    width: calc(100% - 40px);
                    height: calc(100% - 40px);
                    max-width: none;
                    max-height: none;
                    top: 0;
                    left: 0;
                    transform: none;
                    border-radius: 0;
                }
                .user-dashboard-content {
                    max-height: calc(100% - 0px);
                }
                .user-dashboard-appointments {
                    max-height: calc(100% - 140px${this.userData.admin ? ' - 40px' : ""});
                }
            }
        `;
        document.head.appendChild(style);
    }
  
    attachEventListeners() {
        const closeBtn = this.dashboard.querySelector('.user-close-btn');
        closeBtn.addEventListener('click', () => this.close());
    
        // Close the dashboard when clicking outside of it
        document.addEventListener('click', (event) => {
            if (!this.dashboard.contains(event.target) && this.container === document.body) {
                this.close();
            }
        });
    }
  
    close() {
        this.container.removeChild(this.dashboard);
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
    }
  
    render() {
        this.dashboard = this.dashboard || document.createElement('div');
        this.dashboard.className = 'admin-dashboard';
        this.dashboard.innerHTML = `
            <div class="admin-dashboard-sidebar">
                <div class="admin-inline admin-dashboard-main-header">
                    <h3>Dashboard</h3>
                    <button class="admin-dashboardMenuBtn" title="Menu">
                        ${this.icons.menu}
                    </button>
                </div>
                <div class="admin-dashboard-sidebar-content">
                    <ul class="admin-json-file-list">
                        ${!this.isCustomProfile ? "" : `
                            <li preventDefault="true" onclick="if (confirm('Vuoi tornare al profilo principale?')) location.href = '?profile=default&UID='+(new URLSearchParams(location.search).get('UID'))">&lt; Esci dal profilo</li>
                        `}
                        <li data-index="-1" class="${this.currentFileIndex === -1 ? 'admin-active' : ''}">Utenti</li>
                        ${!Array.isArray(this.profiles) ? "" : `
                            <li data-index="-2" class="${this.currentFileIndex === -2 ? 'admin-active' : ''}">Profili</li>
                        `}
                        ${this.jsonFiles.map((file, index) => `
                            <li data-index="${index}" class="${index === this.currentFileIndex ? 'admin-active' : ''}">${file.fileName}</li>
                        `).join('')}
                        ${this.jsonFiles.length === 0 ? "<li data-index=\"-1\" class=\"\">Nessuna materia!</li>" : ""}
                    </ul>
                    <div class="inline admin-json-file-list-actions">
                        <button id="addFileBtn" class="admin-action-button" title="Nuovo File">
                            ${this.icons.plus}
                        </button>
                        <button id="removeFileBtn" style="background-color: red" class="admin-action-button" title="Elimina File Attuale">
                            ${this.icons.trash}
                        </button>
                    </div>
                </div>
            </div>
            <div class="admin-dashboard-content">
                <div class="admin-dashboard-header">
                    <h2 id="admin-dashboard-header-title" title="Clicca per rinominare la sezione." style="cursor: pointer;">Dashboard</h2>
                    <button class="admin-close-btn" title="Chiudi">&times;</button>
                </div>
                <div class="admin-dashboard-subject-section" data-section="${this.dashboardStayOnAnswers ? "answers" : "days"}">
                    <div class="admin-dashboard-controls">
                        <div class="admin-control-row">
                            <div class="admin-switch-container">
                                <label class="admin-switch">
                                    <input type="checkbox" id="lockSwitch">
                                    <span class="admin-slider admin-round"></span>
                                </label>
                                <span>Blocca</span>
                            </div>
                            <div class="admin-switch-container">
                                <label class="admin-switch">
                                    <input type="checkbox" id="hideSwitch">
                                    <span class="admin-slider admin-round"></span>
                                </label>
                                <span>Nascondi</span>
                            </div>
                            <div class="admin-inline admin-user-actions">
                                ${typeof this.dataAnalysis === "function" ? `
                                    <button id="copyAnswersBtn" style="background-color: dodgerblue;" class="admin-action-button" title="Copia Risposte">
                                        ${this.icons.copy}
                                    </button>
                                ` : ""}
                                <button id="editAnswersBtn" style="background-color: dodgerblue;" class="admin-action-button" title="Modifica Risposte">
                                    ${this.icons.swap}
                                </button>
                                <button id="filloutAnswersBtn" style="background-color: red;" class="admin-action-button" title="Riempi Risposte">
                                    ${this.icons.shuffle}
                                </button>
                                <button id="clearAnswersBtn" style="background-color: red;" class="admin-action-button" title="Svuota Risposte">
                                    ${this.icons.clear}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="admin-days-container">
                        <h3>Giorni</h3>
                        <div id="daysList"></div>
                        <button id="addDayBtn" class="admin-action-button" title="Aggiungi Giorno">
                            ${this.icons.plus}
                        </button>
                    </div>
                    <div class="admin-dashboard-subject-answers-section">
                        <span class="admin-dashboard-subject-answers-header clickable-span" id="editAnswersLeaveBtn">&lt; Giorni</span>
                        <div class="admin-days-container">
                            <div id="subjectAnswerList"></div>
                        </div>
                    </div>
                </div>
                <div class="admin-dashboard-user-section">
                    <div class="admin-days-container">
                        <div id="userList"></div>
                        <button id="addUserBtn" class="admin-action-button" title="Aggiungi Utente">
                            ${this.icons.plus}
                        </button>
                    </div>
                </div>
                <div class="admin-dashboard-profile-section">
                    <p>Per entrare in un profilo, clicca il suo nome.</p>
                    <div class="admin-days-container">
                        <div id="profileList"></div>
                        <div class="admin-inline inline">
                            <button id="uploadProfileBtn" class="admin-action-button" style="background-color: dodgerblue" title="Carica Profilo">
                                ${this.icons.upload}
                            </button>
                            <button id="addProfileBtn" class="admin-action-button" title="Crea Profilo">
                                ${this.icons.plus}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    
        this.applyStyles();
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
  
    updateDashboard() {
        this.dashboardStayOnAnswers ??= false;
        const useSubjects = (this.currentFileIndex > -1 && this.jsonFiles[this.currentFileIndex]);
        if (this.currentFileIndex > -1 && !this.jsonFiles[this.currentFileIndex]) this.currentFileIndex = this.jsonFiles.length - 1;
        if (this.jsonFiles.length < 1) this.currentFileIndex = -1;
        const currentFile = useSubjects ? this.jsonFiles[this.currentFileIndex] : this.userData;
        this.updateHeader();
        if (this.dashboard.querySelector('li.admin-active')) this.dashboard.querySelector('li.admin-active').classList.remove("admin-active");
        this.dashboard.querySelector(`li[data-index="${this.currentFileIndex}"]`).classList.add("admin-active");
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

            lockSwitch.checked = currentFile.data.lock;
            hideSwitch.checked = currentFile.data.hide;
            if (Object.keys(Array.isArray(currentFile.data.answers) ? {} : currentFile.data.answers).length > 0) {
                clearAnswersBtn.classList.remove("hided");
                copyAnswersBtn.classList.remove("hided");
                editAnswersBtn.classList.remove("hided");
            } else {
                clearAnswersBtn.classList.add("hided");
                copyAnswersBtn.classList.add("hided");
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
        this.dashboard.querySelector('h2#admin-dashboard-header-title').innerHTML = useSubjects ? currentFile.fileName : 
        (this.currentFileIndex === -1 ? `Utenti (${Object.keys(this.userData).length})` : `Profili (${this.profiles.length})`);
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
                <span>${date} (${dayData.dayName})</span>
                <span class="admin-availability">Posti liberi: ${dayData.availability}</span>
                <div class="admin-inline admin-user-actions">
                    <button class="admin-edit-day-btn" data-date="${date}" title="Sposta Giorno">
                        ${this.icons.edit}
                    </button>
                    ${dayData.availability.split('/')[0] < dayData.availability.split('/')[1] ? `<button class="admin-clear-day-btn" data-date="${date}" title="Svuota Risposte">
                        ${this.icons.clear}
                    </button>` : ""}
                    <button class="admin-delete-day-btn" data-date="${date}" title="Elimina Giorno">
                        ${this.icons.trash}
                    </button>
                </div>
            `;
            daysList.appendChild(dayElement);
        });
        if (objEntries.length === 0) {
            daysList.innerHTML = `
                <div class="admin-day-item">
                    <span>Non ci sono giornate d'interrogazione per questa materia!</span>
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
            userElement.innerHTML = `
                <span data-user="${userUUID}" title="Clicca per cambiare il nome utente" oldtitle="Clicca per copiare il link d'accesso dell'utente" oldonclick="if (confirm(\`Vuoi copiare un testo con il link d'accesso per ${userData.name}?\`)) {navigator.clipboard.writeText('${location.href.split('?')[0]}?UID=${userUUID}${!this.isCustomProfile ? '' : `&profile=${this.isCustomProfile}`}');alert('Il link per ${userData.name} è stato copiato!')}" style="cursor: pointer;">${userData.admin ? '[A] ' : ''}${userData.name}</span>
                <span class="admin-availability">Risposte: ${userAnswerNumber}</span>
                <div class="admin-inline admin-user-actions">
                    <button class="admin-invite-btn ${!userData.pushSubscriptions ? '' : 'admin-notify-user-btn'}" data-user="${userUUID}" title="Copia Invito">
                        ${!userData.pushSubscriptions ? this.icons.invite : this.icons.notification}
                    </button>
                    <button class="admin-admin-btn" data-user="${userUUID}" title="Rendi/Rimuovi Admin">
                        ${this.icons.admin}
                    </button>
                    <button class="admin-delete-day-btn" data-user="${userUUID}" title="Elimina Utente">
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
            const profileElement = document.createElement('div');
            profileElement.className = 'admin-day-item';
            profileElement.innerHTML = `
                <span title="Clicca per spostarti su questo profilo" onclick="if (confirm('Sei sicuro di voler cambiare il profilo su ${e}?')) location.href = location.href.split('?')[0]+'?profile=${e}&UID='+(new URLSearchParams(location.search).get('UID'));">${e}</span>
                <div class="admin-inline admin-user-actions">
                    <button class="admin-download-file-btn" data-profile="${e}" title="Scarica Profilo">
                        ${this.icons.download}
                    </button>
                    <button class="admin-edit-day-btn" data-profile="${e}" title="Rinomina Profilo">
                        ${this.icons.edit}
                    </button>
                    <button class="admin-delete-day-btn" data-profile="${e}" title="Elimina Profilo">
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
        const missingUsers = this.getMissingAnswers();
        if (missingUsers.length > 0) answerList.innerHTML += `<div class="admin-inline inline">
            <h2 style="flex: 1;">In attesa di risposta</h2>
            <button class="admin-edit-day-btn admin-notify-all-btn" data-user="unset" style="margin: 20px 10px;height: 100%;">
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
                    <button class="admin-edit-day-btn admin-notify-user-btn ${!this.userData[userUUID].pushSubscriptions ? 'admin-disabled' : ''}" ${!this.userData[userUUID].pushSubscriptions ? 'disabled' : ''} data-user="${userUUID}" title="Invia Notifica">
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
                const answerElement = document.createElement('div');
                answerElement.className = 'admin-day-item';
                answerElement.innerHTML = `
                    <span>[${answerData.answerNumber}] ${this.userData[UUID].name}</span>
                    <span class="admin-availability">${answerData.date}</span>
                    <div class="admin-inline admin-user-actions">
                        <button class="admin-edit-day-btn" data-user="${UUID}" title="Modifica Risposta">
                            ${this.icons.swap}
                        </button>
                        <button class="admin-delete-day-btn" data-user="${UUID}" title="Elimina Risposta">
                            ${this.icons.trash}
                        </button>
                    </div>
                `;
                answerList.appendChild(answerElement);
                dayDividedAnswers[day] = dayDividedAnswers[day] || [];
                dayDividedAnswers[day].push(...dayData);
            })
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
            .admin-swapping-user-answer > .admin-day-item {
                transition: none;
                border: 2px solid transparent;
            }
            .admin-swapping-user-answer > .admin-day-item .admin-delete-day-btn {
                display: none;
            }
            .admin-current-swapping-element {
                background-color: rgba(100, 100, 100, 0.5) !important;
            }
            .admin-day-item:has(.admin-current-swapping-element), .admin-swapping-user-answer > .admin-day-item:hover {
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
            .admin-delete-day-btn, .admin-clear-day-btn, .admin-edit-day-btn, .admin-download-file-btn, .admin-admin-btn, .admin-invite-btn {
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
        document.head.appendChild(style);
    }
  
    attachEventListeners() {
        const closeBtn = this.dashboard.querySelector('.admin-close-btn');
        closeBtn.addEventListener('click', () => this.close());
    
        const dashHeader = this.dashboard.querySelector(`h2#admin-dashboard-header-title`);
        dashHeader.addEventListener('click', async ()=>{
            await this.editSubject();
        });

        const dashMenuBTN = this.dashboard.querySelector('.admin-dashboardMenuBtn');
        dashMenuBTN.addEventListener('click', ()=>{
            this.dashboard.querySelector(".admin-dashboard-sidebar").classList.toggle("extend");
        });

        const lockSwitch = this.dashboard.querySelector('#lockSwitch');
        lockSwitch.addEventListener('change', async (e) => {
            await new Promise((resolve) => setTimeout(async () => {
                this.jsonFiles[this.currentFileIndex].data.lock = e.target.checked;
                await this.updateJSON();
                resolve();
            }, 500));
        });
    
        const hideSwitch = this.dashboard.querySelector('#hideSwitch');
        hideSwitch.addEventListener('change', async (e) => {
            await new Promise((resolve) => setTimeout(async () => {
                this.jsonFiles[this.currentFileIndex].data.hide = e.target.checked;
                await this.updateJSON();
                resolve();
            }, 500));
        });
    
        const clearAnswersBtn = this.dashboard.querySelector('#clearAnswersBtn');
        clearAnswersBtn.addEventListener('click', async () => {
            await this.clearSubjectAnswers();
        });

        const filloutAnswersBtn = this.dashboard.querySelector('#filloutAnswersBtn');
        filloutAnswersBtn.addEventListener('click', async () => {
            await this.filloutAnswers();
        });

        if (typeof this.dataAnalysis === "function") {
            const copyAnswersBtn = this.dashboard.querySelector('#copyAnswersBtn');
            copyAnswersBtn.addEventListener('click', () => {
                this.dataAnalysis({
                    clipboard: true, 
                    copy: "prenotazioni", 
                    log: false,
                    data: this.jsonFiles[this.currentFileIndex].data,
                    subject: this.jsonFiles[this.currentFileIndex].fileName,
                    users: this.userData
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

        const addProfileBtn = this.dashboard.querySelector('#addProfileBtn');
        addProfileBtn.addEventListener('click', async () => await this.addProfile());

        const uploadProfileBtn = this.dashboard.querySelector('#uploadProfileBtn');
        uploadProfileBtn.addEventListener('click', () => this.uploadProfile());
    
        const daysList = this.dashboard.querySelector('#daysList');
        daysList.addEventListener('click', async (e) => {
            let target = e.target.dataset.date ? e.target : e.target.parentNode;
            target = target.dataset.date ? target : target.parentNode;
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
            let target = e.target.dataset.user ? e.target : e.target.parentNode;
            target = target.dataset.user ? target : target.parentNode;
            if (target.tagName.toLowerCase() == "span") {
                await this.editUser(target.dataset.user);
            }
            if (target.classList.contains('admin-delete-day-btn')) {
                await this.deleteUser(target.dataset.user);
            }
            if (target.classList.contains('admin-admin-btn')) {
                await this.toggleAdminUser(target.dataset.user);
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
                navigator.clipboard.writeText(`Ciao, ${name[name.length - 1]}!\nQuesto è il tuo link di accesso per la pagina delle prenotazioni delle interrogazioni programmate:\n${location.href.split('?')[0]}?UID=${target.dataset.user}${!this.isCustomProfile ? '' : `&profile=${this.isCustomProfile}`}\nNON CONDIVIDERLO ALTRIMENTI DARAI IL TUO ACCESSO AD ALTRE PERSONE!\nNon perdere troppo tempo a rispondere siccome i posti sono limitati!`);
                alert(`Il testo con il link d'accesso di ${name.join(" ")} è stato copiato!`);
            }
        });

        const answerList = this.dashboard.querySelector('#subjectAnswerList');
        answerList.addEventListener('click', async (e) => {
            let target = e.target.dataset.user ? e.target : e.target.parentNode;
            target = target.dataset.user ? target : target.parentNode;
            if (target.classList.contains('admin-notify-all-btn')) {
                await this.sendSubjectNotification(this.getMissingAnswers());
            } else if (target.classList.contains('admin-notify-user-btn')) {
                if (target.classList.contains("admin-disabled")) return alert("Questo utente non ha attivato le notifiche!");
                await this.sendSubjectNotification([target.dataset.user]);
            } else if (target.classList.contains('admin-edit-day-btn')) {
                this.dashboard.querySelector('#subjectAnswerList').classList.toggle("admin-swapping-user-answer");
                if (this.dashboard.querySelector('#subjectAnswerList').classList.contains("admin-swapping-user-answer")) 
                    target.classList.add("admin-current-swapping-element");
                else {
                    const firstUserElement = this.dashboard.querySelector('#subjectAnswerList').querySelector(".admin-current-swapping-element");
                    const user2UUID = firstUserElement.dataset.user;
                    firstUserElement.classList.remove("admin-current-swapping-element");
                    if (user2UUID != target.dataset.user) await this.swapUserAnswer(target.dataset.user, user2UUID);
                }
            }
            if (target.classList.contains('admin-delete-day-btn')) {
                await this.removeUserAnswer(target.dataset.user);
            }
        });

        const profileList = this.dashboard.querySelector('#profileList');
        profileList.addEventListener('click', async (e) => {
            let target = e.target.dataset.profile ? e.target : e.target.parentNode;
            target = target.dataset.profile ? target : target.parentNode;
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
            if (e.target.tagName === 'LI' && !e.target.dataset.preventDefault) {
                this.currentFileIndex = parseInt(e.target.dataset.index);
                fileList.querySelectorAll('li').forEach(li => li.classList.remove('admin-active'));
                e.target.classList.add('admin-active');
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
  
    async addDay() {
        const date = prompt('Inserisci la data (DD-MM-YYYY):');
        if (date) {
            if (this.jsonFiles[this.currentFileIndex].data.days[date]) {
                alert(`Questa data è già esistente!`);
                return await this.addDay();
            }
            const formattedDate = `${date.split("-")[1]}/${date.split("-")[0]}/${date.split("-")[2]}`;
            let dayName = (new Date(formattedDate)).toLocaleString("it-IT", {weekday: "long"});
            dayName = dayName.substring(0, 1).toUpperCase() + dayName.substring(1, dayName.length);
            let availability = prompt('Quanti posti dovrebbero essere disponibili? (Ex. 3):');
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
        const profile = customName ?? prompt(`Inserisci il nome del profilo:`);
        if (profile) {
            if (profile === "default" || profile === "" || this.profiles.includes(profile)) {
                alert("Questo nome non è disponibile!");
                return await this.editProfile(profile);
            }
            const r = await fetch('?scope=profileMGMT&UID='+(new URLSearchParams(location.search).get("UID")), {
                method: "POST",
                body: JSON.stringify({
                    action: "newprofile",
                    method: confirm("Vuoi importare i dati da questo profilo? (Annulla = No)") ? "import" : "new",
                    profile
                })
            }).then(r=>r.json());
            if (!r.status) return alert('Impossibile completare l\'azione!');
            this.profiles.push(profile);
            this.profiles.sort();
            this.renderProfiles();
        }
    }

    async deleteProfile(profile, force = false) {
        if (profile === "default" || profile === "" || !this.profiles.includes(profile)) return alert("Non puoi cancellare questo profilo!");
        if (!force && !confirm(`Sicuro di voler eliminare il profilo ${profile}?`)) return;
        const r = await fetch('?scope=profileMGMT&UID='+new URLSearchParams(location.search).get("UID"), {
            method: "POST",
            body: JSON.stringify({
                action: "deleteprofile",
                profile
            })
        }).then(r=>r.json());
        if (!r.status) return alert('Impossibile completare l\'azione!');
        this.profiles.splice(this.profiles.indexOf(profile), 1);
        this.profiles.sort();
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
        if (!this.userData[userUUID]) return alert(`Questo utente non esiste!`);
        if (!this.jsonFiles[this.currentFileIndex].data.answers[userUUID]) return alert(`Questa risposta non esiste!`);
        if (!force && !confirm(`Sei sicuro di voler rimuovere questa risposta?`)) return;
        
        const day = this.jsonFiles[this.currentFileIndex].data.answers[userUUID].date;
        const answerPriority = this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber;
        delete this.jsonFiles[this.currentFileIndex].data.answers[userUUID];
        
        this.jsonFiles[this.currentFileIndex].data.answerCount = this.jsonFiles[this.currentFileIndex].data.answerCount - 1;
        this.jsonFiles[this.currentFileIndex].data.days[day].availability = `${Number(this.jsonFiles[this.currentFileIndex].data.days[day].availability.split("/")[0]) + 1}/${this.jsonFiles[this.currentFileIndex].data.days[day].availability.split("/")[1]}`;
        
        if (this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName]) {
            var index = this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==day);
            if (index != -1) {
                this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].splice(index, 1);
                if (!this.userEditList.includes(userUUID)) this.userEditList.push(userUUID);
            }
        }

        const objEntries = Object.entries(this.jsonFiles[this.currentFileIndex].data.answers);
        objEntries.forEach(([userUUID, userData]) => {
            if (userData.answerNumber > answerPriority) this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber = userData.answerNumber - 1;
        });

        var tmpIndex = this.currentFileIndex;
        this.currentFileIndex = -1;
        await this.updateJSON(undefined, false, true);
        this.currentFileIndex = tmpIndex;

        await this.updateJSON();
        this.render();
        this.dashboardStayOnAnswers = true;
    }

    async swapUserAnswer(user1UUID, user2UUID) {
        if (!this.userData[user1UUID]) return alert(`Questo utente non esiste!`);
        if (!this.userData[user2UUID]) return alert(`Questo utente non esiste!`);
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

    async moveUserToDate(userUUID, date) {
        if (!this.userData[userUUID]) return alert(`Questo utente non esiste!`);
        if (!confirm(Number(this.jsonFiles[this.currentFileIndex].data.days[date].availability.split('/')[0]) > 0 ? `Sei sicuro di voler spostare questa risposta?` : `Il giorno selezionato è pieno, sei sicuro di voler spostare questa risposta?`)) return;
        
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
        if (!!this.jsonFiles[this.currentFileIndex].data.answers[userUUID]) await this.removeUserAnswer(userUUID, true);
        
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
        if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
        this.jsonFiles[this.currentFileIndex].data.answers[userUUID] ??= {};
        this.jsonFiles[this.currentFileIndex].data.answers[userUUID].date = date;
        this.jsonFiles[this.currentFileIndex].data.answerCount++;
        this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber = this.jsonFiles[this.currentFileIndex].data.answerCount;
        
        const newAvailability = Number(this.jsonFiles[this.currentFileIndex].data.days[date].availability.split("/")[0]) - 1;
        this.jsonFiles[this.currentFileIndex].data.days[date].availability = `${newAvailability > -1 ? newAvailability.toString() : "0"}/${this.jsonFiles[this.currentFileIndex].data.days[date].availability.split("/")[1]}`;

        this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName] ??= [];
        let oldUserAnswerIndex = this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].findIndex(e=>e==date);
        if (oldUserAnswerIndex > -1) this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName][oldUserAnswerIndex] = date;
        else this.userData[userUUID].answers[this.jsonFiles[this.currentFileIndex].fileName].push(date);
        if (!this.userEditList.includes(userUUID)) this.userEditList.push(userUUID);

        var tmpIndex = this.currentFileIndex;
        this.currentFileIndex = -1;
        await this.updateJSON(undefined, false, true);
        this.currentFileIndex = tmpIndex;

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
    
            while (current > 0 && availableUsers.length > 0) {
                const randomIndex = Math.floor(Math.random() * availableUsers.length);
                const userUUID = availableUsers[randomIndex];
    
                // Add answer for this user
                if (!this.jsonFiles[this.currentFileIndex].data.answers[userUUID]) {
                    this.jsonFiles[this.currentFileIndex].data.answers[userUUID] = {};
                }
                this.jsonFiles[this.currentFileIndex].data.answers[userUUID].date = day;
                this.jsonFiles[this.currentFileIndex].data.answers[userUUID].answerNumber = "F";

                // Update counters
                current--;
                this.jsonFiles[this.currentFileIndex].data.answerCount++;
                this.jsonFiles[this.currentFileIndex].data.days[day].availability = `${current}/${max}`;
                await this.updateJSON(undefined, false, true);
    
                // Update user's answers
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
        const date = prompt('Inserisci la data (DD-MM-YYYY):');
        if (date) {
            if (this.jsonFiles[this.currentFileIndex].data.days[date] && oldDate != date) {
                alert(`Questa data è già esistente!`);
                return await this.editDay(oldDate);
            }
            let dayName = new Date(`${date.split("-")[1]}-${date.split("-")[0]}-${date.split("-")[2]}`).toLocaleString("it-IT", {weekday: "long"});
            dayName = dayName.substring(0, 1).toUpperCase() + dayName.substring(1, dayName.length);
            let availability = prompt('Quanti posti dovrebbero essere disponibili? (Ex. 3):');
            if (dayName && availability) {
                if (availability.length < 1) availability = "3";
                var oldUsedSpots = this.jsonFiles[this.currentFileIndex].data.days[oldDate].availability.split("/")[1] - this.jsonFiles[this.currentFileIndex].data.days[oldDate].availability.split("/")[0];
                if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.days) && this.jsonFiles[this.currentFileIndex].data.days.length === 0) this.jsonFiles[this.currentFileIndex].data.days = {};
                if (Array.isArray(this.jsonFiles[this.currentFileIndex].data.answers) && this.jsonFiles[this.currentFileIndex].data.answers.length === 0) this.jsonFiles[this.currentFileIndex].data.answers = {};
                if (availability < oldUsedSpots && !confirm(`La disponibilità scelta (${availability}) è più bassa dei posti occupati (${oldUsedSpots}), questo cancellerà tutte le prenotazioni per questa data. Sicuro di voler continuare?`)) return;
                else if (availability < oldUsedSpots) {
                    await this.clearDayAnswers(oldDate, true);
                    availability = `${availability}/${availability}`;
                } else {
                    availability = `${availability - oldUsedSpots}/${availability}`;
                }
                for (var answer in this.jsonFiles[this.currentFileIndex].data.answers) {
                    if (this.jsonFiles[this.currentFileIndex].data.answers[answer].date == oldDate) {
                        this.jsonFiles[this.currentFileIndex].data.answers[answer].date = date;
                    }
                }
                for (var user in this.userData) {
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
        const newName = customName ?? prompt(`Come vuoi rinominare ${profile}?`);
        if (newName) {
            if (newName === "default" || newName === "" || this.profiles.includes(newName)) {
                alert("Questo nome non è disponibile!");
                return await this.editProfile(profile);
            }
            const r = await fetch('?scope=profileMGMT&UID='+(new URLSearchParams(location.search).get("UID")), {
                method: "POST",
                body: JSON.stringify({
                    action: "renameprofile",
                    profile,
                    newName
                })
            }).then(r=>r.json());
            if (!r.status) return alert('Impossibile completare l\'azione!');
            this.profiles.splice(this.profiles.indexOf(profile), 1);
            this.profiles.push(newName);
            this.profiles.sort();
            this.renderProfiles();
        }
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
            if (!this.userEditList.includes(uuid)) this.userEditList.push(uuid);
            await this.updateJSON();
            this.renderUsers();
        }
    }
  
    async addFile(fileN = prompt('Inserisci il nome della materia:'), customData) {
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
                    days: {}
                }
            };
            this.jsonFiles.push(newFile);
            this.currentFileIndex = this.jsonFiles.length - 1;
            await this.updateJSON(newFile);
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
        if (!confirm(`Vuoi scaricare il profilo ${profileName}?`)) return;
        window.open("?scope=downloadProfile&UID="+(new URLSearchParams(location.search).get("UID"))+"&profileName="+profileName, "_blank");
    }
    
    uploadProfile() {
        const form = document.createElement("form");
        const fileInput = document.createElement("input");

        fileInput.type = "file";
        fileInput.name = "profileData";
        fileInput.accept = ".zip";
        form.appendChild(fileInput);

        form.action = "?scope=uploadProfile&UID="+(new URLSearchParams(location.search).get("UID"));
        form.method = "post";
        form.setAttribute("onsubmit", "return false")
        form.enctype = "multipart/form-data";

        fileInput.addEventListener("change", async () => {
            if (fileInput.files.length > 0) {
                const formData = new FormData();
                formData.append("profileData", fileInput.files[0]);
                const r = await fetch(form.action, {
                    method: "POST",
                    body: formData
                }).then(r=>r.json());
                if (!r.status) alert("Impossibile completare l'azione!");
                else {
                    this.profiles = await this.refreshProfiles();
                    this.profiles.sort();
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
            title: data.title ?? "Nuova interrogazione!",
            tag: data.tag,
            body: data.desc ?? `Controlla il sito, c'è una nuova interrogazione per ${this.jsonFiles[customIndex].fileName} a cui non hai risposto!`,
            lang: data.lang,
            badge: data.badge,
            icon: data.icon ?? "",
            image: data.image,
            url: data.url ?? "",
            requireInteraction: data.requireInteraction ?? true,
            timestamp: data.timestamp,
            silent: data.silent ?? false,
            vibrate: data.vibrate ?? (!data.silent ? [100, 50, 100] : undefined),
            renotify: data.renotify,
            actions: data.actions ?? [],
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

        // Here you would typically send the updated JSON to the server
        if (customData.data && customData.data.days) {
            if (Array.isArray(customData.data.days) && customData.data.days.length === 0) customData.data.days = {};
            if (Array.isArray(customData.data.answers) && customData.data.answers.length === 0) customData.data.answers = {};
            customData.data.days = this.sortSubjectDates(customData.data.days);
        } else {
            await this.mergeUserEdits();
            for (var usr in customData) {
                for (var subj in customData[usr].answers) {
                    customData[usr].answers[subj] = this.sortUserDates(customData[usr].answers[subj]);
                }
            }
        }
        
        if (!skipUpdateFunction) this.onJsonUpdate(this.currentFileIndex > -1 ? "subject" : "users", this.jsonFiles, customData, forceSkipUpdateRefresh);
        this.updating = false;

        return [(this.currentFileIndex > -1 ? "subject" : "users"), this.jsonFiles, customData];
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
        this.jsonFiles = jsonFiles || this.jsonFiles;
        this.userData = userData || this.userData;
        this.profiles = profiles || this.profiles;
        this.onJsonUpdate = update || this.onJsonUpdate || ((fullData, fileData)=>console.log('Updated JSON:', fullData, fileData));
        this.dataAnalysis = dataAnalysis || this.dataAnalysis;
        this.refreshUsers = refreshUsers || this.refreshUsers;
        this.refreshProfiles = refreshProfiles || this.refreshProfiles;
        this.notificationClass = notificationClass || this.notificationClass;
        if (this.currentFileIndex > this.jsonFiles.length - 1) this.currentFileIndex = -1;
        // this.dashboard.remove();
        // this.dashboard = null;
        this.render();
    }
}