const express = require('express');
const { createServer } = require('http');
const { Server } = require('socket.io');
const apiRoutes = require('./routes/api');
const { setupTunnelHandlers } = require('./routes/tunnel');

const app = express();
const httpServer = createServer(app);
const io = new Server(httpServer, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

// Middleware
app.use(express.json());
app.use('/api', apiRoutes);

// Setup WebSocket handlers
setupTunnelHandlers(io);

// Start server
const PORT = process.env.PORT || 8080;
httpServer.listen(PORT, () => {
    console.log(`Server running on port ${PORT}`);
});
