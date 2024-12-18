const express = require('express');
const router = express.Router();
const { validateToken, getUserInfo, createTunnel, getTunnels, getTunnel, updateTunnel, deleteTunnel, getStats, getMetrics, getEvents, clearEvents } = require('../db');
const { apiLimiter, tunnelLimiter } = require('../rateLimit');

// Authentication middleware
const authenticateToken = async (req, res, next) => {
    try {
        const token = req.headers.authorization?.split(' ')[1];
        if (!token) {
            return res.status(401).json({ error: 'No token provided' });
        }

        const userId = await validateToken(token);
        if (!userId) {
            return res.status(401).json({ error: 'Invalid token' });
        }

        req.userId = userId;
        next();
    } catch (error) {
        console.error('Auth error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
};

// User routes
router.get('/user', authenticateToken, async (req, res) => {
    try {
        const user = await getUserInfo(req.userId);
        if (!user) {
            return res.status(404).json({ error: 'User not found' });
        }
        res.json({ username: user.username });
    } catch (error) {
        console.error('Error in /api/user:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

// Stats routes
router.get('/stats', [authenticateToken, apiLimiter], async (req, res) => {
    try {
        const stats = await getStats(req.userId);
        res.json(stats);
    } catch (error) {
        console.error('Error in /api/stats:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

// Metrics routes
router.get('/metrics', [authenticateToken, apiLimiter], async (req, res) => {
    try {
        const { view = 'traffic', period = '1h' } = req.query;
        const tunnels = await getTunnels(req.userId);
        
        const metrics = {};
        for (const tunnel of tunnels) {
            const tunnelMetrics = await getMetrics(tunnel.id, period);
            metrics[tunnel.id] = tunnelMetrics;
        }
        
        res.json(metrics);
    } catch (error) {
        console.error('Error in /api/metrics:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

// Events routes
router.get('/events', [authenticateToken, apiLimiter], async (req, res) => {
    try {
        const { type, startDate, endDate, limit } = req.query;
        const events = await getEvents({
            userId: req.userId,
            type,
            startDate,
            endDate,
            limit: parseInt(limit) || 10
        });
        res.json(events);
    } catch (error) {
        console.error('Error in /api/events:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

router.delete('/events', authenticateToken, async (req, res) => {
    try {
        await clearEvents(req.userId);
        res.json({ success: true });
    } catch (error) {
        console.error('Error in DELETE /api/events:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

// Tunnel routes
router.get('/tunnels', [authenticateToken, apiLimiter], async (req, res) => {
    try {
        const tunnels = await getTunnels(req.userId);
        res.json(tunnels);
    } catch (error) {
        console.error('Error in GET /api/tunnels:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

router.post('/tunnels', [authenticateToken, tunnelLimiter], async (req, res) => {
    try {
        const tunnelData = {
            ...req.body,
            userId: req.userId,
            status: 'inactive'
        };
        
        const tunnelId = await createTunnel(tunnelData);
        if (!tunnelId) {
            return res.status(400).json({ error: 'Failed to create tunnel' });
        }
        
        const tunnel = await getTunnel(tunnelId);
        res.status(201).json(tunnel);
    } catch (error) {
        console.error('Error in POST /api/tunnels:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

router.get('/tunnels/:id', authenticateToken, async (req, res) => {
    try {
        const tunnel = await getTunnel(req.params.id);
        if (!tunnel) {
            return res.status(404).json({ error: 'Tunnel not found' });
        }
        
        if (tunnel.userId !== req.userId) {
            return res.status(403).json({ error: 'Access denied' });
        }
        
        res.json(tunnel);
    } catch (error) {
        console.error('Error in GET /api/tunnels/:id:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

router.put('/tunnels/:id', authenticateToken, async (req, res) => {
    try {
        const tunnel = await getTunnel(req.params.id);
        if (!tunnel) {
            return res.status(404).json({ error: 'Tunnel not found' });
        }
        
        if (tunnel.userId !== req.userId) {
            return res.status(403).json({ error: 'Access denied' });
        }
        
        const success = await updateTunnel(req.params.id, req.body);
        if (!success) {
            return res.status(400).json({ error: 'Failed to update tunnel' });
        }
        
        const updatedTunnel = await getTunnel(req.params.id);
        res.json(updatedTunnel);
    } catch (error) {
        console.error('Error in PUT /api/tunnels/:id:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

router.delete('/tunnels/:id', authenticateToken, async (req, res) => {
    try {
        const tunnel = await getTunnel(req.params.id);
        if (!tunnel) {
            return res.status(404).json({ error: 'Tunnel not found' });
        }
        
        if (tunnel.userId !== req.userId) {
            return res.status(403).json({ error: 'Access denied' });
        }
        
        const success = await deleteTunnel(req.params.id);
        if (!success) {
            return res.status(400).json({ error: 'Failed to delete tunnel' });
        }
        
        res.json({ success: true });
    } catch (error) {
        console.error('Error in DELETE /api/tunnels/:id:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});

module.exports = router;
