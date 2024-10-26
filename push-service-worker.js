self.addEventListener('message', event => {
    const data = event.data;
  
    // Save pathname and UID for future launches
    self.pathname = data.pathname;
    self.uid = data.uid;
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

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const toOpenUrl = event.notification.data.url || `${global.pathname ?? "/"}?UID=${global.uid ?? ""}`;
    
    // Handle notification click
    event.waitUntil(
        clients
        .matchAll({
            type: "window",
        })
        .then((clientList) => {
            for (const client of clientList) {
                if (client.url === toOpenUrl && "focus" in client) return client.focus();
            }
            if (clients.openWindow) return clients.openWindow(toOpenUrl);
        }),
    );
});