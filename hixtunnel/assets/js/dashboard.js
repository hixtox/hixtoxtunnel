const app = {
    // Chart instance
    chart: null,
    
    // Current view settings
    currentView: 'traffic',
    currentPeriod: '1h',
    
    // Initialize dashboard
    init() {
        this.initChart();
        this.initPeriodSelector();
        this.initWebSocket();
        this.refreshDashboard();
        
        // Auto-refresh every minute
        setInterval(() => this.refreshDashboard(), 60000);
    },
    
    // Initialize chart
    initChart() {
        const ctx = document.getElementById('traffic-chart').getContext('2d');
        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: []
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += app.formatValue(context.parsed.y, app.currentView);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'minute'
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatValue(value, this.currentView)
                        }
                    }
                }
            }
        });
    },
    
    // Initialize period selector
    initPeriodSelector() {
        document.querySelectorAll('[data-period]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const period = e.target.getAttribute('data-period');
                document.querySelectorAll('[data-period]').forEach(b => {
                    b.classList.toggle('active', b === e.target);
                });
                this.currentPeriod = period;
                this.refreshDashboard();
            });
        });
    },
    
    // Initialize WebSocket connection
    initWebSocket() {
        const ws = new WebSocket('ws://' + window.location.host + '/ws');
        
        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            
            switch (data.type) {
                case 'stats':
                    this.updateStats(data.stats);
                    break;
                case 'tunnel':
                    this.updateTunnel(data.tunnel);
                    break;
                case 'event':
                    this.addEvent(data.event);
                    break;
            }
        };
        
        ws.onclose = () => {
            // Attempt to reconnect after 5 seconds
            setTimeout(() => this.initWebSocket(), 5000);
        };
    },
    
    // Refresh dashboard data
    async refreshDashboard() {
        try {
            const [stats, tunnels, events] = await Promise.all([
                this.fetchStats(),
                this.fetchTunnels(),
                this.fetchEvents()
            ]);
            
            this.updateStats(stats);
            this.updateTunnels(tunnels);
            this.updateEvents(events);
            this.updateChart();
            
        } catch (error) {
            console.error('Failed to refresh dashboard:', error);
            this.showError('Failed to refresh dashboard data');
        }
    },
    
    // Fetch methods
    async fetchStats() {
        const response = await fetch('/api/stats');
        return response.json();
    },
    
    async fetchTunnels() {
        const response = await fetch('/api/tunnels');
        return response.json();
    },
    
    async fetchEvents() {
        const response = await fetch('/api/events');
        return response.json();
    },
    
    async fetchChartData() {
        const response = await fetch(`/api/metrics?view=${this.currentView}&period=${this.currentPeriod}`);
        return response.json();
    },
    
    // Update methods
    updateStats(stats) {
        // Update stat values
        document.querySelectorAll('[data-stat]').forEach(el => {
            const stat = el.getAttribute('data-stat');
            if (stats[stat] !== undefined) {
                el.textContent = this.formatValue(stats[stat], stat);
            }
        });
        
        // Update trends
        document.querySelectorAll('[data-trend]').forEach(el => {
            const trend = el.getAttribute('data-trend');
            if (stats.trends && stats.trends[trend] !== undefined) {
                const value = stats.trends[trend];
                const trendValue = el.querySelector('.trend-value');
                trendValue.textContent = (value >= 0 ? '+' : '') + value.toFixed(1) + '%';
                trendValue.className = 'trend-value ' + (value > 0 ? 'trend-up' : value < 0 ? 'trend-down' : 'trend-neutral');
            }
        });
    },
    
    updateTunnels(tunnels) {
        const container = document.getElementById('tunnel-list');
        container.innerHTML = '';
        
        tunnels.forEach(tunnel => {
            container.appendChild(this.createTunnelCard(tunnel));
        });
    },
    
    updateEvents(events) {
        const container = document.getElementById('events-list');
        container.innerHTML = '';
        
        events.forEach(event => {
            container.appendChild(this.createEventItem(event));
        });
    },
    
    async updateChart() {
        const data = await this.fetchChartData();
        
        // Update datasets based on view
        let datasets = [];
        switch (this.currentView) {
            case 'traffic':
                datasets = [
                    {
                        label: 'Bytes In',
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        data: data.bytesIn
                    },
                    {
                        label: 'Bytes Out',
                        borderColor: 'rgb(16, 185, 129)',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        data: data.bytesOut
                    }
                ];
                break;
                
            case 'requests':
                datasets = [
                    {
                        label: 'Requests',
                        borderColor: 'rgb(139, 92, 246)',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        data: data.requests
                    }
                ];
                break;
                
            case 'errors':
                datasets = [
                    {
                        label: 'Errors',
                        borderColor: 'rgb(239, 68, 68)',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        data: data.errors
                    }
                ];
                break;
        }
        
        this.chart.data.labels = data.labels;
        this.chart.data.datasets = datasets;
        this.chart.update();
    },
    
    // UI creation methods
    createTunnelCard(tunnel) {
        const card = document.createElement('div');
        card.className = 'tunnel-card';
        card.setAttribute('data-status', tunnel.active ? 'active' : 'inactive');
        card.setAttribute('data-protocol', tunnel.protocol);
        
        card.innerHTML = `
            <div class="tunnel-header">
                <div class="tunnel-title">
                    <h3 class="tunnel-name">${this.escapeHtml(tunnel.name)}</h3>
                    <span class="tunnel-status ${tunnel.active ? 'status-active' : 'status-inactive'}">
                        ${tunnel.active ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <div class="tunnel-actions">
                    <button class="btn btn-icon" onclick="app.editTunnel(${tunnel.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-icon" onclick="app.toggleTunnel(${tunnel.id}, ${!tunnel.active})">
                        <i class="fas fa-${tunnel.active ? 'stop' : 'play'}"></i>
                    </button>
                    <button class="btn btn-icon" onclick="app.deleteTunnel(${tunnel.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <p class="tunnel-description">${this.escapeHtml(tunnel.description || '')}</p>
            <div class="tunnel-details">
                <div class="tunnel-detail">
                    <span class="detail-label">Local:</span>
                    <span class="detail-value">${tunnel.local_host}:${tunnel.local_port}</span>
                </div>
                <div class="tunnel-detail">
                    <span class="detail-label">Remote:</span>
                    <span class="detail-value">${tunnel.remote_port}</span>
                </div>
                <div class="tunnel-detail">
                    <span class="detail-label">Protocol:</span>
                    <span class="detail-value">${tunnel.protocol.toUpperCase()}</span>
                </div>
            </div>
            <div class="tunnel-metrics">
                <div class="tunnel-metric">
                    <span class="metric-label">Traffic In</span>
                    <span class="metric-value">${this.formatBytes(tunnel.bytes_in)}</span>
                </div>
                <div class="tunnel-metric">
                    <span class="metric-label">Traffic Out</span>
                    <span class="metric-value">${this.formatBytes(tunnel.bytes_out)}</span>
                </div>
                <div class="tunnel-metric">
                    <span class="metric-label">Uptime</span>
                    <span class="metric-value">${this.formatDuration(tunnel.uptime)}</span>
                </div>
            </div>
        `;
        
        return card;
    },
    
    createEventItem(event) {
        const item = document.createElement('div');
        item.className = 'event-item';
        
        item.innerHTML = `
            <div class="event-icon ${this.getEventIconClass(event.type)}">
                <i class="fas ${this.getEventIcon(event.type)}"></i>
            </div>
            <div class="event-content">
                <div class="event-header">
                    <span class="event-title">${this.escapeHtml(event.title)}</span>
                    <span class="event-time">${this.formatTime(event.timestamp)}</span>
                </div>
                <p class="event-description">${this.escapeHtml(event.description)}</p>
            </div>
        `;
        
        return item;
    },
    
    // Modal methods
    showModal(id) {
        document.getElementById(id).classList.add('show');
    },
    
    closeModal(id) {
        document.getElementById(id).classList.remove('show');
    },
    
    // Tunnel actions
    async editTunnel(id) {
        try {
            const response = await fetch(`/api/tunnels/${id}`);
            const tunnel = await response.json();
            
            // Populate form
            const form = document.getElementById('edit-tunnel-form');
            form.action = `/api/tunnels/${id}`;
            
            for (const [key, value] of Object.entries(tunnel)) {
                const input = form.elements[key];
                if (input) {
                    if (input.type === 'checkbox') {
                        input.checked = value;
                    } else {
                        input.value = value;
                    }
                }
            }
            
            this.showModal('edit-tunnel');
            
        } catch (error) {
            console.error('Failed to load tunnel:', error);
            this.showError('Failed to load tunnel data');
        }
    },
    
    async toggleTunnel(id, active) {
        try {
            await fetch(`/api/tunnels/${id}/${active ? 'start' : 'stop'}`, {
                method: 'POST'
            });
            
            this.refreshDashboard();
            
        } catch (error) {
            console.error('Failed to toggle tunnel:', error);
            this.showError(`Failed to ${active ? 'start' : 'stop'} tunnel`);
        }
    },
    
    async deleteTunnel(id) {
        if (!confirm('Are you sure you want to delete this tunnel?')) {
            return;
        }
        
        try {
            await fetch(`/api/tunnels/${id}`, {
                method: 'DELETE'
            });
            
            this.refreshDashboard();
            
        } catch (error) {
            console.error('Failed to delete tunnel:', error);
            this.showError('Failed to delete tunnel');
        }
    },
    
    // Event actions
    async clearEvents() {
        if (!confirm('Are you sure you want to clear all events?')) {
            return;
        }
        
        try {
            await fetch('/api/events', {
                method: 'DELETE'
            });
            
            document.getElementById('events-list').innerHTML = '';
            
        } catch (error) {
            console.error('Failed to clear events:', error);
            this.showError('Failed to clear events');
        }
    },
    
    // Utility methods
    formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },
    
    formatDuration(seconds) {
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
        return Math.floor(seconds / 86400) + 'd';
    },
    
    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = (now - date) / 1000;
        
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return date.toLocaleDateString();
    },
    
    formatValue(value, type) {
        switch (type) {
            case 'traffic':
            case 'totalTraffic':
                return this.formatBytes(value);
            case 'avgResponseTime':
                return value + ' ms';
            case 'errorRate':
                return value.toFixed(1) + '%';
            default:
                return value.toString();
        }
    },
    
    getEventIconClass(type) {
        switch (type) {
            case 'error': return 'event-icon-error';
            case 'warning': return 'event-icon-warning';
            case 'success': return 'event-icon-success';
            default: return 'event-icon-info';
        }
    },
    
    getEventIcon(type) {
        switch (type) {
            case 'error': return 'fa-exclamation-circle';
            case 'warning': return 'fa-exclamation-triangle';
            case 'success': return 'fa-check-circle';
            default: return 'fa-info-circle';
        }
    },
    
    escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    },
    
    showError(message) {
        // Implement your preferred error notification method
        alert(message);
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => app.init());
