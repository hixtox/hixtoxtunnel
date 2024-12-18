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

const displayStatus = (username, protocol, localPort, localHost, publicPort) => {
    console.clear();
    const width = 80;  // Total width
    const extraPadding = 10; // Extra padding for specific lines
    const server = getServerHost();
    const localUrl = protocol === 'tcp' ? `tcp://${localHost}:${localPort}` : `http://${localHost}:${localPort}`;
    const publicUrl = protocol === 'tcp' ? `tcp://${server}:${publicPort}` : `http://${server}:${publicPort}`;

    // Header
    console.log(chalk.cyan(BOX.topLeft + createLine(width) + BOX.topRight));
    
    // Title section with adjusted spacing
    console.log(chalk.cyan(BOX.vertical) + chalk.yellow(centerText('{FREE SYRIA & FREE PALESTINE}', width)) + chalk.cyan(BOX.vertical));
    console.log(chalk.cyan(BOX.vertical) + chalk.magenta(centerText('H !  X  T  0  X   L  A  B', width, 2)) + chalk.cyan(BOX.vertical));
    console.log(chalk.cyan(BOX.vertical) + chalk.blue(centerText('HixTunnel v1.0.2', width, 3)) + chalk.cyan(BOX.vertical));
    console.log(chalk.cyan(BOX.vertical) + createLine(width) + chalk.cyan(BOX.vertical));

    // Status section with extra padding
    const statusText = `Status: ${chalk.green('●')} ACTIVE`;
    const protocolText = `Protocol: ${protocol.toUpperCase()}`;
    const userText = `Welcome user: "${chalk.green(username)}"`;

    console.log(chalk.cyan(BOX.vertical) + chalk.white(padRight(statusText, width)) + ' '.repeat(extraPadding) + chalk.cyan(BOX.vertical));
    console.log(chalk.cyan(BOX.vertical) + chalk.white(padRight(protocolText, width)) + chalk.cyan(BOX.vertical));
    console.log(chalk.cyan(BOX.vertical) + chalk.white(padRight(userText, width)) + ' '.repeat(extraPadding) + chalk.cyan(BOX.vertical));
    console.log(chalk.cyan(BOX.vertical) + createLine(width) + chalk.cyan(BOX.vertical));

    // URLs section with extra padding
    const hostText = `Your host:    ${chalk.yellow(localUrl)}`;
    const tunnelText = `Tunneling at: ${chalk.green(publicUrl)}`;

    console.log(chalk.cyan(BOX.vertical) + chalk.white(padRight(hostText, width)) + ' '.repeat(extraPadding) + chalk.cyan(BOX.vertical));
    console.log(chalk.cyan(BOX.vertical) + chalk.white(padRight(tunnelText, width)) + ' '.repeat(extraPadding) + chalk.cyan(BOX.vertical));
    
    // Footer
    console.log(chalk.cyan(BOX.bottomLeft + createLine(width) + BOX.bottomRight));
    
    // Requests section
    const plusLine = '+'.repeat(width + 2); // +2 to match the box width including borders
    console.log(chalk.yellow(plusLine));
    console.log(chalk.yellow('REQUESTS:'));
    console.log(chalk.yellow(plusLine));
    console.log('No requests yet');
};

const logRequest = (method, path, status) => {
    const timestamp = new Date().toISOString();
    const statusColor = status >= 200 && status < 300 ? chalk.green : 
                       status >= 300 && status < 400 ? chalk.yellow :
                       chalk.red;
    
    console.log(
        chalk.gray(timestamp) + ' - ' +
        chalk.cyan(method.padEnd(7)) +
        chalk.white(path) + ' - ' +
        statusColor(status)
    );
};

module.exports = {
    displayStatus,
    logRequest
};
