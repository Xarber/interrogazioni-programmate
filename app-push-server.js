// server.js
const http = require('http');
const path = require('path');
const fsSync = require('fs');
const fs = fsSync.promises;
const webpush = require('web-push');

// Generate VAPID keys (do this once and save the keys)
const vapidKeyPath = process.env.SCUOLA_VAPID_KEY_FILE;
const subscriptionPath = process.env.SCUOLA_PUSH_SUBSCRIPTIONS_FILE;
if (!vapidKeyPath) throw new Error('SCUOLA_VAPID_KEY_FILE must point outside the web root.');
const applicationRoot = path.resolve(__dirname);
const isInsideApplication = candidate => {
    const relative = path.relative(applicationRoot, path.resolve(candidate));
    return relative === '' || (!relative.startsWith('..' + path.sep) && relative !== '..' && !path.isAbsolute(relative));
};
if (isInsideApplication(vapidKeyPath)) throw new Error('The VAPID key file must be outside the web root.');
if (subscriptionPath && isInsideApplication(subscriptionPath)) throw new Error('Push subscription persistence must be outside the web root.');
if (!fsSync.existsSync(vapidKeyPath)) throw new Error('The configured VAPID key file does not exist.');
const vapidKeys = JSON.parse(fsSync.readFileSync(vapidKeyPath, 'utf8'));
if (!vapidKeys.publicKey || !vapidKeys.privateKey) throw new Error('The configured VAPID key file is invalid.');


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
        req.on('data', chunk => {
            body += chunk;
            if (body.length > 1024 * 1024) req.destroy(new Error('Request body is too large.'));
        });
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
        //console.log(data, options);
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
                subject: bodyData.subject,
                classId: bodyData.classId,
                additionalInfo: bodyData.additionalInfo ?? {},
                urgency: bodyData.urgency ?? "normal",
                subscriptions: bodyData.subscriptions
            };
            let notificationData = {
                title: data.title ?? 'New Notification',
                tag: data.tag,
                body: data.body ?? 'Open the website to read',
                lang: data.lang,
                icon: data.icon ?? '/icon.png',
                image: data.image,
                badge: data.badge,
                data: {
                    url: data.url,
                    subject: data.subject,
                    classId: data.classId,
                    ...data.additionalInfo
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
            if (notificationData.priority === "high") {
                notificationData.tag = `time-sensitive-${((new Date().getTime()) + Math.random()).toString()}`;
                notificationData.renotify = true;
                notificationData.silent = false;
                notificationData.requireInteraction = true;
            }

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
        sendJSON(res, {
            status: false,
            message: "Not Found",
        }, 404);
    } catch (error) {
        console.error('Server error:', error);
        sendJSON(res, {
            status: false,
            message: "Internal Server Error",
        }, 500);
    }
});

const PORT = process.env.PORT || 5743;
const HOST = process.env.HOST || '127.0.0.1';
server.listen(PORT, HOST, () => {
    console.log(`Server running on ${HOST}:${PORT}`);
});

// Save subscriptions to file on server shutdown
async function shutdown() {
    try {
        if (subscriptionPath) await fs.writeFile(subscriptionPath, JSON.stringify(Array.from(subscriptions)), {mode: 0o600});
        server.close(() => process.exit(0));
    } catch (error) {
        console.error('Error saving subscriptions:', error);
        process.exit(1);
    }
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

// Load subscriptions from file on server start
(async () => {
    try {
        if (!subscriptionPath) return;
        const savedSubscriptions = await fs.readFile(subscriptionPath, 'utf-8');
        JSON.parse(savedSubscriptions).forEach(sub => subscriptions.add(sub));
        console.log(`Loaded ${subscriptions.size} subscriptions`);
    } catch (error) {
        if (error.code !== 'ENOENT') {
            console.error('Error loading subscriptions:', error);
        }
    }
})();
