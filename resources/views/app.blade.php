<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f4ee">
    <title>Gather | Find your next good thing</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="topbar">
        <a class="wordmark" href="/" aria-label="Gather home"><span class="brand-mark">g</span>gather<span class="wordmark-dot">.</span></a>
        <nav class="primary-nav" aria-label="Main navigation">
            <button class="nav-link is-active" type="button" data-nav="discover">Discover</button>
            <button class="nav-link" type="button" data-nav="tickets">My tickets</button>
            <button class="nav-link" type="button" data-nav="organizer">Organizer desk</button>
               <button class="nav-link is-hidden" id="gate-nav-button" type="button" data-nav="gate">Gate operations</button>
        </nav>
        <div class="account-nav">
            <span class="account-name" id="account-name"></span>
            <button class="button button-quiet" id="auth-open" type="button">Sign in</button>
            <button class="button button-dark is-hidden" id="logout-button" type="button">Sign out</button>
            <button class="button button-cart" id="cart-open" type="button" aria-label="Open ticket basket">Basket <span id="cart-count">0</span></button>
        </div>
    </header>

    <main>
        <section class="view is-visible" id="discover-view" data-view="discover">
            <div class="intro-grid">
                <div class="intro-copy">
                    <p class="eyebrow"><span class="eyebrow-line"></span> FIND YOUR PEOPLE, FIND YOUR PLANS</p>
                    <h1>Make room for<br><em>a good time.</em></h1>
                    <p class="intro-description">Small rooms, big nights, new ideas. Find the things happening around you and save your place.</p>
                    <a class="text-link" href="#event-list">Explore what's on <span aria-hidden="true">↓</span></a>
                </div>
                <div class="feature-art" aria-label="Live music audience">
                    <div class="feature-photo"></div>
                    <div class="feature-caption"><span>LOCAL, LIVE, AND ALREADY HAPPENING</span><strong>Your next story starts here.</strong></div>
                    <div class="feature-index">01 <span>/</span> 04</div>
                </div>
            </div>

            <div class="discovery-bar" id="event-list">
                <div class="discovery-heading"><p class="eyebrow">THE LINEUP</p><h2>Find an event</h2></div>
                <form class="filters" id="event-filters">
                    <label class="filter-search"><span>Search events</span><input name="name" placeholder="Artist, idea, or event" autocomplete="off"></label>
                    <label><span>Town</span><input name="town" placeholder="Any town"></label>
                    <label><span>From</span><input name="date_from" type="date"></label>
                    <label><span>To</span><input name="date_to" type="date"></label>
                    <label><span>Min price (XAF)</span><input name="min_price_xaf" type="number" min="0" step="1" placeholder="0"></label>
                    <label><span>Max price (XAF)</span><input name="max_price_xaf" type="number" min="0" step="1" placeholder="Any"></label>
                    <button class="button button-dark filter-submit" type="submit">Search events</button>
                </form>
            </div>
            <div class="event-meta"><span id="event-count">Loading events</span><span>Published events · newest date first</span></div>
            <div class="event-grid" id="event-grid" aria-live="polite"></div>
            <div class="pagination" id="event-pagination"></div>
        </section>

        <section class="view content-view" id="tickets-view" data-view="tickets" aria-labelledby="tickets-title">
            <div class="section-heading"><div><p class="eyebrow">YOUR PLANS</p><h1 id="tickets-title">My tickets</h1><p>Reservations, checkout status, and tickets in one place.</p></div><button class="button button-outline" type="button" data-nav="discover">Browse events</button></div>
            <section class="ticket-wallet-section is-hidden" id="ticket-wallet-section" aria-labelledby="ticket-wallet-title">
                <div class="ticket-wallet-heading"><div><p class="eyebrow">MODULE C</p><h2 id="ticket-wallet-title">Ticket wallet</h2></div><span class="muted" id="ticket-count"></span></div>
                <div class="ticket-grid" id="attendee-tickets" aria-live="polite"></div>
                <div class="ticket-pagination" id="ticket-pagination"></div>
            </section>
            <h2 class="orders-heading">Orders and refunds</h2>
            <div id="ticket-orders" class="order-list"><div class="loading-line">Sign in to see your orders.</div></div>
        </section>

        <section class="view content-view" id="organizer-view" data-view="organizer" aria-labelledby="organizer-title">
            <div class="section-heading"><div><p class="eyebrow">ORGANIZER WORKSPACE</p><h1 id="organizer-title">Organizer desk</h1><p>Manage events, ticket inventory, and sales.</p></div><div class="summary-strip" id="sales-summary"></div></div>
            <div id="organizer-content"><div class="empty-state">Sign in with a verified organizer account to manage your events.</div></div>
        </section>
            <section class="view content-view gate-view" id="gate-view" data-view="gate" aria-labelledby="gate-title">
                <div class="section-heading"><div><p class="eyebrow">ADMISSIONS</p><h1 id="gate-title">Gate operations</h1><p>Scan tickets only for events and gates assigned to your account.</p></div><span class="status-pill" id="gate-network-status">Checking connection</span></div>
                <div id="gate-workspace">
                    <div class="scan-context">
                        <label>Assigned event<select id="scan-event-select" required></select></label>
                        <label>Active gate<select id="scan-gate-select" required></select></label>
                        <label>Registered device<select id="scan-device-select"><option value="">Online scan only</option></select></label>
                    </div>
                    <div class="scan-toolbar"><div class="scan-mode" role="group" aria-label="Scan mode"><button class="is-active" type="button" data-scan-mode="online">Online</button><button type="button" data-scan-mode="offline">Offline</button></div><div class="scan-actions"><button class="small-button" id="snapshot-download" type="button">Download verified roster</button><button class="small-button" id="scan-sync" type="button">Sync pending scans</button></div></div>
                    <p class="form-message" id="snapshot-status" role="status"></p>
                    <div class="scanner-panel">
                        <div class="camera-frame is-hidden" id="camera-frame"><video id="scan-video" autoplay muted playsinline aria-label="Ticket QR camera preview"></video></div>
                        <div class="scanner-actions"><button class="small-button" id="camera-start" type="button">Start camera scanner</button><button class="small-button is-hidden" id="camera-stop" type="button">Stop camera</button></div>
                        <form id="scan-form" class="scan-form"><label>Ticket code<textarea name="credential" required maxlength="4096" rows="3" autocomplete="off" autocapitalize="off" spellcheck="false"></textarea></label><button class="button button-dark" id="scan-submit" type="submit">Check ticket</button></form>
                    </div>
                    <div class="scan-result" id="scan-result" role="status" aria-live="polite"></div>
                    <section class="scan-queue-section" aria-labelledby="scan-queue-title"><div class="organizer-toolbar"><strong id="scan-queue-title">Offline scan queue</strong><span class="muted" id="scan-queue-count">0 pending</span></div><div id="scan-queue"></div></section>
                </div>
            </section>

        <section class="view content-view return-view" id="return-view" data-view="return" aria-live="polite">
            <p class="eyebrow">CHECKOUT</p><h1 id="return-title">Checking your return</h1><p id="return-message">Payment status is confirmed by Stripe's webhook. Your order will appear in My tickets once it has been processed.</p><button class="button button-dark" type="button" data-nav="tickets">View my tickets</button>
        </section>

        <section class="view content-view reset-view" id="reset-view" data-view="reset">
            <div class="auth-card"><p class="eyebrow">ACCOUNT SECURITY</p><h1>Choose a new password</h1><form id="reset-form" class="form-stack"><label>Email address<input type="email" name="email" required autocomplete="email"></label><label>New password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label><label>Confirm password<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label><button class="button button-dark" type="submit">Update password</button></form><p class="form-message" id="reset-message" role="status"></p></div>
        </section>

        <section class="view content-view invitation-view" id="gate-invitation-view" data-view="gate-invitation" aria-labelledby="gate-invitation-title">
            <div class="auth-card"><p class="eyebrow">GATE ACCESS</p><h1 id="gate-invitation-title">Accept your invitation</h1>
                <p id="gate-invitation-message">Use the invited email address to accept access.</p>
                <form id="gate-invitation-form" class="form-stack">
                    <div id="invitation-account-fields"><label>Your name<input name="name" required autocomplete="name" maxlength="255"></label><label>Password<input type="password" name="password" required autocomplete="new-password" minlength="8"></label><label>Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" minlength="8"></label></div>
                    <button class="button button-dark" id="gate-invitation-submit" type="submit">Create account and accept</button>
                </form>
                <button class="small-button" id="gate-invitation-signin" type="button">Sign in to an existing account</button>
                <p class="form-message" id="gate-invitation-status" role="status"></p>
            </div>
        </section>
    </main>

    <footer class="site-footer"><a class="wordmark" href="/"><span class="brand-mark">g</span>gather<span class="wordmark-dot">.</span></a><span>Good things happen when we get together.</span><span id="footer-year"></span></footer>

    <dialog class="dialog auth-dialog" id="auth-dialog">
        <button class="dialog-close" type="button" data-close-dialog="auth-dialog" aria-label="Close">Close</button>
        <div class="dialog-tabs"><button class="dialog-tab is-active" type="button" data-auth-mode="login">Sign in</button><button class="dialog-tab" type="button" data-auth-mode="register">Create account</button></div>
        <div class="auth-card-inner" id="auth-panel"></div>
    </dialog>

    <dialog class="dialog event-dialog" id="event-dialog">
        <button class="dialog-close" type="button" data-close-dialog="event-dialog" aria-label="Close">Close</button>
        <div id="event-detail"></div>
    </dialog>

    <dialog class="dialog ticket-dialog" id="ticket-detail-dialog" aria-labelledby="ticket-detail-title">
        <button class="dialog-close" type="button" data-close-dialog="ticket-detail-dialog" aria-label="Close ticket details">Close</button>
        <div id="ticket-detail"></div>
    </dialog>

    <aside class="cart-drawer" id="cart-drawer" aria-label="Ticket basket" aria-hidden="true">
        <div class="drawer-heading"><div><p class="eyebrow">YOUR RESERVATION</p><h2>Ticket basket</h2></div><button class="dialog-close" id="cart-close" type="button" aria-label="Close basket">Close</button></div>
        <p class="drawer-note">Your tickets are held for 10 minutes. Complete checkout before the timer runs out.</p>
        <div id="cart-items" class="cart-items"></div>
        <div class="cart-total"><span>Estimated total</span><strong id="cart-total">0 XAF</strong></div>
        <button class="button button-accent checkout-button" id="checkout-button" type="button">Continue to secure checkout</button>
        <button class="text-link drawer-clear" id="cart-clear" type="button">Release all holds</button>
    </aside>
    <div class="scrim" id="scrim"></div>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
