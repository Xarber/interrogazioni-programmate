// app.js - Main application code
async function initializePushNotifications() {
    // Check if service worker and push messaging is supported
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.error('Push notifications not supported');
        return;
    }

    try {
        // Register service worker
        const registration = await navigator.serviceWorker.register('service-worker.js');
        console.log('Service Worker registered');

        // Request notification permission
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error('Notification permission denied');
        }

        // Get push subscription
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array((await fetch('/api/vapid-public-key').then(r=>r.json())).publicKey)
        });

        console.log('Push Subscription:', subscription);
        
        // Send subscription to your server
        await sendSubscriptionToServer(subscription);
    } catch (error) {
        console.error('Error setting up push notifications:', error);
    }
}

// Helper function to convert VAPID key
function urlBase64ToUint8Array(base64String) {
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

async function sendSubscriptionToServer(subscription) {
    // Implementation to send subscription to your backend
    const response = await fetch('/api/subscribe', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(subscription)
    });
    return response.json();
}

// service-worker.js
self.addEventListener('push', function(event) {
    if (!event.data) return;

    const data = event.data.json();
    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        data: data.data
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    
    // Handle notification click
    event.waitUntil(
        clients.openWindow(event.notification.data.url || '/')
    );
});