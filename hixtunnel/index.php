<?php require_once 'includes/header.php'; ?>

<div class="px-4 py-5 my-5 text-center">
    <h1 class="display-5 fw-bold text-body-emphasis">Welcome to HixTunnel</h1>
    <div class="col-lg-6 mx-auto">
        <p class="lead mb-4">
            Secure and fast tunneling solution for your local services.
            Expose your localhost to the internet securely.
        </p>
        <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
            <?php if (!isLoggedIn()): ?>
                <a href="/registration.php" class="btn btn-primary btn-lg px-4 gap-3">Get Started</a>
                <a href="/login.php" class="btn btn-outline-secondary btn-lg px-4">Login</a>
            <?php else: ?>
                <a href="/dashboard" class="btn btn-primary btn-lg px-4 gap-3">Go to Dashboard</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container px-4 py-5">
    <h2 class="pb-2 border-bottom">Features</h2>
    <div class="row g-4 py-5 row-cols-1 row-cols-lg-3">
        <div class="col d-flex align-items-start">
            <div class="icon-square text-body-emphasis bg-body-secondary d-inline-flex align-items-center justify-content-center fs-4 flex-shrink-0 me-3">
                <svg class="bi" width="1em" height="1em"><use xlink:href="#toggles2"/></svg>
            </div>
            <div>
                <h3 class="fs-2 text-body-emphasis">HTTP Tunnels</h3>
                <p>Create secure HTTP tunnels to expose your web services to the internet.</p>
            </div>
        </div>
        <div class="col d-flex align-items-start">
            <div class="icon-square text-body-emphasis bg-body-secondary d-inline-flex align-items-center justify-content-center fs-4 flex-shrink-0 me-3">
                <svg class="bi" width="1em" height="1em"><use xlink:href="#cpu-fill"/></svg>
            </div>
            <div>
                <h3 class="fs-2 text-body-emphasis">TCP Tunnels</h3>
                <p>Tunnel any TCP service through our secure network.</p>
            </div>
        </div>
        <div class="col d-flex align-items-start">
            <div class="icon-square text-body-emphasis bg-body-secondary d-inline-flex align-items-center justify-content-center fs-4 flex-shrink-0 me-3">
                <svg class="bi" width="1em" height="1em"><use xlink:href="#tools"/></svg>
            </div>
            <div>
                <h3 class="fs-2 text-body-emphasis">Custom Domains</h3>
                <p>Use your own domain names with our tunneling service.</p>
            </div>
        </div>
    </div>
</div>

<div class="container px-4 py-5">
    <h2 class="pb-2 border-bottom">How It Works</h2>
    <div class="row g-4 py-5">
        <div class="col-12">
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <h4>Sign Up</h4>
                    <p>Create your free account to get started.</p>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <h4>Install Client</h4>
                    <p>Download and install our client software.</p>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <h4>Create Tunnel</h4>
                    <p>Create a tunnel to your local service.</p>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <h4>Share Access</h4>
                    <p>Share your tunnel URL with others.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>