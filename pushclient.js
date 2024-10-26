// app.js - Main application code
async function initializePushNotifications() {
    // Check if service worker and push messaging is supported
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.error('Push notifications not supported');
        return;
    }

    try {
        // Register service worker
        const registration = await navigator.serviceWorker.register('push-service-worker.js');
        console.log('Service Worker registered');

        // Request notification permission
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error('Notification permission denied');
        }

        // Get push subscription
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array((await fetch(`pushapi.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({path: "/api/vapid-public-key"})
            }).then(r=>r.json())).publicKey)
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
    const response = await fetch(`pushapi.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({subscription, path: "/api/subscribe"})
    });
    return response.json();
}