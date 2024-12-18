const RateLimit = require('express-rate-limit');
const RedisStore = require('rate-limit-redis');
const Redis = require('ioredis');

const redisClient = new Redis({
    host: process.env.REDIS_HOST || 'localhost',
    port: process.env.REDIS_PORT || 6379,
    password: process.env.REDIS_PASSWORD,
});

// Global rate limiter
const globalLimiter = RateLimit({
    store: new RedisStore({
        client: redisClient,
        prefix: 'rl:global:',
    }),
    windowMs: 15 * 60 * 1000, // 15 minutes
    max: 100, // limit each IP to 100 requests per windowMs
    message: { error: 'Too many requests, please try again later.' }
});

// API specific rate limiter
const apiLimiter = RateLimit({
    store: new RedisStore({
        client: redisClient,
        prefix: 'rl:api:',
    }),
    windowMs: 5 * 60 * 1000, // 5 minutes
    max: 50, // limit each IP to 50 requests per windowMs
    message: { error: 'Too many API requests, please try again later.' }
});

// Tunnel creation rate limiter
const tunnelLimiter = RateLimit({
    store: new RedisStore({
        client: redisClient,
        prefix: 'rl:tunnel:',
    }),
    windowMs: 60 * 60 * 1000, // 1 hour
    max: 10, // limit each IP to 10 tunnel creations per hour
    message: { error: 'Too many tunnel creation requests, please try again later.' }
});

// Track data transfer rates
const trackDataTransfer = async (userId, bytes) => {
    const key = `transfer:${userId}`;
    const minute = Math.floor(Date.now() / 60000);
    
    try {
        await redisClient.hincrby(key, minute, bytes);
        await redisClient.expire(key, 3600); // Keep data for 1 hour
    } catch (error) {
        console.error('Error tracking data transfer:', error);
    }
};

// Check data transfer limit
const checkDataTransferLimit = async (userId, limit) => {
    const key = `transfer:${userId}`;
    const minute = Math.floor(Date.now() / 60000);
    
    try {
        const usage = await redisClient.hget(key, minute);
        return usage ? parseInt(usage) < limit : true;
    } catch (error) {
        console.error('Error checking data transfer limit:', error);
        return true; // Allow transfer on error
    }
};

// Get current usage statistics
const getUsageStats = async (userId) => {
    const key = `transfer:${userId}`;
    const now = Math.floor(Date.now() / 60000);
    
    try {
        const usage = await redisClient.hgetall(key);
        let total = 0;
        let lastMinute = 0;
        let lastHour = 0;
        
        for (const [minute, bytes] of Object.entries(usage)) {
            const minuteNum = parseInt(minute);
            const bytesNum = parseInt(bytes);
            
            if (minuteNum === now) {
                lastMinute = bytesNum;
            }
            
            if (minuteNum >= now - 60) {
                lastHour += bytesNum;
            }
            
            total += bytesNum;
        }
        
        return { total, lastMinute, lastHour };
    } catch (error) {
        console.error('Error getting usage stats:', error);
        return { total: 0, lastMinute: 0, lastHour: 0 };
    }
};

module.exports = {
    globalLimiter,
    apiLimiter,
    tunnelLimiter,
    trackDataTransfer,
    checkDataTransferLimit,
    getUsageStats,
    redisClient
};
