// server.js
const http = require('http');
const path = require('path');
const fs = require('fs').promises;
const webpush = require('web-push');

// Generate VAPID keys (do this once and save the keys)
const vapidKeys = webpush.generateVAPIDKeys();

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
async function sendPushNotification(subscription, data) {
    try {
        await webpush.sendNotification(subscription, JSON.stringify(data));
        return true;
    } catch (error) {
        console.error('Error sending push notification:', error);
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
            return sendJSON(res, { publicKey: vapidKeys.publicKey });
        }

        if (req.url === '/api/subscribe') {
            const subscription = await parseBody(req);
            subscriptions.add(subscription);
            return sendJSON(res, { message: 'Subscription saved' }, 201);
        }

        if (req.url === '/api/send-notification') {
            const data = await parseBody(req);
            const notificationData = {
                title: data.title || 'New Notification',
                body: data.body || 'This is a push notification',
                icon: data.icon || '/icon.png',
                data: {
                    url: data.url || 'https://your-site.com'
                }
            };

            const results = await Promise.all(
                Array.from(subscriptions).map(subscription =>
                    sendPushNotification(subscription, notificationData)
                )
            );

            return sendJSON(res, {
                success: true,
                sentCount: results.filter(Boolean).length
            });
        }

        // Handle 404
        res.writeHead(404);
        res.end('Not Found');
    } catch (error) {
        console.error('Server error:', error);
        res.writeHead(500);
        res.end('Internal Server Error');
    }
});

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`Server running on port ${PORT}`);
});

// Save subscriptions to file on server shutdown
process.on('SIGINT', async () => {
    try {
        await fs.writeFile(
            'subscriptions.json', 
            JSON.stringify(Array.from(subscriptions))
        );
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