const mysql = require('mysql2/promise');
const { redisClient } = require('./rateLimit');
require('dotenv').config({ path: './config/default.env' });

let pool;

async function initializeDatabase() {
    try {
        pool = mysql.createPool({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASSWORD,
            database: process.env.DB_NAME,
            waitForConnections: true,
            connectionLimit: 10,
            queueLimit: 0
        });

        // Test connection
        await pool.getConnection();
        console.log('Successfully connected to MySQL database');
    } catch (error) {
        console.error('Failed to connect to MySQL:', error);
        console.log('Falling back to in-memory storage');
        return false;
    }
    return true;
}

// In-memory fallback storage
const memoryStorage = {
    users: new Map(),
    tunnels: new Map(),
    events: [],
    metrics: new Map()
};

async function validateToken(token) {
    try {
        if (pool) {
            const [rows] = await pool.execute(
                'SELECT user_id FROM sessions WHERE token = ? AND expires_at > NOW()',
                [token]
            );
            return rows.length > 0 ? rows[0].user_id : null;
        }
        
        // Fallback to memory storage
        for (const [userId, user] of memoryStorage.users) {
            if (user.token === token) return userId;
        }
        return null;
    } catch (error) {
        console.error('Error validating token:', error);
        return null;
    }
}

async function getUserInfo(userId) {
    try {
        if (pool) {
            const [rows] = await pool.execute(
                'SELECT id, username, email, is_admin FROM users WHERE id = ?',
                [userId]
            );
            return rows.length > 0 ? rows[0] : null;
        }
        
        return memoryStorage.users.get(userId) || null;
    } catch (error) {
        console.error('Error getting user info:', error);
        return null;
    }
}

async function createTunnel(tunnelData) {
    try {
        if (pool) {
            const [result] = await pool.execute(
                'INSERT INTO tunnels (user_id, name, local_host, local_port, remote_port, protocol, auth_enabled, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [tunnelData.userId, tunnelData.name, tunnelData.localHost, tunnelData.localPort, tunnelData.remotePort, tunnelData.protocol, tunnelData.authEnabled, tunnelData.description]
            );
            return result.insertId;
        }
        
        const tunnelId = Date.now().toString();
        memoryStorage.tunnels.set(tunnelId, { ...tunnelData, id: tunnelId });
        return tunnelId;
    } catch (error) {
        console.error('Error creating tunnel:', error);
        return null;
    }
}

async function updateTunnelStatus(tunnelId, status) {
    try {
        if (pool) {
            await pool.execute(
                'UPDATE tunnels SET status = ?, updated_at = NOW() WHERE id = ?',
                [status, tunnelId]
            );
            return true;
        }
        
        const tunnel = memoryStorage.tunnels.get(tunnelId);
        if (tunnel) {
            tunnel.status = status;
            tunnel.updatedAt = new Date();
            return true;
        }
        return false;
    } catch (error) {
        console.error('Error updating tunnel status:', error);
        return false;
    }
}

async function getTunnels(userId) {
    try {
        if (pool) {
            const [rows] = await pool.execute(
                'SELECT * FROM tunnels WHERE user_id = ?',
                [userId]
            );
            return rows;
        }
        
        return Array.from(memoryStorage.tunnels.values())
            .filter(tunnel => tunnel.userId === userId);
    } catch (error) {
        console.error('Error getting tunnels:', error);
        return [];
    }
}

async function getTunnel(tunnelId) {
    try {
        if (pool) {
            const [rows] = await pool.execute(
                'SELECT * FROM tunnels WHERE id = ?',
                [tunnelId]
            );
            return rows.length > 0 ? rows[0] : null;
        }
        
        return memoryStorage.tunnels.get(tunnelId) || null;
    } catch (error) {
        console.error('Error getting tunnel:', error);
        return null;
    }
}

async function updateTunnel(tunnelId, data) {
    try {
        if (pool) {
            await pool.execute(
                'UPDATE tunnels SET name = ?, local_host = ?, local_port = ?, remote_port = ?, protocol = ?, auth_enabled = ?, description = ?, updated_at = NOW() WHERE id = ?',
                [data.name, data.localHost, data.localPort, data.remotePort, data.protocol, data.authEnabled, data.description, tunnelId]
            );
            return true;
        }
        
        const tunnel = memoryStorage.tunnels.get(tunnelId);
        if (tunnel) {
            Object.assign(tunnel, data);
            tunnel.updatedAt = new Date();
            return true;
        }
        return false;
    } catch (error) {
        console.error('Error updating tunnel:', error);
        return false;
    }
}

async function deleteTunnel(tunnelId) {
    try {
        if (pool) {
            await pool.execute('DELETE FROM tunnels WHERE id = ?', [tunnelId]);
            return true;
        }
        
        return memoryStorage.tunnels.delete(tunnelId);
    } catch (error) {
        console.error('Error deleting tunnel:', error);
        return false;
    }
}

async function logEvent(event) {
    try {
        if (pool) {
            await pool.execute(
                'INSERT INTO events (user_id, type, title, description) VALUES (?, ?, ?, ?)',
                [event.userId, event.type, event.title, event.description]
            );
            return true;
        }
        
        memoryStorage.events.push({ ...event, timestamp: new Date() });
        return true;
    } catch (error) {
        console.error('Error logging event:', error);
        return false;
    }
}

async function getEvents(filters = {}) {
    try {
        if (pool) {
            let query = 'SELECT * FROM events WHERE 1=1';
            const params = [];
            
            if (filters.userId) {
                query += ' AND user_id = ?';
                params.push(filters.userId);
            }
            
            if (filters.type) {
                query += ' AND type = ?';
                params.push(filters.type);
            }
            
            if (filters.startDate) {
                query += ' AND created_at >= ?';
                params.push(filters.startDate);
            }
            
            if (filters.endDate) {
                query += ' AND created_at <= ?';
                params.push(filters.endDate);
            }
            
            query += ' ORDER BY created_at DESC';
            
            if (filters.limit) {
                query += ' LIMIT ?';
                params.push(filters.limit);
            }
            
            const [rows] = await pool.execute(query, params);
            return rows;
        }
        
        let events = [...memoryStorage.events];
        
        if (filters.userId) {
            events = events.filter(e => e.userId === filters.userId);
        }
        
        if (filters.type) {
            events = events.filter(e => e.type === filters.type);
        }
        
        if (filters.startDate) {
            events = events.filter(e => e.timestamp >= new Date(filters.startDate));
        }
        
        if (filters.endDate) {
            events = events.filter(e => e.timestamp <= new Date(filters.endDate));
        }
        
        events.sort((a, b) => b.timestamp - a.timestamp);
        
        if (filters.limit) {
            events = events.slice(0, filters.limit);
        }
        
        return events;
    } catch (error) {
        console.error('Error getting events:', error);
        return [];
    }
}

async function clearEvents(userId) {
    try {
        if (pool) {
            await pool.execute('DELETE FROM events WHERE user_id = ?', [userId]);
            return true;
        }
        
        memoryStorage.events = memoryStorage.events.filter(e => e.userId !== userId);
        return true;
    } catch (error) {
        console.error('Error clearing events:', error);
        return false;
    }
}

async function updateMetrics(tunnelId, metrics) {
    const key = `metrics:${tunnelId}`;
    try {
        await redisClient.hset(key, metrics);
        await redisClient.expire(key, 86400); // Keep metrics for 24 hours
        return true;
    } catch (error) {
        console.error('Error updating metrics:', error);
        return false;
    }
}

async function getMetrics(tunnelId, period = '1h') {
    const key = `metrics:${tunnelId}`;
    try {
        const metrics = await redisClient.hgetall(key);
        return metrics || {};
    } catch (error) {
        console.error('Error getting metrics:', error);
        return {};
    }
}

async function getStats(userId) {
    try {
        if (pool) {
            const stats = {
                activeTunnels: 0,
                totalTraffic: 0,
                avgResponseTime: 0,
                errorRate: 0
            };
            
            // Get active tunnels count
            const [tunnelRows] = await pool.execute(
                'SELECT COUNT(*) as count FROM tunnels WHERE user_id = ? AND status = "active"',
                [userId]
            );
            stats.activeTunnels = tunnelRows[0].count;
            
            // Get traffic metrics from Redis
            const tunnels = await getTunnels(userId);
            for (const tunnel of tunnels) {
                const metrics = await getMetrics(tunnel.id);
                stats.totalTraffic += parseInt(metrics.bytesIn || 0) + parseInt(metrics.bytesOut || 0);
                if (metrics.responseTime) {
                    stats.avgResponseTime += parseInt(metrics.responseTime);
                }
                if (metrics.errors) {
                    stats.errorRate += parseInt(metrics.errors);
                }
            }
            
            if (tunnels.length > 0) {
                stats.avgResponseTime /= tunnels.length;
                stats.errorRate = (stats.errorRate / tunnels.length) * 100;
            }
            
            return stats;
        }
        
        // Fallback to memory storage
        const stats = {
            activeTunnels: 0,
            totalTraffic: 0,
            avgResponseTime: 0,
            errorRate: 0
        };
        
        const tunnels = Array.from(memoryStorage.tunnels.values())
            .filter(t => t.userId === userId);
            
        stats.activeTunnels = tunnels.filter(t => t.status === 'active').length;
        
        for (const tunnel of tunnels) {
            const metrics = memoryStorage.metrics.get(tunnel.id) || {};
            stats.totalTraffic += (metrics.bytesIn || 0) + (metrics.bytesOut || 0);
            if (metrics.responseTime) stats.avgResponseTime += metrics.responseTime;
            if (metrics.errors) stats.errorRate += metrics.errors;
        }
        
        if (tunnels.length > 0) {
            stats.avgResponseTime /= tunnels.length;
            stats.errorRate = (stats.errorRate / tunnels.length) * 100;
        }
        
        return stats;
    } catch (error) {
        console.error('Error getting stats:', error);
        return {
            activeTunnels: 0,
            totalTraffic: 0,
            avgResponseTime: 0,
            errorRate: 0
        };
    }
}

// Initialize database connection
initializeDatabase();

module.exports = {
    validateToken,
    getUserInfo,
    createTunnel,
    updateTunnelStatus,
    getTunnels,
    getTunnel,
    updateTunnel,
    deleteTunnel,
    logEvent,
    getEvents,
    clearEvents,
    updateMetrics,
    getMetrics,
    getStats
};
