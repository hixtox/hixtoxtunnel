class HixTunnel {
    constructor() {
        this.token = localStorage.getItem('token');
        this.ws = null;
        this.charts = {};
        this.tunnels = [];
        this.currentPage = null;
        
        this.initializeWebSocket();
        this.setupEventListeners();
        this.setupCharts();
        
        // Load initial page
        this.loadPage(window.location.pathname);
    }

    initializeWebSocket() {
        this.ws = new WebSocket('ws://localhost:8080');
        
        this.ws.onopen = () => {
            if (this.token) {
                this.ws.send(JSON.stringify({
                    action: 'authenticate',
                    token: this.token
                }));
            }
        };
        
        this.ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleWebSocketMessage(data);
        };
        
        this.ws.onclose = () => {
            setTimeout(() => this.initializeWebSocket(), 5000);
        };
    }

    setupEventListeners() {
        // Navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const href = e.currentTarget.getAttribute('href');
                this.loadPage(href);
                history.pushState(null, '', href);
            });
        });
        
        // Form submissions
        document.addEventListener('submit', (e) => {
            if (e.target.matches('form[data-ajax]')) {
                e.preventDefault();
                this.handleFormSubmit(e.target);
            }
        });
        
        // Modal triggers
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-modal]')) {
                e.preventDefault();
                const modalId = e.target.getAttribute('data-modal');
                this.openModal(modalId);
            }
        });
        
        // Period selection for metrics
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-period]')) {
                e.preventDefault();
                const period = e.target.getAttribute('data-period');
                this.loadMetrics(period);
            }
        });
    }

    setupCharts() {
        // Traffic chart
        const trafficCtx = document.getElementById('traffic-chart');
        if (trafficCtx) {
            this.charts.traffic = new Chart(trafficCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Bytes In',
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            data: []
                        },
                        {
                            label: 'Bytes Out',
                            borderColor: 'rgb(16, 185, 129)',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            data: []
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return HixUtils.formatBytes(value);
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Latency chart
        const latencyCtx = document.getElementById('latency-chart');
        if (latencyCtx) {
            this.charts.latency = new Chart(latencyCtx, {
                type: 'bar',
                data: {
                    labels: ['0-100ms', '100-200ms', '200-500ms', '500ms-1s', '1s+'],
                    datasets: [{
                        label: 'Response Time Distribution',
                        backgroundColor: 'rgba(59, 130, 246, 0.5)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 1,
                        data: []
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    async loadPage(path) {
        try {
            const response = await this.api.get(path);
            document.getElementById('main-content').innerHTML = response;
            
            // Update active nav item
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.toggle('active', link.getAttribute('href') === path);
            });
            
            this.currentPage = path;
            this.setupCharts();
            
            // Load page-specific data
            if (path === '/dashboard') {
                this.loadDashboard();
            } else if (path === '/metrics') {
                this.loadMetrics('1h');
            } else if (path === '/settings') {
                this.loadSettings();
            }
        } catch (error) {
            this.showError(error);
        }
    }

    async loadDashboard() {
        try {
            const [tunnels, metrics] = await Promise.all([
                this.api.get('/api/tunnels'),
                this.api.get('/api/metrics?period=24h')
            ]);
            
            this.tunnels = tunnels;
            this.updateTunnelList();
            this.updateMetricsCharts(metrics);
        } catch (error) {
            this.showError(error);
        }
    }

    async loadMetrics(period) {
        try {
            const metrics = await this.api.get(`/api/metrics?period=${period}`);
            this.updateMetricsCharts(metrics);
            
            // Update period buttons
            document.querySelectorAll('[data-period]').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-period') === period);
            });
        } catch (error) {
            this.showError(error);
        }
    }

    async loadSettings() {
        try {
            const [profile, tokens, notifications] = await Promise.all([
                this.api.get('/api/settings/profile'),
                this.api.get('/api/api-tokens'),
                this.api.get('/api/settings/notifications')
            ]);
            
            this.updateProfileForm(profile);
            this.updateApiTokens(tokens);
            this.updateNotificationSettings(notifications);
        } catch (error) {
            this.showError(error);
        }
    }

    async handleFormSubmit(form) {
        try {
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            const method = form.getAttribute('method') || 'POST';
            const endpoint = form.getAttribute('action');
            
            const response = await this.api[method.toLowerCase()](endpoint, data);
            
            if (endpoint === '/api/auth/login') {
                this.handleLogin(response);
            } else if (endpoint === '/api/auth/register') {
                this.showSuccess('Registration successful! Please log in.');
                this.loadPage('/login');
            } else {
                this.showSuccess('Changes saved successfully!');
                if (this.currentPage) {
                    this.loadPage(this.currentPage);
                }
            }
        } catch (error) {
            this.showError(error);
        }
    }

    handleLogin(response) {
        localStorage.setItem('token', response.token);
        this.token = response.token;
        
        // Re-initialize WebSocket with new token
        if (this.ws) {
            this.ws.close();
        }
        this.initializeWebSocket();
        
        this.loadPage('/dashboard');
    }

    handleWebSocketMessage(data) {
        switch (data.type) {
            case 'auth':
                if (data.status === 'success') {
                    // Subscribe to relevant channels
                    this.tunnels.forEach(tunnel => {
                        this.ws.send(JSON.stringify({
                            action: 'subscribe',
                            channel: `tunnel.${tunnel.id}`
                        }));
                    });
                }
                break;
                
            case 'tunnel_created':
            case 'tunnel_updated':
            case 'tunnel_deleted':
                this.loadDashboard();
                break;
                
            case 'metrics_update':
                if (this.currentPage === '/metrics' || this.currentPage === '/dashboard') {
                    this.updateMetricsCharts(data.metrics);
                }
                break;
        }
    }

    updateTunnelList() {
        const container = document.getElementById('tunnel-list');
        if (!container) return;
        
        container.innerHTML = this.tunnels.map(tunnel => `
            <div class="tunnel-card">
                <div class="tunnel-header">
                    <h3>${HixUtils.escapeHtml(tunnel.name)}</h3>
                    <div class="tunnel-status ${tunnel.status}">
                        ${tunnel.status}
                    </div>
                </div>
                <div class="tunnel-info">
                    <div>Local: ${tunnel.local_host}:${tunnel.local_port}</div>
                    <div>Remote: ${tunnel.remote_port}</div>
                    <div>Protocol: ${tunnel.protocol}</div>
                </div>
                <div class="tunnel-stats">
                    <div>
                        <span class="stat-label">Traffic In</span>
                        <span class="stat-value">${HixUtils.formatBytes(tunnel.total_bytes_in)}</span>
                    </div>
                    <div>
                        <span class="stat-label">Traffic Out</span>
                        <span class="stat-value">${HixUtils.formatBytes(tunnel.total_bytes_out)}</span>
                    </div>
                </div>
                <div class="tunnel-actions">
                    <button class="btn btn-sm btn-primary" onclick="app.toggleTunnel(${tunnel.id})">
                        ${tunnel.status === 'active' ? 'Stop' : 'Start'}
                    </button>
                    <button class="btn btn-sm btn-outline" data-modal="edit-tunnel" data-tunnel-id="${tunnel.id}">
                        Edit
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="app.deleteTunnel(${tunnel.id})">
                        Delete
                    </button>
                </div>
            </div>
        `).join('');
    }

    updateMetricsCharts(metrics) {
        if (this.charts.traffic) {
            this.charts.traffic.data.labels = metrics.map(m => m.time);
            this.charts.traffic.data.datasets[0].data = metrics.map(m => m.bytes_in);
            this.charts.traffic.data.datasets[1].data = metrics.map(m => m.bytes_out);
            this.charts.traffic.update();
        }
        
        if (this.charts.latency) {
            // Calculate response time distribution
            const distribution = this.calculateLatencyDistribution(metrics);
            this.charts.latency.data.datasets[0].data = distribution;
            this.charts.latency.update();
        }
        
        // Update stats
        const stats = this.calculateStats(metrics);
        this.updateStats(stats);
    }

    calculateLatencyDistribution(metrics) {
        const total = metrics.reduce((sum, m) => sum + m.total_requests, 0);
        const ranges = [100, 200, 500, 1000, Infinity];
        
        return ranges.map(range => {
            const count = metrics.reduce((sum, m) => {
                return sum + (m.avg_response_time <= range ? m.total_requests : 0);
            }, 0);
            
            return (count / total) * 100;
        });
    }

    calculateStats(metrics) {
        return metrics.reduce((stats, m) => ({
            total_requests: stats.total_requests + m.total_requests,
            total_bytes: stats.total_bytes + m.bytes_in + m.bytes_out,
            avg_response_time: stats.avg_response_time + m.avg_response_time,
            error_rate: stats.error_rate + (m.errors / m.total_requests) * 100
        }), {
            total_requests: 0,
            total_bytes: 0,
            avg_response_time: 0,
            error_rate: 0
        });
    }

    updateStats(stats) {
        document.querySelectorAll('[data-stat]').forEach(el => {
            const stat = el.getAttribute('data-stat');
            let value = stats[stat];
            
            switch (stat) {
                case 'totalRequests':
                    value = HixUtils.formatNumber(stats.total_requests);
                    break;
                case 'totalTraffic':
                    value = HixUtils.formatBytes(stats.total_bytes);
                    break;
                case 'avgResponseTime':
                    value = Math.round(stats.avg_response_time) + ' ms';
                    break;
                case 'errorRate':
                    value = stats.error_rate.toFixed(2) + '%';
                    break;
            }
            
            el.textContent = value;
        });
    }

    async toggleTunnel(tunnelId) {
        try {
            const tunnel = this.tunnels.find(t => t.id === tunnelId);
            const action = tunnel.status === 'active' ? 'stop' : 'start';
            
            await this.api.post(`/api/tunnels/${tunnelId}/${action}`);
            this.loadDashboard();
        } catch (error) {
            this.showError(error);
        }
    }

    async deleteTunnel(tunnelId) {
        if (!confirm('Are you sure you want to delete this tunnel?')) {
            return;
        }
        
        try {
            await this.api.delete(`/api/tunnels/${tunnelId}`);
            this.loadDashboard();
        } catch (error) {
            this.showError(error);
        }
    }

    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.classList.add('active');
        
        if (modalId === 'edit-tunnel') {
            const tunnelId = event.target.getAttribute('data-tunnel-id');
            const tunnel = this.tunnels.find(t => t.id === parseInt(tunnelId));
            if (tunnel) {
                this.populateTunnelForm(tunnel);
            }
        }
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.classList.remove('active');
    }

    populateTunnelForm(tunnel) {
        const form = document.getElementById('edit-tunnel-form');
        if (!form) return;
        
        form.elements.name.value = tunnel.name;
        form.elements.description.value = tunnel.description || '';
        form.elements.local_host.value = tunnel.local_host;
        form.elements.local_port.value = tunnel.local_port;
        form.elements.remote_port.value = tunnel.remote_port;
        form.elements.protocol.value = tunnel.protocol;
        form.elements.auth_enabled.checked = tunnel.auth_enabled;
        
        form.setAttribute('action', `/api/tunnels/${tunnel.id}`);
        form.setAttribute('method', 'PUT');
    }

    updateProfileForm(profile) {
        const form = document.getElementById('profile-form');
        if (!form) return;
        
        form.elements.name.value = profile.name;
        form.elements.email.value = profile.email;
    }

    updateApiTokens(tokens) {
        const container = document.getElementById('api-tokens');
        if (!container) return;
        
        container.innerHTML = tokens.map(token => `
            <div class="token-card">
                <div class="token-info">
                    <h4>${HixUtils.escapeHtml(token.name)}</h4>
                    <div class="token-details">
                        <div>Created: ${new Date(token.created_at).toLocaleDateString()}</div>
                        ${token.last_used_at ? `<div>Last used: ${new Date(token.last_used_at).toLocaleDateString()}</div>` : ''}
                    </div>
                </div>
                <div class="token-actions">
                    <button class="btn btn-sm btn-danger" onclick="app.deleteApiToken(${token.id})">
                        Delete
                    </button>
                </div>
            </div>
        `).join('');
    }

    updateNotificationSettings(settings) {
        const form = document.getElementById('notification-form');
        if (!form) return;
        
        form.elements.email_notifications.checked = settings.email_notifications;
        form.elements.tunnel_alerts.checked = settings.tunnel_alerts;
        form.elements.usage_reports.checked = settings.usage_reports;
    }

    async deleteApiToken(tokenId) {
        if (!confirm('Are you sure you want to delete this API token?')) {
            return;
        }
        
        try {
            await this.api.delete(`/api/api-tokens/${tokenId}`);
            this.loadSettings();
        } catch (error) {
            this.showError(error);
        }
    }

    showSuccess(message) {
        this.showToast(message, 'success');
    }

    showError(error) {
        this.showToast(error.message || 'An error occurred', 'error');
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                document.body.removeChild(toast);
            }, 300);
        }, 3000);
    }
}

class HixUtils {
    static formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    static formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    static escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

// Initialize app
const app = new HixTunnel();
