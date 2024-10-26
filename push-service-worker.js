self.addEventListener('install', event => {
    event.waitUntil(
        caches.open('v1').then(cache => {
            return cache.addAll([]);
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
        store.put({ id: '1', pathname: data.pathname, uid: data.uid });
    };
  
    request.onerror = () => {
        console.error('IndexedDB error');
    };
});
  

self.addEventListener('push', function(event) {
    if (!event.data) return;

    const data = event.data.json();
    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        data: data.data,
        requireInteraction: data.requireInteraction || false,
        silent: data.silent || false,
        timestamp: data.timestamp || new Date().getTime(),
        actions: data.actions || [],
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
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
                        const toOpenUrl = notificationEvent.notification.data.url || `${self.pathname ?? "/"}?UID=${self.uid ?? ""}`;
                        for (const client of clientList) {
                            if (client.url === toOpenUrl && "focus" in client) return client.focus();
                        }
                        if (clients.openWindow) return clients.openWindow(toOpenUrl);
                    }
                };
            };
        }),
    );
});