/**
 * API Client for HixTunnel
 */

import HixUtils from './utils.js';

class ApiClient {
    constructor() {
        this.baseUrl = '/api';
        this.token = localStorage.getItem('auth_token');
    }

    /**
     * Set authentication token
     * @param {string} token - JWT token
     */
    setToken(token) {
        this.token = token;
        localStorage.setItem('auth_token', token);
    }

    /**
     * Clear authentication token
     */
    clearToken() {
        this.token = null;
        localStorage.removeItem('auth_token');
    }

    /**
     * Get request headers
     * @returns {Object} Headers object
     */
    getHeaders() {
        const headers = {
            'Content-Type': 'application/json'
        };

        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }

        return headers;
    }

    /**
     * Make API request
     * @param {string} method - HTTP method
     * @param {string} endpoint - API endpoint
     * @param {Object} data - Request data
     * @returns {Promise} API response
     */
    async request(method, endpoint, data = null) {
        try {
            const options = {
                method,
                headers: this.getHeaders()
            };

            if (data && method !== 'GET') {
                options.body = JSON.stringify(data);
            }

            const response = await fetch(`${this.baseUrl}${endpoint}`, options);
            const responseData = await response.json();

            if (!response.ok) {
                throw new Error(responseData.message || 'API request failed');
            }

            return responseData;
        } catch (error) {
            throw new Error(HixUtils.formatError(error));
        }
    }

    // Auth endpoints
    async login(email, password) {
        const response = await this.request('POST', '/auth/login', { email, password });
        this.setToken(response.token);
        return response;
    }

    async register(userData) {
        return await this.request('POST', '/auth/register', userData);
    }

    async logout() {
        await this.request('POST', '/auth/logout');
        this.clearToken();
    }

    async forgotPassword(email) {
        return await this.request('POST', '/auth/forgot-password', { email });
    }

    async resetPassword(token, password) {
        return await this.request('POST', '/auth/reset-password', { token, password });
    }

    // User endpoints
    async getCurrentUser() {
        return await this.request('GET', '/user');
    }

    async updateProfile(userData) {
        return await this.request('PUT', '/user/profile', userData);
    }

    async changePassword(oldPassword, newPassword) {
        return await this.request('PUT', '/user/password', { oldPassword, newPassword });
    }

    // Tunnel endpoints
    async getTunnels() {
        return await this.request('GET', '/tunnels');
    }

    async createTunnel(tunnelData) {
        return await this.request('POST', '/tunnels', tunnelData);
    }

    async getTunnel(tunnelId) {
        return await this.request('GET', `/tunnels/${tunnelId}`);
    }

    async updateTunnel(tunnelId, tunnelData) {
        return await this.request('PUT', `/tunnels/${tunnelId}`, tunnelData);
    }

    async deleteTunnel(tunnelId) {
        return await this.request('DELETE', `/tunnels/${tunnelId}`);
    }

    async startTunnel(tunnelId) {
        return await this.request('POST', `/tunnels/${tunnelId}/start`);
    }

    async stopTunnel(tunnelId) {
        return await this.request('POST', `/tunnels/${tunnelId}/stop`);
    }

    async getTunnelMetrics(tunnelId, period = '1h') {
        return await this.request('GET', `/tunnels/${tunnelId}/metrics?period=${period}`);
    }

    async getTunnelLogs(tunnelId, limit = 100) {
        return await this.request('GET', `/tunnels/${tunnelId}/logs?limit=${limit}`);
    }

    // API Token endpoints
    async getApiTokens() {
        return await this.request('GET', '/api-tokens');
    }

    async createApiToken(name, permissions) {
        return await this.request('POST', '/api-tokens', { name, permissions });
    }

    async deleteApiToken(tokenId) {
        return await this.request('DELETE', `/api-tokens/${tokenId}`);
    }

    // Settings endpoints
    async getSettings() {
        return await this.request('GET', '/settings');
    }

    async updateSettings(settings) {
        return await this.request('PUT', '/settings', settings);
    }

    // Admin endpoints
    async getUsers() {
        return await this.request('GET', '/admin/users');
    }

    async createUser(userData) {
        return await this.request('POST', '/admin/users', userData);
    }

    async updateUser(userId, userData) {
        return await this.request('PUT', `/admin/users/${userId}`, userData);
    }

    async deleteUser(userId) {
        return await this.request('DELETE', `/admin/users/${userId}`);
    }

    async getSystemMetrics() {
        return await this.request('GET', '/admin/metrics');
    }

    async getAuditLogs() {
        return await this.request('GET', '/admin/audit-logs');
    }
}

// Create and export singleton instance
const api = new ApiClient();
export default api;
