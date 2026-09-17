self.addEventListener('activate', event => {
    event.waitUntil(Promise.all([
        clients.claim(),
        caches.keys().then(keys => Promise.all(keys.filter(key => key !== 'pwa-cache-v6').map(key => caches.delete(key))))
    ]));
});

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open("pwa-cache-v6").then(cache => {
            return cache.addAll([
                //'/',
                '/interrogazioni.php',
                '/assets/app.css?v=6',
                '/assets/dash.js?v=6',
                '/assets/manifest.php',
                //'/push-service-worker.js',
                //'/manager.php?scope=loadPageData'
            ]);
        })
    );
});

self.addEventListener('fetch', event => {
    event.respondWith(
        fetch(event.request).then(response => {
            // Don't cache if not a valid response
            if (!response || response.status !== 200 || response.type !== 'basic') {
                return response;
            }
            
            // Clone the response because it can only be used once
            console.log(event.request, event.request.method);
            if (event.request.method.toUpperCase() == "GET") {
                console.log("Cached response");
                const responseToCache = response.clone();
                
                caches.open("pwa-cache-v6").then(cache => {
                    try {cache.put(event.request, responseToCache);}
                    catch(e) {console.warn("Failed to cache response.", e)}
                });
            }
            
            return response;
        }).catch(async e=>{
            console.warn("Fetch error encountered, returning cached response.", e);
            return (await caches.match(event.request)) || Response.error();
        })
        
    );
});

self.addEventListener('message', event => {
    const data = event.data;
  
    // Open an IndexedDB database
    const request = indexedDB.open('ServiceWorkerDB', 1);
  
    request.onupgradeneeded = event => {
        const db = event.target.result;
        db.createObjectStore('pathUidStore', { keyPath: 'id' });
    };
  
    request.onsuccess = event => {
        const db = event.target.result;
        const tx = db.transaction('pathUidStore', 'readwrite');
        const store = tx.objectStore('pathUidStore');
        store.put({ id: '1', pathname: data.pathname, uid: data.uid, classId: data.classId });
    };
  
    request.onerror = () => {
        console.error('IndexedDB error');
    };
});

self.addEventListener('push', function(event) {
    if (!event.data) return;

    const data = event.data.json();
    const postData = JSON.stringify({type: "push", data: data});
    let options = data;
    options.requireInteraction ??= false;
    options.silent ??= false;
    options.actions ??= [];

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );

    if (typeof postMessage === "function") postMessage(postData);
    else {
        clients.matchAll({type: "window"}).then(cl=>{
            for (var c of cl) c.postMessage(postData);
        });
        console.log(JSON.parse(postData));
    }
});

self.addEventListener('notificationclick', function(notificationEvent) {
    notificationEvent.notification.close();

    notificationEvent.waitUntil(
        Promise.all([
            clients.matchAll({ type: "window", includeUncontrolled: true }),
            new Promise((resolve) => {
            const request = indexedDB.open('ServiceWorkerDB', 1);
            request.onerror = () => resolve({});
            request.onupgradeneeded = () => resolve({});
            request.onsuccess = event => {
                try {
                    const db = event.target.result;
                    const getRequest = db.transaction('pathUidStore', 'readonly').objectStore('pathUidStore').get('1');
                    getRequest.onerror = () => resolve({});
                    getRequest.onsuccess = () => resolve(getRequest.result || {});
                } catch (_) {
                    resolve({});
                }
            };
        })]).then(([clientList, saved]) => {
            const notificationData = notificationEvent.notification.data || {};
            const target = new URL(notificationData.url || saved.pathname || '/interrogazioni.php', self.location.origin);
            if (!target.searchParams.has('UID') && saved.uid) target.searchParams.set('UID', saved.uid);
            const classId = notificationData.classId || saved.classId;
            if (classId) target.searchParams.set('class', classId);
            if (notificationData.subject) target.searchParams.set('subject', notificationData.subject);

            const matchingClient = clientList.find(client => new URL(client.url).pathname === target.pathname);
            if (matchingClient && 'focus' in matchingClient) {
                if ('navigate' in matchingClient) return matchingClient.navigate(target.href).then(() => matchingClient.focus());
                return matchingClient.focus();
            }
            return clients.openWindow ? clients.openWindow(target.href) : undefined;
        })
    );
});
