const { getServerUrl, getServerHost } = require('./config');
const chalk = require('chalk');

const BOX = {
    topLeft: '╔',
    topRight: '╗',
    bottomLeft: '╚',
    bottomRight: '╝',
    horizontal: '═',
    vertical: '║'
};

const centerText = (text, width, extraSpaces = 0) => {
    const effectiveWidth = width - extraSpaces;
    const padding = Math.max(0, effectiveWidth - text.length);
    const leftPad = Math.floor(padding / 2) + extraSpaces;
    const rightPad = padding - Math.floor(padding / 2);
    return ' '.repeat(leftPad) + text + ' '.repeat(rightPad);
};

const createLine = (width) => BOX.horizontal.repeat(width);

const padRight = (text, width, minPadding = 2) => {
    const padding = Math.max(minPadding, width - text.length);
    return text + ' '.repeat(padding);
};

const formatBytes = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = bytes;
    let unitIndex = 0;
    
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    
    return `${size.toFixed(2)} ${units[unitIndex]}`;
};

const displayStatus = (username, protocol, localPort, localHost, publicPort) => {
    console.clear();
    const width = 80;  // Total width
    const extraPadding = 10; // Extra padding for specific lines
    
    // Create the box
    console.log(BOX.topLeft + createLine(width - 2) + BOX.topRight);
    
    // Title
    console.log(BOX.vertical + centerText(chalk.cyan('HIXTUNNEL STATUS'), width - 2) + BOX.vertical);
    console.log(BOX.vertical + createLine(width - 2) + BOX.vertical);
    
    // User info
    console.log(BOX.vertical + centerText(`User: ${chalk.yellow(username)}`, width - 2) + BOX.vertical);
    
    // Connection details
    console.log(BOX.vertical + centerText('Connection Details', width - 2) + BOX.vertical);
    console.log(BOX.vertical + centerText(`Protocol: ${chalk.green(protocol.toUpperCase())}`, width - 2, extraPadding) + BOX.vertical);
    console.log(BOX.vertical + centerText(`Local: ${chalk.green(`${localHost}:${localPort}`)}`, width - 2, extraPadding) + BOX.vertical);
    console.log(BOX.vertical + centerText(`Public: ${chalk.green(`${getServerHost()}:${publicPort}`)}`, width - 2, extraPadding) + BOX.vertical);
    
    // Bottom of box
    console.log(BOX.bottomLeft + createLine(width - 2) + BOX.bottomRight);
    
    // Additional info
    console.log('\nPress Ctrl+C to stop the tunnel');
};

const displayMetrics = (metrics) => {
    console.clear();
    const width = 80;
    
    console.log(BOX.topLeft + createLine(width - 2) + BOX.topRight);
    
    // Title
    console.log(BOX.vertical + centerText(chalk.cyan('TUNNEL METRICS'), width - 2) + BOX.vertical);
    console.log(BOX.vertical + createLine(width - 2) + BOX.vertical);
    
    // Traffic
    console.log(BOX.vertical + centerText('Traffic', width - 2) + BOX.vertical);
    console.log(BOX.vertical + centerText(`In: ${chalk.green(formatBytes(metrics.bytesIn))}  Out: ${chalk.green(formatBytes(metrics.bytesOut))}`, width - 2) + BOX.vertical);
    
    // Requests
    console.log(BOX.vertical + centerText('Requests', width - 2) + BOX.vertical);
    console.log(BOX.vertical + centerText(`Total: ${chalk.green(metrics.requests)}  Errors: ${chalk.red(metrics.errors)}`, width - 2) + BOX.vertical);
    
    // Response Time
    console.log(BOX.vertical + centerText('Performance', width - 2) + BOX.vertical);
    console.log(BOX.vertical + centerText(`Avg Response Time: ${chalk.yellow(metrics.responseTime.toFixed(2))} ms`, width - 2) + BOX.vertical);
    
    // Bottom of box
    console.log(BOX.bottomLeft + createLine(width - 2) + BOX.bottomRight);
    
    // Additional info
    console.log('\nPress Ctrl+C to stop the tunnel');
};

const logRequest = (method, path, status) => {
    const timestamp = new Date().toISOString();
    const statusColor = status >= 500 ? chalk.red :
                       status >= 400 ? chalk.yellow :
                       status >= 300 ? chalk.cyan :
                       status >= 200 ? chalk.green :
                       chalk.white;
    
    console.log(`[${timestamp}] ${chalk.blue(method)} ${path} ${statusColor(status)}`);
};

module.exports = {
    displayStatus,
    logRequest,
    displayMetrics
};
