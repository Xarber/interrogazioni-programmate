const CACHE_NAME = 'pwa-cache-v1';
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll([
                '/',
                '/client.html',
                '/assets/app.css',
                '/assets/dash.js',
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
            if (event.request.method === "GET") {
                console.log("Cached response");
                const responseToCache = response.clone();
                
                caches.open(CACHE_NAME).then(cache => {
                    cache.put(event.request, responseToCache);
                });
            }
            
            return response;
        }).catch(e=>{
            caches.match(event.request).then(response => {
                console.warn("Fetch error encountered, returning cached response.", e);
                // Return cached response if found
                if (response) return response;
                
                return e;
            })
        })
        
    );
});

self.addEventListener('activate', event => {
    clients.claim();
    console.log('Service Worker Ready!');
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
        store.put({ id: '1', pathname: data.pathname, uid: data.uid });
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
    
    // Handle notification click
    notificationEvent.waitUntil(
        clients
        .matchAll({
            type: "window",
        })
        .then((clientList) => {
            const request = indexedDB.open('ServiceWorkerDB', 1);
            return new Promise((resolve)=>{
                request.onsuccess = event => {
                    const db = event.target.result;
                    const tx = db.transaction('pathUidStore', 'readonly');
                    const store = tx.objectStore('pathUidStore');
                    const getRequest = store.get('1');
            
                    getRequest.onsuccess = () => {
                        const data = getRequest.result;
                        if (data) {
                            self.pathname = data.pathname;
                            self.uid = data.uid;
                            let toOpenUrl = notificationEvent.notification.data.url || `${self.pathname ?? "/"}?UID=${self.uid ?? ""}`;
                            if (!!notificationEvent.notification.data.subject) toOpenUrl += `${toOpenUrl.indexOf('?') != -1 ? '&' : '?'}subject=${notificationEvent.notification.data.subject}`;
                            for (const client of clientList) {
                                if (client.url === toOpenUrl && "focus" in client) return client.focus();
                            }
                            if (clients.openWindow) return clients.openWindow(toOpenUrl);
                        }
                        resolve();
                    };
                };
            });
        }),
    );
});