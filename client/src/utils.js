const requestCount = { value: 0 };

function displayStatus(username, localService, publicUrl) {
    console.clear();
    console.log(`Welcome user: "${username}"`);
    console.log(`Your service is at: ${localService}`);
    console.log(`Your service is now available at: ${publicUrl}`);
    console.log('BEST OF LUCK:');
    console.log('STATUS: (*) active');
    console.log('REQUESTS:');
    if (requestCount.value === 0) {
        console.log('No requests yet');
    }
}

function logRequest(method, path, status) {
    requestCount.value++;
    console.log(`${new Date().toISOString()} - ${method} ${path} - ${status}`);
}

module.exports = {
    displayStatus,
    logRequest,
    requestCount
};
