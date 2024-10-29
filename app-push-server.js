// server.js
const http = require('http');
const path = require('path');
const fsSync = require('fs');
const fs = fsSync.promises;
const webpush = require('web-push');

// Generate VAPID keys (do this once and save the keys)
const vapidKeys = fsSync.existsSync("vapidkeys.json") ? JSON.parse(fsSync.readFileSync("vapidkeys.json")) : (()=>{
    const keys = webpush.generateVAPIDKeys();
    fsSync.writeFileSync("vapidkeys.json", JSON.stringify(keys));
    return keys;
})();


// Configure web-push with your VAPID keys
webpush.setVapidDetails(
    'mailto:your@email.com',
    vapidKeys.publicKey,
    vapidKeys.privateKey
);

// Store subscriptions (in a real app, use a file or database)
const subscriptions = new Set();

// Simple request body parser
async function parseBody(req) {
    return new Promise((resolve, reject) => {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('error', reject);
        req.on('end', () => {
            try {
                resolve(JSON.parse(body));
            } catch (e) {
                reject(e);
            }
        });
    });
}

// Send JSON response helper
function sendJSON(res, data, status = 200) {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(data));
}

// Send push notification
async function sendPushNotification(subscription, data, options) {
    try {
        await webpush.sendNotification(subscription, JSON.stringify(data), options);
        return true;
    } catch (error) {
        console.error('Error sending push notification:', error.body);
        if (error.statusCode === 410) {
            subscriptions.delete(subscription);
        }
        return false;
    }
}

// Create HTTP server
const server = http.createServer(async (req, res) => {
    try {
        // Basic CORS headers
        res.setHeader('Access-Control-Allow-Origin', '*');
        res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

        // Handle preflight requests
        if (req.method === 'OPTIONS') {
            return sendJSON(res, {});
        }

        // Route handling
        if (req.url === '/api/vapid-public-key') {
            return sendJSON(res, { status: true, publicKey: vapidKeys.publicKey });
        }

        if (req.url === '/api/subscribe') {
            const subscription = await parseBody(req);
            subscriptions.add(subscription);
            return sendJSON(res, { status: true, message: 'Subscription saved' }, 201);
        }

        if (req.url === "/api/unsubscribe") {
            const subscription = await parseBody(req);
            subscriptions.delete(subscription);
            return sendJSON(res, { status: true, message: 'Subscription removed' }, 201);
        }

        if (req.url === '/api/send-notification') {
            const bodyData = await parseBody(req);
            const data = {
                title: bodyData.title,
                tag: bodyData.tag,
                body: bodyData.body,
                lang: bodyData.lang,
                icon: bodyData.icon,
                image: bodyData.image,
                badge: bodyData.badge,
                requireInteraction: bodyData.requireInteraction,
                silent: bodyData.silent,
                vibrate: bodyData.vibrate,
                renotify: bodyData.renotify,
                timestamp: bodyData.timestamp ?? new Date().getTime(),
                actions: bodyData.actions ?? [],

                url: bodyData.url,
                urgency: bodyData.urgency ?? "normal",
                subscriptions: bodyData.subscriptions
            };
            const notificationData = {
                title: data.title ?? 'New Notification',
                tag: data.tag,
                body: data.body ?? 'Open the website to read',
                lang: data.lang,
                icon: data.icon ?? '/icon.png',
                image: data.image,
                badge: data.badge,
                data: {
                    url: data.url
                },
                requireInteraction: data.requireInteraction ?? false,
                silent: data.silent ?? false,
                vibrate: data.vibrate,
                renotify: data.renotify,
                timestamp: data.timestamp ?? new Date().getTime(),
                priority: data.urgency,
                urgency: data.urgency,
                importance: data.urgency,
                actions: data.actions ?? [],
            };
            const options = {
                urgency: data.urgency
            };

            const toSendUsers = Array.from(data.subscriptions ?? subscriptions);
            console.log("Sending " +toSendUsers.length + " notifications!");
            const results = await Promise.all(
                toSendUsers.map((element) => {
                    const subscription = element.subscription ?? element;
                    return sendPushNotification(subscription, notificationData, options);
                })
            );

            return sendJSON(res, {
                status: true,
                message: {
                    total: toSendUsers.length,
                    sent: results.filter(Boolean).length
                },
            });
        }

        // Handle 404
        res.writeHead(404);
        sendJSON(res, {
            status: false,
            message: "Not Found",
        });
    } catch (error) {
        console.error('Server error:', error);
        res.writeHead(500);
        sendJSON(res, {
            status: false,
            message: "Internal Server Error",
        });
    }
});

const PORT = process.env.PORT || 5743;
server.listen(PORT, () => {
    console.log(`Server running on port ${PORT}`);
});

// Save subscriptions to file on server shutdown
process.on('SIGINT', async () => {
    try {
        //! REMOVED: await fs.writeFile('subscriptions.json', JSON.stringify(Array.from(subscriptions)));
        process.exit(0);
    } catch (error) {
        console.error('Error saving subscriptions:', error);
        process.exit(1);
    }
});

// Load subscriptions from file on server start
(async () => {
    try {
        const savedSubscriptions = await fs.readFile('subscriptions.json', 'utf-8');
        JSON.parse(savedSubscriptions).forEach(sub => subscriptions.add(sub));
        console.log(`Loaded ${subscriptions.size} subscriptions`);
    } catch (error) {
        if (error.code !== 'ENOENT') {
            console.error('Error loading subscriptions:', error);
        }
    }
})();