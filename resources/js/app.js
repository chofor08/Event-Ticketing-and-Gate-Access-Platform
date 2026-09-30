import QRCode from 'qrcode';

const API = '/api';
const TOKEN_KEY = 'gather.access-token';
const TOKEN_ISSUED_AT_KEY = 'gather.access-token-issued-at';
const CART_KEY = 'gather.ticket-holds-xaf-v1';
const TOKEN_LIFETIME_MS = 7 * 24 * 60 * 60 * 1000;
const GATE_DATABASE_NAME = 'gather-gate-client-v1';
const GATE_QUEUE_STORE = 'scan-queue';
const GATE_SNAPSHOT_STORE = 'snapshots';
const GATE_KEY_STORE = 'keys';
const photos = [
	'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=1000&q=80',
	'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=1000&q=80',
	'https://images.unsplash.com/photo-1506157786151-b8491531f063?auto=format&fit=crop&w=1000&q=80',
	'https://images.unsplash.com/photo-1501386761578-eac5c94b800a?auto=format&fit=crop&w=1000&q=80',
];

const state = {
	token: sessionStorage.getItem(TOKEN_KEY),
	user: null,
	events: [],
	tickets: [],
	ticketPage: 1,
	ticketQrRender: 0,
	invitationToken: null,
	cart: JSON.parse(sessionStorage.getItem(CART_KEY) || '[]'),
	page: 1,
	pendingHold: null,
	toastTimer: null,
	holdTimer: null,
	gate: {
		assignments: [],
		eventId: '',
		gateId: '',
		deviceId: '',
		mode: 'online',
		snapshot: null,
		roster: new Map(),
		localAdmissions: new Set(),
		stream: null,
		detector: null,
		frame: null,
		scanLocked: false,
		onlineAttempt: null,
	},
};

const byId = (id) => document.getElementById(id);
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
	'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[character]));
const formatMoney = (xaf) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Number(xaf) || 0)} XAF`;
const formatDate = (value, options = { month: 'short', day: 'numeric', year: 'numeric' }) => {
	if (!value) return 'Date to be announced';
	const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
	return Number.isNaN(date.getTime()) ? 'Date to be announced' : new Intl.DateTimeFormat('en-US', options).format(date);
};
const eventPhoto = (id) => photos[Math.abs(Number(id) || 0) % photos.length];

function showToast(message, error = false) {
	const toast = byId('toast');
	toast.textContent = message;
	toast.classList.toggle('is-error', error);
	toast.classList.add('is-visible');
	clearTimeout(state.toastTimer);
	state.toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 4200);
}

function errorText(error) {
	if (error?.payload?.errors) {
		return Object.values(error.payload.errors).flat().join(' ');
	}
	return error?.payload?.message || error?.message || 'Something went wrong. Please try again.';
}

async function api(path, options = {}) {
	const headers = { Accept: 'application/json', ...(options.headers || {}) };
	if (state.token) headers.Authorization = `Bearer ${state.token}`;
	const init = { method: options.method || 'GET', headers };
	if (options.body !== undefined) {
		headers['Content-Type'] = 'application/json';
		init.body = JSON.stringify(options.body);
	}
	const response = await fetch(`${API}${path}`, init);
	const text = response.status === 204 ? '' : await response.text();
	let payload = {};
	if (text) {
		try { payload = JSON.parse(text); } catch { payload = { message: text }; }
	}
	if (!response.ok) {
		if (response.status === 401 && state.token) {
			clearSession(false);
			if (location.pathname === '/gate-invitation/accept') renderInvitationForm();
			showToast('Your session expired or needs re-authentication. Sign in again to continue.', true);
		}
		const error = new Error(payload.message || `Request failed (${response.status}).`);
		error.status = response.status;
		error.payload = payload;
		throw error;
	}
	return payload;
}

function saveSession(token, user) {
	state.token = token;
	state.user = user;
	sessionStorage.setItem(TOKEN_KEY, token);
	sessionStorage.setItem(TOKEN_ISSUED_AT_KEY, String(Date.now()));
	updateAccountControls();
}

function clearSession(showMessage = true) {
	state.token = null;
	state.user = null;
	sessionStorage.removeItem(TOKEN_KEY);
	sessionStorage.removeItem(TOKEN_ISSUED_AT_KEY);
	clearTicketWallet();
	clearGateSession();
	updateAccountControls();
	if (showMessage) showToast('You have signed out.');
}

function clearGateSession() {
	stopGateCamera();
	state.gate.assignments = [];
	state.gate.snapshot = null;
	state.gate.roster.clear();
	state.gate.localAdmissions.clear();
	byId('scan-form')?.reset();
	if (location.pathname !== '/gate-invitation/accept' && byId('gate-workspace')) {
		byId('gate-workspace').innerHTML = '<div class="empty-state">Sign in with an assigned event account to use gate operations.</div>';
	}
}

function clearTicketWallet() {
	state.tickets = [];
	byId('ticket-wallet-section').classList.add('is-hidden');
	byId('attendee-tickets').replaceChildren();
	byId('ticket-pagination').replaceChildren();
	byId('ticket-count').textContent = '';
	byId('ticket-detail').replaceChildren();
}

function updateAccountControls() {
	const isSignedIn = Boolean(state.user && state.token);
	byId('auth-open').classList.toggle('is-hidden', isSignedIn);
	byId('logout-button').classList.toggle('is-hidden', !isSignedIn);
	byId('gate-nav-button').classList.toggle('is-hidden', !isSignedIn);
	byId('account-name').textContent = isSignedIn ? state.user.name : '';
	byId('footer-year').textContent = new Date().getFullYear();
}

function showView(name) {
	document.querySelectorAll('[data-view]').forEach((view) => view.classList.toggle('is-visible', view.dataset.view === name));
	document.querySelectorAll('[data-nav]').forEach((button) => button.classList.toggle('is-active', button.dataset.nav === name));
	if (name === 'tickets') loadOrders();
	if (name === 'organizer') loadOrganizerWorkspace();
	if (name === 'gate') loadGateWorkspace();
	window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openDialog(id) {
	const dialog = byId(id);
	if (!dialog.open) dialog.showModal();
}

function closeDialog(id) {
	const dialog = byId(id);
	if (dialog.open) dialog.close();
}

function setMessage(element, message, type = '') {
	element.textContent = message;
	element.classList.toggle('is-error', type === 'error');
	element.classList.toggle('is-success', type === 'success');
}

async function loadEvents(page = state.page) {
	const form = byId('event-filters');
	const params = new URLSearchParams(new FormData(form));
	params.set('page', String(page));
	byId('event-grid').innerHTML = '<div class="loading-line">Finding events...</div>';
	try {
		const response = await api(`/events?${params.toString()}`);
		const paginator = response.events;
		state.events = paginator.data || [];
		state.page = paginator.current_page || 1;
		byId('event-count').textContent = `${paginator.total ?? state.events.length} event${paginator.total === 1 ? '' : 's'} found`;
		renderEvents();
		renderPagination(paginator);
	} catch (error) {
		byId('event-count').textContent = 'Events unavailable';
		byId('event-grid').innerHTML = `<div class="empty-state">${escapeHtml(errorText(error))}</div>`;
	}
}

function renderEvents() {
	const grid = byId('event-grid');
	if (!state.events.length) {
		grid.innerHTML = '<div class="empty-state">No events match those filters. Try a different town or date.</div>';
		return;
	}
	grid.innerHTML = state.events.map((event, index) => {
		const tickets = event.ticket_types || [];
		const prices = tickets.map((ticket) => Number(ticket.price_xaf ?? ticket.base_price_xaf));
		const available = tickets.reduce((sum, ticket) => sum + Math.max(0, Number(ticket.remaining ?? ticket.quantity)), 0);
		const firstPrice = prices.length ? Math.min(...prices) : null;
		const date = new Date(`${String(event.date).slice(0, 10)}T00:00:00`);
		const dateDay = Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-US', { day: '2-digit' }).format(date);
		const dateMonth = Number.isNaN(date.getTime()) ? 'TBA' : new Intl.DateTimeFormat('en-US', { month: 'short' }).format(date);
		return `<article class="event-card" style="animation-delay:${Math.min(index * 50, 250)}ms">
			<div class="event-image" style="background-image:linear-gradient(0deg,rgba(20,35,30,.16),rgba(20,35,30,0)),url('${eventPhoto(event.id)}')">
				<div class="event-date-chip"><b>${dateDay}</b><span>${dateMonth}</span></div>
			</div>
			<div class="event-body">
				<p class="event-kicker">${escapeHtml(event.town || 'Local event')} · ${escapeHtml(event.start_time?.slice(0, 5) || 'Time TBA')}</p>
				<h3>${escapeHtml(event.title)}</h3>
				<p class="event-place">${escapeHtml(event.venue)} · ${escapeHtml(event.town)}</p>
				<div class="event-card-footer">
					<span class="event-price"><small>${available} ${available === 1 ? 'ticket' : 'tickets'} available</small>${firstPrice === null ? 'Price TBA' : `From ${formatMoney(firstPrice)}`}</span>
					<button class="event-open" type="button" data-event-open="${Number(event.id)}">View event</button>
				</div>
			</div>
		</article>`;
	}).join('');
}

function renderPagination(paginator) {
	const container = byId('event-pagination');
	if (!paginator || (paginator.last_page || 1) <= 1) {
		container.innerHTML = '';
		return;
	}
	const pages = [];
	for (let page = 1; page <= paginator.last_page; page += 1) {
		pages.push(`<button type="button" data-page="${page}" ${page === paginator.current_page ? 'aria-current="page"' : ''}>${page}</button>`);
	}
	container.innerHTML = pages.join('');
}

function openEvent(eventId) {
	const event = state.events.find((item) => Number(item.id) === Number(eventId));
	if (!event) return;
	const tickets = event.ticket_types || [];
	byId('event-detail').innerHTML = `<div class="detail-photo" style="background-image:linear-gradient(0deg,rgba(20,35,30,.12),rgba(20,35,30,.02)),url('${eventPhoto(event.id)}')"></div>
		<div class="detail-head"><div><p class="eyebrow">${escapeHtml(event.town)} · ${escapeHtml(formatDate(event.date))}</p><h2>${escapeHtml(event.title)}</h2></div><span class="status-pill">${escapeHtml(event.start_time?.slice(0, 5) || 'Time TBA')}</span></div>
		<p class="event-place">${escapeHtml(event.venue)} · ${escapeHtml(event.town)}</p>
		<p class="detail-description">${escapeHtml(event.description || 'Join your local community for this upcoming event.')}</p>
		<div class="detail-tickets">${tickets.length ? tickets.map((ticket) => {
			const remaining = Math.max(0, Number(ticket.remaining ?? ticket.quantity));
				return `<div class="detail-ticket"><div><strong>${escapeHtml(ticket.name)}</strong><small>${formatMoney(ticket.price_xaf ?? ticket.base_price_xaf)} per ticket · ${remaining} remaining</small></div><strong>${formatMoney(ticket.price_xaf ?? ticket.base_price_xaf)}</strong><input type="number" min="0" max="${remaining}" value="0" step="1" aria-label="Quantity for ${escapeHtml(ticket.name)}" data-ticket-quantity="${Number(ticket.id)}" ${remaining < 1 ? 'disabled' : ''}></div>`;
		}).join('') : '<div class="empty-state">Ticket information is not available yet.</div>'}</div>
		<button class="button button-dark" type="button" data-add-event-holds="${Number(event.id)}" ${tickets.every((ticket) => Number(ticket.remaining ?? ticket.quantity) < 1) ? 'disabled' : ''}>Reserve selected tickets</button>`;
	openDialog('event-dialog');
}

async function addSelectedHolds(eventId) {
	const event = state.events.find((item) => Number(item.id) === Number(eventId));
	if (!event) return;
	const quantities = [...document.querySelectorAll('[data-ticket-quantity]')]
		.map((select) => ({ ticketTypeId: Number(select.dataset.ticketQuantity), quantity: Number(select.value) }))
		.filter((item) => item.quantity > 0);
	if (!quantities.length) {
		showToast('Choose at least one ticket first.', true);
		return;
	}
	if (quantities.length > 20) {
		showToast('A checkout can include tickets from up to 20 ticket types.', true);
		return;
	}
	if (!state.token) {
		state.pendingHold = { eventId: Number(eventId), quantities };
		closeDialog('event-dialog');
		setAuthMode('login');
		openDialog('auth-dialog');
		showToast('Sign in to reserve tickets.');
		return;
	}
	await createHolds(event, quantities);
}

async function createHolds(event, quantities) {
	try {
		for (const item of quantities) {
			const ticket = (event.ticket_types || []).find((entry) => Number(entry.id) === item.ticketTypeId);
			const response = await api(`/ticket-types/${item.ticketTypeId}/holds`, { method: 'POST', body: { quantity: item.quantity } });
			state.cart.push({
				id: response.hold.id,
				token: response.token,
				expires_at: response.hold.expires_at,
				event_id: event.id,
				event_title: event.title,
				event_date: event.date,
				ticket_type_id: item.ticketTypeId,
				ticket_name: ticket?.name || 'Ticket',
				quantity: item.quantity,
				unit_price_xaf: Number(ticket?.price_xaf ?? ticket?.base_price_xaf ?? 0),
			});
			persistCart();
		}
		closeDialog('event-dialog');
		renderCart();
		openCart();
		showToast('Your tickets are held for 10 minutes.');
		loadEvents();
	} catch (error) {
		renderCart();
		showToast(errorText(error), true);
	}
}

function persistCart() {
	sessionStorage.setItem(CART_KEY, JSON.stringify(state.cart));
	byId('cart-count').textContent = state.cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
}

function renderCart() {
	persistCart();
	const container = byId('cart-items');
	if (!state.cart.length) {
		container.innerHTML = '<div class="empty-state">Your basket is empty.</div>';
		byId('cart-total').textContent = formatMoney(0);
		byId('checkout-button').disabled = true;
		return;
	}
	byId('checkout-button').disabled = !state.token;
	container.innerHTML = state.cart.map((item) => `<article class="cart-item">
		<strong>${escapeHtml(item.event_title)}</strong><button class="cart-remove" type="button" data-remove-hold="${Number(item.id)}">Remove</button>
		<small>${escapeHtml(item.ticket_name)} · ${item.quantity} × ${formatMoney(item.unit_price_xaf)}</small><span class="muted">${escapeHtml(formatDate(item.event_date))}</span>
		<span class="hold-timer" data-hold-expiry="${Number(item.id)}">Held for 10:00</span>
	</article>`).join('');
	const total = state.cart.reduce((sum, item) => sum + (Number(item.unit_price_xaf) * Number(item.quantity)), 0);
	byId('cart-total').textContent = formatMoney(total);
	updateHoldTimers();
}

function updateHoldTimers() {
	const now = Date.now();
	const expired = [];
	document.querySelectorAll('[data-hold-expiry]').forEach((element) => {
		const id = Number(element.dataset.holdExpiry);
		const item = state.cart.find((hold) => Number(hold.id) === id);
		if (!item) return;
		const seconds = Math.max(0, Math.floor((new Date(item.expires_at).getTime() - now) / 1000));
		if (!seconds) {
			element.textContent = 'Reservation expired';
			expired.push(id);
		} else {
			element.textContent = `Held for ${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
		}
	});
	if (expired.length) {
		state.cart = state.cart.filter((item) => !expired.includes(Number(item.id)));
		persistCart();
		if (byId('cart-drawer').classList.contains('is-open')) renderCart();
		showToast('An expired reservation was removed from your basket.', true);
	}
}

function openCart() {
	byId('cart-drawer').classList.add('is-open');
	byId('cart-drawer').setAttribute('aria-hidden', 'false');
	byId('scrim').classList.add('is-visible');
	renderCart();
}

function closeCart() {
	byId('cart-drawer').classList.remove('is-open');
	byId('cart-drawer').setAttribute('aria-hidden', 'true');
	byId('scrim').classList.remove('is-visible');
}

async function releaseHold(holdId) {
	const item = state.cart.find((hold) => Number(hold.id) === Number(holdId));
	if (!item) return;
	try {
		await api(`/holds/${item.id}/release`, { method: 'POST', body: { token: item.token } });
	} catch (error) {
		if (error.status !== 422) showToast(errorText(error), true);
	}
	state.cart = state.cart.filter((hold) => Number(hold.id) !== Number(holdId));
	renderCart();
	loadEvents();
}

async function releaseAllHolds() {
	for (const item of [...state.cart]) await releaseHold(item.id);
}

// Keep retries for the same basket on one request key; a changed hold set gets a new key.
function checkoutIdempotencyKey() {
	const holdIds = state.cart.map((item) => Number(item.id)).sort((first, second) => first - second);
	const storageKey = `checkout-idempotency-${holdIds.join('-')}`;
	let key = sessionStorage.getItem(storageKey);
	if (!key) {
		key = crypto.randomUUID();
		sessionStorage.setItem(storageKey, key);
	}
	return key;
}

async function beginCheckout() {
	if (!state.token) {
		closeCart();
		setAuthMode('login');
		openDialog('auth-dialog');
		showToast('Sign in before checkout.');
		return;
	}
	if (!state.cart.length) return;
	const button = byId('checkout-button');
	button.disabled = true;
	button.textContent = 'Opening secure checkout...';
	try {
		const response = await api('/checkout', {
			method: 'POST',
			headers: { 'Idempotency-Key': checkoutIdempotencyKey() },
			body: { hold_ids: state.cart.map((item) => item.id) },
		});
		window.location.assign(response.checkout_url);
	} catch (error) {
		showToast(errorText(error), true);
		button.disabled = false;
		button.textContent = 'Continue to secure checkout';
		renderCart();
	}
}

function setAuthMode(mode) {
	const modes = ['login', 'register'];
	document.querySelectorAll('[data-auth-mode]').forEach((tab) => tab.classList.toggle('is-active', tab.dataset.authMode === mode));
	if (!modes.includes(mode)) {
		renderAuthForm(mode);
		return;
	}
	renderAuthForm(mode);
}

function renderAuthForm(mode) {
	const panel = byId('auth-panel');
	if (mode === 'forgot') {
		panel.innerHTML = `<div class="auth-card"><p class="eyebrow">ACCOUNT RECOVERY</p><h2>Reset your password</h2><form id="forgot-form" class="form-stack"><label>Email address<input type="email" name="email" required autocomplete="email"></label><button class="button button-dark" type="submit">Send reset link</button></form><p id="auth-message" class="form-message" role="status"></p><div class="auth-links"><button type="button" data-return-login>Back to sign in</button></div></div>`;
		return;
	}
	const register = mode === 'register';
	panel.innerHTML = `<div class="auth-card"><p class="eyebrow">${register ? 'JOIN THE GATHERING' : 'WELCOME BACK'}</p><h2>${register ? 'Create your account' : 'Sign in to Gather'}</h2>
		<form id="auth-form" class="form-stack">
			${register ? '<label>Your name<input name="name" required autocomplete="name" maxlength="255"></label>' : ''}
			<label>Email address<input type="email" name="email" required autocomplete="email"></label>
			<label>Password<input type="password" name="password" required autocomplete="current-password" minlength="8"></label>
			${register ? '<label>Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" minlength="8"></label><label>Account type<select name="role" required><option value="attendee">Attendee</option><option value="organizer">Organizer</option></select></label>' : ''}
			<button class="button button-dark" type="submit">${register ? 'Create account' : 'Sign in'}</button>
		</form><p id="auth-message" class="form-message" role="status"></p>
		${register ? '' : '<div class="auth-links"><button type="button" data-forgot-password>Forgot password?</button><span></span></div>'}
	</div>`;
}

async function submitAuth(form) {
	const values = Object.fromEntries(new FormData(form));
	const register = Boolean(values.role);
	const message = byId('auth-message');
	const button = form.querySelector('button[type="submit"]');
	button.disabled = true;
	setMessage(message, register ? 'Creating your account...' : 'Signing you in...');
	try {
		const response = await api(register ? '/register' : '/login', { method: 'POST', body: values });
		saveSession(response.token, response.user);
		closeDialog('auth-dialog');
		showToast(register && response.user.role === 'organizer' ? 'Account created. Check your email to verify your organizer account.' : 'You are signed in.');
		if (location.pathname === '/gate-invitation/accept') renderInvitationForm();
		if (state.pendingHold) {
			const pending = state.pendingHold;
			state.pendingHold = null;
			const event = state.events.find((item) => Number(item.id) === pending.eventId);
			if (event) await createHolds(event, pending.quantities);
		}
	} catch (error) {
		setMessage(message, errorText(error), 'error');
		button.disabled = false;
	}
}

async function submitForgot(form) {
	const message = byId('auth-message');
	const button = form.querySelector('button[type="submit"]');
	button.disabled = true;
	try {
		const response = await api('/forgot-password', { method: 'POST', body: Object.fromEntries(new FormData(form)) });
		setMessage(message, response.status || 'If the account exists, a reset link has been sent.', 'success');
	} catch (error) {
		setMessage(message, errorText(error), 'error');
	} finally {
		button.disabled = false;
	}
}

async function resendVerification() {
	try {
		const response = await api('/email/verification-notification', { method: 'POST' });
		showToast(response.message || 'Verification email sent.');
	} catch (error) {
		showToast(errorText(error), true);
	}
}

async function loadOrders() {
	const container = byId('ticket-orders');
	if (!state.token) {
		clearTicketWallet();
		container.innerHTML = '<div class="empty-state">Sign in to see your orders and ticket details.</div>';
		return;
	}
	container.innerHTML = '<div class="loading-line">Loading your orders...</div>';
	try {
		const response = await api('/orders');
		renderOrders(response.orders || []);
	} catch (error) {
		container.innerHTML = `<div class="empty-state">${escapeHtml(errorText(error))}</div>`;
		return;
	}
	if (state.user && state.user.role !== 'attendee') {
		clearTicketWallet();
		return;
	}
	byId('ticket-wallet-section').classList.remove('is-hidden');
	await loadTicketPage(state.ticketPage);
}

async function loadTicketPage(page) {
	const container = byId('attendee-tickets');
	state.tickets = [];
	container.innerHTML = '<div class="loading-line">Loading tickets...</div>';
	try {
		const response = await api(`/my-tickets?page=${encodeURIComponent(page)}`);
		renderTicketWallet(response.tickets || {});
	} catch (error) {
		state.tickets = [];
		container.innerHTML = `<div class="empty-state">${escapeHtml(errorText(error))}</div>`;
	}
}

function renderTicketWallet(paginator) {
	const container = byId('attendee-tickets');
	const tickets = paginator.data || [];
	// Keep regenerated credentials in page memory and reveal them only in ticket details.
	state.tickets = tickets;
	state.ticketPage = Number(paginator.current_page) || 1;
	byId('ticket-count').textContent = `${Number(paginator.total) || 0} ticket${Number(paginator.total) === 1 ? '' : 's'}`;

	if (!tickets.length) {
		container.innerHTML = '<div class="empty-state">No issued tickets yet.</div>';
	} else {
		container.innerHTML = tickets.map((ticket) => `<article class="ticket-card">
			<div class="ticket-card-header"><span class="eyebrow">TICKET ${Number(ticket.unit_number)}</span><span class="status-pill ${ticketStatusClass(ticket.status)}">${escapeHtml(String(ticket.status).replaceAll('_', ' '))}</span></div>
			<strong class="ticket-card-event">${escapeHtml(ticket.event)}</strong>
			<div class="ticket-card-meta"><span>${escapeHtml(ticket.ticket_type)}</span><span>Unit ${Number(ticket.unit_number)}</span></div>
			<button class="small-button" type="button" data-ticket-detail="${Number(ticket.id)}">View ticket</button>
		</article>`).join('');
	}

	const pagination = byId('ticket-pagination');
	const lastPage = Number(paginator.last_page) || 1;
	pagination.innerHTML = lastPage > 1 ? `<button class="small-button" type="button" data-ticket-page="${state.ticketPage - 1}" ${state.ticketPage <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${state.ticketPage} of ${lastPage}</span><button class="small-button" type="button" data-ticket-page="${state.ticketPage + 1}" ${state.ticketPage >= lastPage ? 'disabled' : ''}>Next</button>` : '';
}

function ticketStatusClass(status) {
	if (['cancelled', 'refunded'].includes(status)) return 'is-danger';
	if (status === 'admission_conflict') return 'is-warning';
	return '';
}

async function openTicketDetail(ticketId) {
	const ticket = state.tickets.find((entry) => Number(entry.id) === Number(ticketId));
	if (!ticket) return;
	const canPresentCode = ! ['cancelled', 'refunded'].includes(ticket.status) && ticket.credential;
	const credential = canPresentCode ? `<section class="ticket-code-section"><h3>Ticket QR code</h3><div class="ticket-qr-frame"><img class="ticket-qr-image" id="ticket-qr-image" alt="QR code for ${escapeHtml(ticket.event)} ticket"><span id="ticket-qr-status" role="status">Generating ticket code...</span></div><button class="small-button" type="button" data-ticket-copy="${Number(ticket.id)}">Copy ticket code</button></section>` : '';
	byId('ticket-detail').innerHTML = `<div class="ticket-detail-heading"><p class="eyebrow">YOUR TICKET</p><h2 id="ticket-detail-title">${escapeHtml(ticket.event)}</h2><span class="status-pill ${ticketStatusClass(ticket.status)}">${escapeHtml(String(ticket.status).replaceAll('_', ' '))}</span></div><dl class="ticket-facts"><div><dt>Ticket type</dt><dd>${escapeHtml(ticket.ticket_type)}</dd></div><div><dt>Unit</dt><dd>${Number(ticket.unit_number)}</dd></div></dl>${credential}`;
	const dialog = byId('ticket-detail-dialog');
	if (!dialog.open) dialog.showModal();
	const renderId = ++state.ticketQrRender;
	if (canPresentCode) {
		try {
			const dataUrl = await QRCode.toDataURL(ticket.credential, {
				errorCorrectionLevel: 'M',
				margin: 2,
				width: 280,
				color: { dark: '#17201d', light: '#ffffff' },
			});
			if (renderId !== state.ticketQrRender) return;
			const image = byId('ticket-qr-image');
			image.src = dataUrl;
			byId('ticket-qr-status').remove();
		} catch {
			if (renderId === state.ticketQrRender) byId('ticket-qr-status').textContent = 'QR generation failed. Use the copy button instead.';
		}
	}
}

async function copyTicketCode(ticketId) {
	const ticket = state.tickets.find((entry) => Number(entry.id) === Number(ticketId));
	if (!ticket?.credential || !navigator.clipboard?.writeText) {
		showToast('Clipboard access is unavailable in this browser.', true);
		return;
	}
	try {
		await navigator.clipboard.writeText(ticket.credential);
		showToast('Ticket code copied.');
	} catch {
		showToast('Ticket code could not be copied.', true);
	}
}

function statusClass(status) {
	if (['failed', 'refund_failed', 'expired', 'cancelled'].includes(status)) return 'is-danger';
	if (['pending', 'refund_pending'].includes(status)) return 'is-warning';
	return '';
}

function renderOrders(orders) {
	const container = byId('ticket-orders');
	if (!orders.length) {
		container.innerHTML = '<div class="empty-state">No orders yet. Your next good plan is out there.</div>';
		return;
	}
	container.innerHTML = orders.map((order) => {
		const lines = order.order_items || [];
		const refunds = order.refund_requests || [];
		const refundsTotal = (order.ledger_entries || []).filter((entry) => entry.type === 'refund').reduce((sum, entry) => sum + Math.abs(Number(entry.amount_xaf)), 0);
		const canRefund = ['paid', 'partially_refunded', 'refund_failed'].includes(order.status) && refundsTotal < Number(order.amount_xaf);
		return `<article class="order-card">
			<div class="order-topline"><div><strong>Order #${Number(order.id)}</strong><div class="muted">Placed ${escapeHtml(formatDate(order.created_at))} · ${formatMoney(order.amount_xaf)}</div></div><span class="status-pill ${statusClass(order.status)}">${escapeHtml(String(order.status).replaceAll('_', ' '))}</span></div>
			<div class="order-lines">${lines.map((line) => `<div class="order-line"><span class="order-line-name">${escapeHtml(line.ticket_type?.event?.title || 'Event')} · ${escapeHtml(line.ticket_type?.name || 'Ticket')} × ${Number(line.quantity)}</span><span class="order-line-price">${formatMoney(line.sub_total_xaf)}</span></div>`).join('')}</div>
			${refunds.length ? `<div class="order-refunds">${refunds.map((refund) => {
				const eventScoped = refund.reason === 'event_cancelled';
				const label = eventScoped ? `Event cancellation · ${refund.event?.title || 'Event'}` : refund.reason === 'attendee_request' ? 'Your refund request' : 'Refund update';
				return `<div class="refund-row"><span>${escapeHtml(label)} · <b>${escapeHtml(refund.status)}</b></span><strong>${formatMoney(refund.amount_xaf)}</strong></div>`;
			}).join('')}</div>` : ''}
			${canRefund ? `<div class="order-actions"><button class="small-button" type="button" data-order-refund="${Number(order.id)}">Request refund for remaining balance</button></div>` : ''}
		</article>`;
	}).join('');
}

async function requestFullRefund(orderId) {
	if (!window.confirm('Request a refund for the remaining refundable balance on this order?')) return;
	try {
		await api('/refund', { method: 'POST', body: { id: Number(orderId) } });
		showToast('Refund request submitted. Its status will update as Stripe processes it.');
		await loadOrders();
	} catch (error) {
		showToast(errorText(error), true);
	}
}

async function loadOrganizerWorkspace() {
	const container = byId('organizer-content');
	if (!state.user || state.user.role !== 'organizer') {
		container.innerHTML = '<div class="empty-state">Sign in with an organizer account to manage events and sales.</div>';
		byId('sales-summary').innerHTML = '';
		return;
	}
	if (!state.user.email_verified_at) {
		container.innerHTML = `<div class="organizer-verify"><span>Your email must be verified before organizer tools are available.</span><button class="small-button" type="button" data-resend-verification>Resend verification email</button></div>`;
		byId('sales-summary').innerHTML = '';
		return;
	}
	container.innerHTML = '<div class="loading-line">Loading organizer workspace...</div>';
	try {
		const [eventsResponse, summary] = await Promise.all([api('/organizer/events'), api('/organizer/sales_summary')]);
		const events = eventsResponse.events?.data || [];
		const inventories = await Promise.all(events.map(async (event) => {
			try { return (await api(`/organizer/events/${event.id}/inventory`)).inventory || []; }
			catch { return []; }
		}));
		events.forEach((event, index) => { event.inventory = inventories[index]; });
		renderSalesSummary(summary);
		renderOrganizerEvents(events);
	} catch (error) {
		container.innerHTML = `<div class="empty-state">${escapeHtml(errorText(error))}</div>`;
	}
}

function renderSalesSummary(summary) {
	byId('sales-summary').innerHTML = `<div class="summary-stat"><span>ORDERS</span><strong>${Number(summary.orders_count) || 0}</strong></div><div class="summary-stat"><span>PAID</span><strong>${formatMoney(summary.paid_xaf)}</strong></div><div class="summary-stat"><span>REFUNDED</span><strong>${formatMoney(summary.refunded_xaf)}</strong></div><div class="summary-stat"><span>COLLECTED</span><strong>${formatMoney(summary.collected_xaf)}</strong></div>`;
}

function ticketRows(event) {
	const inventory = event.inventory || [];
	if (!inventory.length) return '<tr><td colspan="5" class="muted">No ticket types yet.</td></tr>';
	return inventory.map((ticket) => `<tr>
		<td><strong>${escapeHtml(ticket.name)}</strong></td><td>${formatMoney(ticket.price_xaf ?? ticket.base_price_xaf)}</td>
		<td>${Number(ticket.sold || 0)} sold · ${Number(ticket.held || 0)} held</td>
		<td><span class="inventory-bar"><span style="width:${Math.max(0, Math.min(100, Number(ticket.quantity) ? (Number(ticket.sold || 0) + Number(ticket.held || 0)) / Number(ticket.quantity) * 100 : 0))}%"></span></span><small> ${Number(ticket.remaining || 0)} left</small></td>
		<td><details><summary class="small-button">Edit</summary><form class="organizer-form ticket-edit-form" data-ticket-update="${Number(ticket.id)}"><label>Name<input name="name" value="${escapeHtml(ticket.name)}" maxlength="255"></label><label>Base price (XAF)<input name="base_price_xaf" type="number" min="1" step="1" value="${Number(ticket.base_price_xaf)}"></label><label>Discount %<input name="discount" type="number" min="0" max="100" value="${Number(ticket.discount || 0)}"></label><label>Total quantity<input name="quantity" type="number" min="1" value="${Number(ticket.quantity)}"></label><button class="small-button" type="submit">Save ticket</button><button class="small-button danger" type="button" data-ticket-delete="${Number(ticket.id)}">Delete</button></form></details></td>
	</tr>`).join('');
}

function renderOrganizerEvents(events) {
	const container = byId('organizer-content');
	const form = `<section class="organizer-create"><div class="organizer-toolbar"><div><strong>Create an event</strong><span class="muted"> Status is selected at creation; cancellation is a separate action.</span></div></div>
		<form class="organizer-form" id="create-event-form">
			<label>Event title<input name="title" required maxlength="255"></label><label>Status<select name="status" required><option value="draft">Draft</option><option value="published">Published</option><option value="cancelled">Cancelled</option></select></label>
			<label>Venue<input name="venue" required maxlength="255"></label><label>Town<input name="town" required maxlength="255"></label>
			<label>Date<input name="date" type="date" required></label><label>Start time<input name="start_time" type="time" required></label>
			<label class="wide">Description<textarea name="description"></textarea></label><button class="button button-dark" type="submit">Create event</button>
		</form></section>
		<div class="organizer-toolbar"><strong>Your events</strong><span class="muted">${events.length} total</span></div>`;
	const list = events.length ? `<div class="organizer-event-list">${events.map((event) => `<article class="organizer-event">
		<div class="organizer-event-header"><div><h3>${escapeHtml(event.title)}</h3><p>${escapeHtml(formatDate(event.date))} · ${escapeHtml(event.venue)}, ${escapeHtml(event.town)} · <span class="status-pill ${statusClass(event.status)}">${escapeHtml(event.status)}</span></p></div>
			<div class="organizer-event-tools"><button class="small-button" type="button" data-event-manage="${Number(event.id)}">${event.status === 'cancelled' ? 'View details' : 'Manage event'}</button>${event.status === 'draft' ? `<button class="small-button" type="button" data-event-publish="${Number(event.id)}">Publish event</button>` : ''}${event.status !== 'cancelled' ? `<button class="small-button danger" type="button" data-event-cancel="${Number(event.id)}">Cancel event</button>` : ''}</div></div>
		<div class="event-management is-hidden" id="manage-${Number(event.id)}">
			<div class="management-grid">
				<section><h4>Edit event details</h4><form class="organizer-form event-edit-form" data-event-update="${Number(event.id)}"><label>Title<input name="title" value="${escapeHtml(event.title)}" required></label><label>Venue<input name="venue" value="${escapeHtml(event.venue)}" required></label><label>Town<input name="town" value="${escapeHtml(event.town)}" required></label><label>Date<input name="date" type="date" value="${escapeHtml(String(event.date).slice(0, 10))}" required></label><label>Start time<input name="start_time" type="time" value="${escapeHtml(event.start_time?.slice(0, 5) || '')}" required></label><label class="wide">Description<textarea name="description">${escapeHtml(event.description || '')}</textarea></label><button class="small-button" type="submit">Save event details</button></form></section>
				<section><h4>Ticket inventory</h4><div class="ticket-table-wrap"><table class="ticket-table"><thead><tr><th>Ticket</th><th>Price</th><th>Committed</th><th>Remaining</th><th>Manage</th></tr></thead><tbody>${ticketRows(event)}</tbody></table></div>
					${event.status !== 'cancelled' ? `<h4 style="margin-top:18px">Add ticket type</h4><form class="organizer-form ticket-create-form" data-event-ticket-create="${Number(event.id)}"><label>Name<input name="name" required></label><label>Base price (XAF)<input name="base_price_xaf" type="number" min="1" step="1" required></label><label>Discount %<input name="discount" type="number" min="0" max="100" value="0"></label><label>Quantity<input name="quantity" type="number" min="1" required></label><button class="small-button" type="submit">Add ticket type</button></form>` : ''}
				</section>
			</div>
			<section class="gate-management-section"><div class="organizer-toolbar"><strong>Gates and access</strong></div><div id="gate-management-${Number(event.id)}"><div class="loading-line">Open this event to load gate operations.</div></div></section>
			${renderCancellationRefunds(event)}
		</div>
	</article>`).join('')}</div>` : '<div class="empty-state">You have not created any events yet.</div>';
	container.innerHTML = form + list;
}

async function loadGateManagement(eventId) {
	const container = byId(`gate-management-${Number(eventId)}`);
	container.innerHTML = '<div class="loading-line">Loading gates and access...</div>';
	try {
		const [gateResponse, accessResponse, countResponse] = await Promise.all([
			api(`/organizer/events/${eventId}/gates`),
			api(`/organizer/events/${eventId}/gate-staff/invitations`),
			api(`/organizer/events/${eventId}/entry-counts`),
		]);
		const gates = gateResponse.gates || [];
		const devices = await Promise.all(gates.map(async (gate) => {
			const response = await api(`/organizer/events/${eventId}/gates/${gate.id}/devices`);
			return [gate.id, response.devices || []];
		}));
		renderGateManagement(eventId, gates, accessResponse, countResponse, Object.fromEntries(devices));
	} catch (error) {
		container.innerHTML = `<div class="empty-state">${escapeHtml(errorText(error))}</div>`;
	}
}

function renderGateManagement(eventId, gates, access, counts, devicesByGate) {
	const assignments = access.assignments || [];
	const staffOptions = assignments.map((assignment) => `<option value="${Number(assignment.user_id)}">${escapeHtml(assignment.user?.name)} · ${escapeHtml(assignment.user?.email)}</option>`).join('');
	const invitationRows = (access.invitations || []).map((invitation) => {
		const expired = new Date(invitation.expires_at).getTime() <= Date.now();
		const status = invitation.accepted_at ? `Accepted by ${invitation.accepted_by?.name || 'staff'}` : invitation.revoked_at ? 'Revoked' : expired ? 'Expired' : 'Pending';
		const canRevoke = !invitation.accepted_at && !invitation.revoked_at && !expired;
		return `<div class="module-c-row"><span><strong>${escapeHtml(invitation.email)}</strong><small>${escapeHtml(status)} · Expires ${escapeHtml(formatDate(invitation.expires_at))}</small></span>${canRevoke ? `<button class="small-button danger" type="button" data-invitation-revoke="${Number(invitation.id)}" data-event-id="${Number(eventId)}">Revoke invitation</button>` : ''}</div>`;
	}).join('') || '<div class="empty-state">No invitations for this event.</div>';
	const assignmentRows = assignments.map((assignment) => `<div class="module-c-row"><span><strong>${escapeHtml(assignment.user?.name)}</strong><small>${escapeHtml(assignment.user?.email)} · ${escapeHtml(assignment.user?.role)}</small></span><button class="small-button danger" type="button" data-assignment-revoke="${Number(assignment.user_id)}" data-event-id="${Number(eventId)}">Remove access</button></div>`).join('') || '<div class="empty-state">No staff assignments for this event.</div>';
	const gateRows = gates.map((gate) => {
		const devices = devicesByGate[gate.id] || [];
		const deviceRows = devices.map((device) => `<div class="module-c-row"><span><strong>${escapeHtml(device.name)}</strong><small>${escapeHtml(device.user?.name || 'Assigned staff')} · ${device.revoked_at ? 'Revoked' : 'Active'}${device.last_snapshot_at ? ` · Snapshot ${escapeHtml(formatDate(device.last_snapshot_at))}` : ''}</small><code>${escapeHtml(device.public_id)}</code></span><div class="module-c-actions"><button class="small-button" type="button" data-device-copy="${escapeHtml(device.public_id)}">Copy device ID</button>${device.revoked_at ? '' : `<button class="small-button danger" type="button" data-device-revoke="${escapeHtml(device.public_id)}" data-event-id="${Number(eventId)}" data-gate-id="${Number(gate.id)}">Revoke device</button>`}</div></div>`).join('') || '<div class="muted">No devices enrolled.</div>';
		const enrollmentForm = assignments.length ? `<form class="organizer-form gate-device-form" data-event-id="${Number(eventId)}" data-gate-id="${Number(gate.id)}"><label>Assigned staff<select name="user_id" required>${staffOptions}</select></label><label>Device name<input name="name" required maxlength="255" placeholder="Front entrance tablet"></label><button class="small-button" type="submit">Enroll device</button></form>` : '<p class="muted">Invite and assign staff before enrolling a device.</p>';
		return `<article class="gate-card"><div class="gate-card-heading"><strong>${escapeHtml(gate.name)}</strong><span class="status-pill ${gate.status === 'inactive' ? 'is-danger' : ''}">${escapeHtml(gate.status)}</span></div><form class="organizer-form gate-update-form" data-event-id="${Number(eventId)}" data-gate-id="${Number(gate.id)}"><label>Gate name<input name="name" value="${escapeHtml(gate.name)}" required maxlength="255"></label><label>Status<select name="status"><option value="active" ${gate.status === 'active' ? 'selected' : ''}>Active</option><option value="inactive" ${gate.status === 'inactive' ? 'selected' : ''}>Inactive</option></select></label><button class="small-button" type="submit">Save gate</button></form><div class="device-list"><h5>Devices</h5>${deviceRows}${enrollmentForm}</div></article>`;
	}).join('') || '<div class="empty-state">No gates have been created for this event.</div>';
	byId(`gate-management-${Number(eventId)}`).innerHTML = `<div class="module-c-management"><div class="module-c-count"><span>Server-confirmed admissions</span><strong>${Number(counts.admitted_tickets) || 0}</strong></div><form class="organizer-form gate-create-form" data-event-id="${Number(eventId)}"><label>New gate name<input name="name" required maxlength="255" placeholder="North entrance"></label><button class="small-button" type="submit">Add gate</button></form><div class="module-c-columns"><section><h4>Gate staff invitations</h4><form class="organizer-form gate-invitation-form" data-event-id="${Number(eventId)}"><label>Staff email address<input type="email" name="email" required autocomplete="email"></label><button class="small-button" type="submit">Send invitation</button></form><div class="module-c-list">${invitationRows}</div><h4>Assigned staff</h4><div class="module-c-list">${assignmentRows}</div></section><section><h4>Event gates</h4><div class="gate-list">${gateRows}</div></section></div></div>`;
}

async function createGate(form) {
	const eventId = Number(form.dataset.eventId);
	try {
		await api(`/organizer/events/${eventId}/gates`, { method: 'POST', body: Object.fromEntries(new FormData(form)) });
		showToast('Gate created.');
		await loadGateManagement(eventId);
	} catch (error) { showToast(errorText(error), true); }
}

async function updateGate(form) {
	const eventId = Number(form.dataset.eventId);
	const gateId = Number(form.dataset.gateId);
	try {
		await api(`/organizer/gates/${gateId}`, { method: 'PATCH', body: Object.fromEntries(new FormData(form)) });
		showToast('Gate updated.');
		await loadGateManagement(eventId);
	} catch (error) { showToast(errorText(error), true); }
}

async function inviteGateStaff(form) {
	const eventId = Number(form.dataset.eventId);
	const email = String(new FormData(form).get('email') || '').trim().toLowerCase();
	try {
		await api(`/organizer/events/${eventId}/gate-staff/invitations`, { method: 'POST', body: { email } });
		showToast('Invitation email queued.');
		await loadGateManagement(eventId);
	} catch (error) { showToast(errorText(error), true); }
}

async function enrollGateDevice(form) {
	const eventId = Number(form.dataset.eventId);
	const gateId = Number(form.dataset.gateId);
	const values = Object.fromEntries(new FormData(form));
	values.user_id = Number(values.user_id);
	try {
		await api(`/organizer/events/${eventId}/gates/${gateId}/devices`, { method: 'POST', body: values });
		showToast('Device enrolled.');
		await loadGateManagement(eventId);
	} catch (error) { showToast(errorText(error), true); }
}

async function revokeGateInvitation(eventId, invitationId) {
	if (!window.confirm('Revoke this unused invitation?')) return;
	try {
		await api(`/organizer/events/${eventId}/gate-staff/invitations/${invitationId}`, { method: 'DELETE' });
		showToast('Invitation revoked.');
		await loadGateManagement(eventId);
	} catch (error) { showToast(errorText(error), true); }
}

async function revokeGateAssignment(eventId, userId) {
	if (!window.confirm('Remove this event assignment? Devices enrolled for this event will also be revoked.')) return;
	try {
		await api(`/organizer/events/${eventId}/gate-staff/${userId}`, { method: 'DELETE' });
		showToast('Event access removed.');
		await loadGateManagement(eventId);
	} catch (error) { showToast(errorText(error), true); }
}

async function revokeGateDevice(eventId, gateId, deviceId) {
	if (!window.confirm('Revoke this device?')) return;
	try {
		await api(`/organizer/events/${eventId}/gates/${gateId}/devices/${encodeURIComponent(deviceId)}`, { method: 'DELETE' });
		showToast('Device revoked.');
		await loadGateManagement(eventId);
	} catch (error) { showToast(errorText(error), true); }
}

async function copyDeviceId(deviceId) {
	try {
		await navigator.clipboard.writeText(deviceId);
		showToast('Device ID copied.');
	} catch {
		showToast('Device ID could not be copied.', true);
	}
}

let gateDatabasePromise;

function openGateDatabase() {
	if (!('indexedDB' in window)) return Promise.reject(new Error('Offline storage is unavailable in this browser.'));
	if (!gateDatabasePromise) {
		gateDatabasePromise = new Promise((resolve, reject) => {
			const request = indexedDB.open(GATE_DATABASE_NAME, 1);
			request.onupgradeneeded = () => {
				const database = request.result;
				if (!database.objectStoreNames.contains(GATE_QUEUE_STORE)) database.createObjectStore(GATE_QUEUE_STORE, { keyPath: 'attempt_id' });
				if (!database.objectStoreNames.contains(GATE_SNAPSHOT_STORE)) database.createObjectStore(GATE_SNAPSHOT_STORE, { keyPath: 'device_id' });
				if (!database.objectStoreNames.contains(GATE_KEY_STORE)) database.createObjectStore(GATE_KEY_STORE, { keyPath: 'id' });
			};
			request.onsuccess = () => resolve(request.result);
			request.onerror = () => reject(request.error);
		});
	}
	return gateDatabasePromise;
}

async function gateStoreRequest(storeName, mode, createRequest) {
	const database = await openGateDatabase();
	return new Promise((resolve, reject) => {
		const transaction = database.transaction(storeName, mode);
		let result;
		const request = createRequest(transaction.objectStore(storeName));
		request.onsuccess = () => { result = request.result; };
		request.onerror = () => reject(request.error);
		transaction.oncomplete = () => resolve(result);
		transaction.onerror = () => reject(transaction.error);
		transaction.onabort = () => reject(transaction.error || new Error('Offline storage operation was aborted.'));
	});
}

async function gateCredentialKey() {
	let record = await gateStoreRequest(GATE_KEY_STORE, 'readonly', (store) => store.get('credential-key'));
	if (record?.key) return record.key;
	const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
	await gateStoreRequest(GATE_KEY_STORE, 'readwrite', (store) => store.put({ id: 'credential-key', key }));
	return key;
}

async function encryptQueuedCredential(credential) {
	const key = await gateCredentialKey();
	const iv = crypto.getRandomValues(new Uint8Array(12));
	const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(credential));
	return { iv: iv.buffer, ciphertext };
}

async function decryptQueuedCredential(record) {
	const key = await gateCredentialKey();
	const plaintext = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: record.credential_iv }, key, record.credential_ciphertext);
	return new TextDecoder().decode(plaintext);
}

function decodeBase64Url(value) {
	const base64 = value.replaceAll('-', '+').replaceAll('_', '/');
	const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=');
	return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
}

function pemBytes(pem) {
	const body = pem.replace(/-----BEGIN [^-]+-----|-----END [^-]+-----|\s/g, '');
	return Uint8Array.from(atob(body), (character) => character.charCodeAt(0));
}

function derLength(bytes, offset) {
	const first = bytes[offset];
	if (first < 0x80) return { length: first, next: offset + 1 };
	const count = first & 0x7f;
	let length = 0;
	for (let index = 0; index < count; index += 1) length = (length << 8) | bytes[offset + 1 + index];
	return { length, next: offset + count + 1 };
}

function ecdsaDerToRaw(signature, componentLength) {
	const bytes = new Uint8Array(signature);
	if (bytes[0] !== 0x30) throw new Error('Invalid ECDSA snapshot signature.');
	let offset = derLength(bytes, 1).next;
	const components = [];
	for (let component = 0; component < 2; component += 1) {
		if (bytes[offset] !== 0x02) throw new Error('Invalid ECDSA snapshot signature.');
		const parsedLength = derLength(bytes, offset + 1);
		let value = bytes.slice(parsedLength.next, parsedLength.next + parsedLength.length);
		while (value.length > componentLength && value[0] === 0) value = value.slice(1);
		if (value.length > componentLength) throw new Error('Invalid ECDSA snapshot signature.');
		const padded = new Uint8Array(componentLength);
		padded.set(value, componentLength - value.length);
		components.push(padded);
		offset = parsedLength.next + parsedLength.length;
	}
	const raw = new Uint8Array(componentLength * 2);
	raw.set(components[0]);
	raw.set(components[1], componentLength);
	return raw;
}

async function verifySnapshotSignature(manifest, signature, publicKeyPem) {
	const keyBytes = pemBytes(publicKeyPem);
	let key;
	let algorithm;
	for (const curve of ['P-256', 'P-384', 'P-521']) {
		try {
			key = await crypto.subtle.importKey('spki', keyBytes, { name: 'ECDSA', namedCurve: curve }, false, ['verify']);
			algorithm = { name: 'ECDSA', hash: 'SHA-256' };
			algorithm.componentLength = curve === 'P-256' ? 32 : curve === 'P-384' ? 48 : 66;
			break;
		} catch { /* Try the other OpenSSL/WebCrypto-compatible public-key types. */ }
	}
	if (!key) {
		try {
			key = await crypto.subtle.importKey('spki', keyBytes, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['verify']);
			algorithm = { name: 'RSASSA-PKCS1-v1_5' };
		} catch {
			throw new Error('The configured snapshot key type is not supported by this browser.');
		}
	}
	const signatureBytes = decodeBase64Url(signature);
	const webSignature = algorithm.name === 'ECDSA'
		? ecdsaDerToRaw(signatureBytes, algorithm.componentLength)
		: signatureBytes;
	const canonicalManifest = new TextEncoder().encode(JSON.stringify(manifest));
	if (! await crypto.subtle.verify(algorithm, key, webSignature, canonicalManifest)) {
		throw new Error('The snapshot signature could not be verified.');
	}
}

async function verifyGateSnapshot(snapshot, tickets, expectedDeviceId, expectedEventId) {
	if (snapshot.device_id !== expectedDeviceId || Number(snapshot.event_id) !== Number(expectedEventId)) {
		throw new Error('The snapshot does not match the selected event and device.');
	}
	if (new Date(snapshot.expires_at).getTime() <= Date.now()) throw new Error('This snapshot has expired. Download the current snapshot while online.');
	const entries = tickets.map((ticket) => ({
		ticket_id: Number(ticket.ticket_id),
		code_hash: ticket.code_hash,
		status: ticket.status,
	}));
	const payloadBytes = new TextEncoder().encode(JSON.stringify(entries));
	const payloadDigest = new Uint8Array(await crypto.subtle.digest('SHA-256', payloadBytes));
	const payloadHash = [...payloadDigest].map((byte) => byte.toString(16).padStart(2, '0')).join('');
	if (payloadHash !== snapshot.payload_hash) throw new Error('The downloaded roster does not match its signed snapshot.');
	const manifest = {
		id: snapshot.id,
		device_id: snapshot.device_id,
		event_id: Number(snapshot.event_id),
		event_status: snapshot.event_status,
		payload_hash: snapshot.payload_hash,
		signing_key_id: snapshot.signing_key_id,
		issued_at: snapshot.issued_at,
		expires_at: snapshot.expires_at,
	};
	await verifySnapshotSignature(manifest, snapshot.signature, snapshot.verification_public_key);
	return { manifest, tickets };
}

async function loadGateWorkspace() {
	const container = byId('gate-workspace');
	if (!state.token) {
		container.innerHTML = '<div class="empty-state">Sign in with an assigned event account to use gate operations.</div>';
		return;
	}
	container.dataset.loading = 'true';
	try {
		const response = await api('/gate/access');
		state.gate.assignments = response.assignments || [];
		if (!state.gate.assignments.length) {
			container.innerHTML = '<div class="empty-state">No events are assigned to this account. Ask an event organizer to invite you.</div>';
			return;
		}
		container.innerHTML = `<div class="scan-context"><label>Assigned event<select id="scan-event-select" required></select></label><label>Active gate<select id="scan-gate-select" required></select></label><label>Registered device<select id="scan-device-select"></select></label></div><div class="scan-toolbar"><div class="scan-mode" role="group" aria-label="Scan mode"><button class="is-active" type="button" data-scan-mode="online">Online</button><button type="button" data-scan-mode="offline">Offline</button></div><div class="scan-actions"><button class="small-button" id="snapshot-download" type="button">Download verified roster</button><button class="small-button" id="scan-sync" type="button">Sync pending scans</button></div></div><p class="form-message" id="snapshot-status" role="status"></p><div class="scanner-panel"><div class="camera-frame is-hidden" id="camera-frame"><video id="scan-video" autoplay muted playsinline aria-label="Ticket QR camera preview"></video></div><div class="scanner-actions"><button class="small-button" id="camera-start" type="button">Start camera scanner</button><button class="small-button is-hidden" id="camera-stop" type="button">Stop camera</button></div><form id="scan-form" class="scan-form"><label>Ticket code<textarea name="credential" required maxlength="4096" rows="3" autocomplete="off" autocapitalize="off" spellcheck="false"></textarea></label><button class="button button-dark" id="scan-submit" type="submit">Check ticket</button></form></div><div class="scan-result" id="scan-result" role="status" aria-live="polite"></div><section class="scan-queue-section" aria-labelledby="scan-queue-title"><div class="organizer-toolbar"><strong id="scan-queue-title">Offline scan queue</strong><span class="muted" id="scan-queue-count">0 pending</span></div><div id="scan-queue"></div></section>`;
		renderGateSelectors();
		updateGateNetworkStatus();
		await renderScanQueue();
	} catch (error) {
		container.innerHTML = `<div class="empty-state">${escapeHtml(errorText(error))}</div>`;
	} finally {
		delete container.dataset.loading;
	}
}

function renderGateSelectors() {
	const eventSelect = byId('scan-event-select');
	const selectedEventId = state.gate.eventId || eventSelect.value;
	eventSelect.innerHTML = state.gate.assignments.map(({ event }) => `<option value="${Number(event.id)}">${escapeHtml(event.title)} · ${escapeHtml(formatDate(event.date))}</option>`).join('');
	state.gate.eventId = state.gate.assignments.some(({ event }) => String(event.id) === selectedEventId)
		? selectedEventId
		: String(state.gate.assignments[0].event.id);
	eventSelect.value = state.gate.eventId;
	const assignment = state.gate.assignments.find(({ event }) => String(event.id) === state.gate.eventId);
	const gates = assignment?.event.gates || [];
	const gateSelect = byId('scan-gate-select');
	const selectedGateId = gates.some((gate) => String(gate.id) === state.gate.gateId) ? state.gate.gateId : String(gates[0]?.id || '');
	gateSelect.innerHTML = gates.map((gate) => `<option value="${Number(gate.id)}">${escapeHtml(gate.name)}</option>`).join('');
	gateSelect.value = selectedGateId;
	state.gate.gateId = selectedGateId;
	const gate = gates.find((entry) => String(entry.id) === selectedGateId);
	const devices = gate?.devices || [];
	const deviceSelect = byId('scan-device-select');
	const selectedDeviceId = devices.some((device) => device.id === state.gate.deviceId) ? state.gate.deviceId : String(devices[0]?.id || '');
	deviceSelect.innerHTML = devices.length
		? devices.map((device) => `<option value="${escapeHtml(device.id)}">${escapeHtml(device.name)} · ${escapeHtml(device.id.slice(0, 8))}</option>`).join('')
		: '<option value="">No active device</option>';
	deviceSelect.value = selectedDeviceId;
	state.gate.deviceId = selectedDeviceId;
	state.gate.snapshot = null;
	state.gate.roster.clear();
	updateGateControls();
}

function updateGateControls() {
	const offline = state.gate.mode === 'offline';
	const selectors = ['scan-event-select', 'scan-gate-select', 'scan-device-select'];
	selectors.forEach((id) => byId(id)?.toggleAttribute('disabled', offline));
	const downloadButton = byId('snapshot-download');
	const syncButton = byId('scan-sync');
	const submitButton = byId('scan-submit');
	if (downloadButton) downloadButton.disabled = offline || !navigator.onLine || !state.gate.deviceId;
	if (syncButton) syncButton.disabled = !navigator.onLine || !state.gate.deviceId;
	if (submitButton) submitButton.textContent = offline ? 'Queue offline scan' : 'Check ticket';
	document.querySelectorAll('[data-scan-mode]').forEach((button) => {
		button.classList.toggle('is-active', button.dataset.scanMode === state.gate.mode);
	});
}

function updateGateNetworkStatus() {
	const status = byId('gate-network-status');
	if (!status) return;
	status.textContent = navigator.onLine ? 'Online' : 'Offline';
	status.classList.toggle('is-warning', !navigator.onLine);
}

async function handleGateSelectionChange() {
	state.gate.eventId = byId('scan-event-select').value;
	state.gate.gateId = '';
	state.gate.deviceId = '';
	state.gate.snapshot = null;
	state.gate.roster.clear();
	renderGateSelectors();
	await renderScanQueue();
}

async function handleGateDeviceChange() {
	state.gate.deviceId = byId('scan-device-select').value;
	state.gate.snapshot = null;
	state.gate.roster.clear();
	if (state.gate.mode === 'offline') await loadStoredGateSnapshot();
	await renderScanQueue();
}

async function handleGateChange() {
	state.gate.gateId = byId('scan-gate-select').value;
	state.gate.deviceId = '';
	state.gate.snapshot = null;
	state.gate.roster.clear();
	renderGateSelectors();
	if (state.gate.mode === 'offline') await loadStoredGateSnapshot();
	await renderScanQueue();
}

async function downloadGateSnapshot() {
	if (!navigator.onLine || !state.gate.deviceId) {
		showGateMessage('Connect to the internet and select a registered device before downloading a roster.', true);
		return;
	}
	byId('snapshot-download').disabled = true;
	setMessage(byId('snapshot-status'), 'Downloading and verifying the complete ticket roster...');
	try {
		const tickets = [];
		let snapshot = null;
		let cursor = null;
		let complete = false;
		while (!complete) {
			const params = new URLSearchParams();
			if (snapshot) params.set('snapshot_id', snapshot.id);
			if (cursor) params.set('cursor', String(cursor));
			const suffix = params.size ? `?${params.toString()}` : '';
			const page = await api(`/gate/devices/${encodeURIComponent(state.gate.deviceId)}/snapshot${suffix}`);
			if (!snapshot) snapshot = page.snapshot;
			else if (JSON.stringify(snapshot) !== JSON.stringify(page.snapshot)) throw new Error('The roster snapshot changed while pages were downloading. Start again.');
			tickets.push(...(page.tickets || []));
			complete = page.complete === true;
			if (!complete && !page.next_cursor) throw new Error('The roster response did not provide a next page.');
			cursor = page.next_cursor;
		}
		const verified = await verifyGateSnapshot(snapshot, tickets, state.gate.deviceId, state.gate.eventId);
		const record = {
			device_id: state.gate.deviceId,
			event_id: state.gate.eventId,
			snapshot: { ...snapshot, ...verified.manifest },
			tickets,
			stored_at: new Date().toISOString(),
		};
		await gateStoreRequest(GATE_SNAPSHOT_STORE, 'readwrite', (store) => store.put(record));
		state.gate.snapshot = record;
		state.gate.roster = new Map(tickets.map((ticket) => [ticket.code_hash, ticket]));
		setMessage(byId('snapshot-status'), `Verified roster downloaded: ${tickets.length} tickets. Expires ${new Date(snapshot.expires_at).toLocaleString()}.`, 'success');
		updateGateControls();
	} catch (error) {
		state.gate.snapshot = null;
		state.gate.roster.clear();
		setMessage(byId('snapshot-status'), errorText(error), 'error');
	} finally {
		byId('snapshot-download').disabled = !navigator.onLine || !state.gate.deviceId || state.gate.mode === 'offline';
	}
}

async function loadStoredGateSnapshot() {
	if (!state.gate.deviceId) return false;
	try {
		const record = await gateStoreRequest(GATE_SNAPSHOT_STORE, 'readonly', (store) => store.get(state.gate.deviceId));
		if (!record || record.event_id !== state.gate.eventId) throw new Error('Download a verified roster for this event and device first.');
		await verifyGateSnapshot(record.snapshot, record.tickets, state.gate.deviceId, state.gate.eventId);
		state.gate.snapshot = record;
		state.gate.roster = new Map(record.tickets.map((ticket) => [ticket.code_hash, ticket]));
		setMessage(byId('snapshot-status'), `Verified roster ready: ${record.tickets.length} tickets. Expires ${new Date(record.snapshot.expires_at).toLocaleString()}.`, 'success');
		return true;
	} catch (error) {
		state.gate.snapshot = null;
		state.gate.roster.clear();
		setMessage(byId('snapshot-status'), errorText(error), 'error');
		return false;
	}
}

async function setGateScanMode(mode) {
	if (!['online', 'offline'].includes(mode)) return;
	if (mode === 'offline' && ! await loadStoredGateSnapshot()) return;
	state.gate.mode = mode;
	updateGateControls();
	showGateMessage(mode === 'offline'
		? 'Offline mode is using the verified event snapshot. Newer tickets may not appear until a refreshed snapshot is available.'
		: 'Online mode checks the live admission service.', false);
}

function showGateMessage(message, error = false, result = null) {
	const container = byId('scan-result');
	if (!container) return;
	container.classList.toggle('is-error', error);
	if (!result) {
		container.textContent = message;
		return;
	}
	const outcomeLabels = {
		admitted: 'Admitted',
		already_used: 'Already used',
		wrong_event: 'Wrong event',
		cancelled_or_refunded: 'Cancelled or refunded',
		invalid_code: 'Invalid code',
	};
	const admission = result.admission;
	const originalAdmission = admission?.gate && admission.admitted_at
		? `<p>Originally admitted at ${escapeHtml(new Date(admission.admitted_at).toLocaleString())} · ${escapeHtml(admission.gate.name)}</p>`
		: '';
	const flags = (result.reconciliation_flags || []).includes('offline_duplicate')
		? '<p class="scan-result-conflict">Conflicting offline admission. Both scan attempts are preserved; no gate or admission time is canonical.</p>'
		: '';
	container.innerHTML = `<strong>${escapeHtml(outcomeLabels[result.outcome] || outcomeLabels.invalid_code)}</strong>${originalAdmission}${flags}`;
}

async function handleScanSubmit(form) {
	const credential = String(new FormData(form).get('credential') || '').trim();
	if (!credential || state.gate.scanLocked) return;
	state.gate.scanLocked = true;
	byId('scan-submit').disabled = true;
	try {
		if (state.gate.mode === 'offline') {
			await queueOfflineScan(credential);
		} else {
			await submitOnlineScan(credential);
		}
		form.reset();
	} catch {
		showGateMessage('The scan could not be stored securely. Check browser storage and camera permissions before retrying.', true);
	} finally {
		state.gate.scanLocked = false;
		byId('scan-submit').disabled = false;
	}
}

async function submitOnlineScan(credential) {
	if (!navigator.onLine) {
		showGateMessage('The device is offline. Switch to offline mode after selecting a valid snapshot.', true);
		return;
	}
	if (!state.gate.eventId || !state.gate.gateId) {
		showGateMessage('Choose an assigned event and active gate first.', true);
		return;
	}
	try {
		const response = await api(`/gate/events/${state.gate.eventId}/gates/${state.gate.gateId}/scans`, {
			method: 'POST',
			body: { credential, attempt_id: crypto.randomUUID() },
		});
		showGateMessage('', false, response.data);
	} catch (error) {
		showGateMessage(error.status === 401
			? 'Session expired. Sign in again; the scanned code was not saved.'
			: 'Online scan could not be confirmed. Switch to offline mode only if a verified roster is loaded, then rescan.', true);
	}
}

async function credentialHash(credential) {
	const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(credential));
	return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

async function queueOfflineScan(credential) {
	if (!state.gate.snapshot || new Date(state.gate.snapshot.snapshot.expires_at).getTime() <= Date.now()) {
		showGateMessage('A current verified roster is required before offline scans can be queued.', true);
		return;
	}
	const codeHash = await credentialHash(credential);
	const snapshotTicket = state.gate.roster.get(codeHash);
	const queue = await gateStoreRequest(GATE_QUEUE_STORE, 'readonly', (store) => store.getAll());
	const priorLocalAdmission = queue.some((attempt) => attempt.device_id === state.gate.deviceId
		&& attempt.snapshot_id === state.gate.snapshot.snapshot.id
		&& attempt.code_hash === codeHash
		&& attempt.reported_outcome === 'admitted');
	let outcome = 'invalid_code';
	if (snapshotTicket && (state.gate.snapshot.snapshot.event_status === 'cancelled' || ['cancelled', 'refunded'].includes(snapshotTicket.status))) {
		outcome = 'cancelled_or_refunded';
	} else if (snapshotTicket && (priorLocalAdmission || ['admitted', 'admission_conflict'].includes(snapshotTicket.status))) {
		outcome = 'already_used';
	} else if (snapshotTicket?.status === 'issued') {
		outcome = 'admitted';
	}
	const encrypted = await encryptQueuedCredential(credential);
	const attempt = {
		attempt_id: crypto.randomUUID(),
		device_id: state.gate.deviceId,
		event_id: state.gate.eventId,
		gate_id: Number(state.gate.gateId),
		snapshot_id: state.gate.snapshot.snapshot.id,
		credential_iv: encrypted.iv,
		credential_ciphertext: encrypted.ciphertext,
		code_hash: codeHash,
		scanned_at: new Date().toISOString(),
		reported_outcome: outcome,
		status: 'pending',
	};
	await gateStoreRequest(GATE_QUEUE_STORE, 'readwrite', (store) => store.put(attempt));
	showGateMessage(outcome === 'admitted'
		? 'Locally marked admitted; the server will reconcile this attempt when it syncs.'
		: `${outcome.replaceAll('_', ' ')}. The attempt is queued for server reconciliation.`);
	await renderScanQueue();
}

async function renderScanQueue() {
	const queueElement = byId('scan-queue');
	if (!queueElement) return;
	try {
		const queue = await gateStoreRequest(GATE_QUEUE_STORE, 'readonly', (store) => store.getAll());
		const attempts = queue.filter((attempt) => attempt.device_id === state.gate.deviceId)
			.sort((first, second) => second.scanned_at.localeCompare(first.scanned_at));
		const pending = attempts.filter((attempt) => attempt.status === 'pending').length;
		byId('scan-queue-count').textContent = `${pending} pending`;
		queueElement.innerHTML = attempts.length ? `<div class="scan-queue-list">${attempts.slice(0, 50).map((attempt) => `<div class="scan-queue-row"><span><strong>${escapeHtml(attempt.reported_outcome.replaceAll('_', ' '))}${attempt.server_outcome ? ` · Server: ${escapeHtml(attempt.server_outcome.replaceAll('_', ' '))}` : ''}</strong><small>${escapeHtml(new Date(attempt.scanned_at).toLocaleString())}${attempt.last_sync_error ? ` · ${escapeHtml(attempt.last_sync_error)}` : ''}</small></span><span class="status-pill ${attempt.status === 'conflict' ? 'is-danger' : attempt.status === 'pending' ? 'is-warning' : ''}">${escapeHtml(attempt.status)}</span></div>`).join('')}</div>` : '<div class="empty-state">No offline scan attempts for this device.</div>';
	} catch {
		byId('scan-queue-count').textContent = 'Unavailable';
		queueElement.innerHTML = '<div class="empty-state">Encrypted offline storage is unavailable in this browser.</div>';
	}
}

async function syncOfflineScans() {
	if (!navigator.onLine || !state.gate.deviceId) return;
	try {
		const allAttempts = await gateStoreRequest(GATE_QUEUE_STORE, 'readonly', (store) => store.getAll());
		const pending = allAttempts.filter((attempt) => attempt.device_id === state.gate.deviceId && attempt.status === 'pending');
		if (!pending.length) {
			showGateMessage('There are no pending scans for this device.');
			return;
		}
		const snapshotIds = [...new Set(pending.map((attempt) => attempt.snapshot_id))];
		for (const snapshotId of snapshotIds) {
			const snapshotAttempts = pending.filter((attempt) => attempt.snapshot_id === snapshotId);
			for (let batchStart = 0; batchStart < snapshotAttempts.length; batchStart += 500) {
				const matching = snapshotAttempts.slice(batchStart, batchStart + 500);
				const attempts = await Promise.all(matching.map(async (attempt) => ({
					attempt_id: attempt.attempt_id,
					credential: await decryptQueuedCredential(attempt),
					scanned_at: attempt.scanned_at,
					reported_outcome: attempt.reported_outcome,
				})));
				const response = await api(`/gate/devices/${encodeURIComponent(state.gate.deviceId)}/scan-sync`, {
					method: 'POST',
					body: { snapshot_id: snapshotId, attempts },
				});
				for (const result of response.results || []) {
					const record = matching[result.index];
					if (!record) continue;
					if (!result.accepted) {
						record.last_sync_error = 'The server rejected this attempt. Review its snapshot and retry.';
						await gateStoreRequest(GATE_QUEUE_STORE, 'readwrite', (store) => store.put(record));
						continue;
					}
					const flags = result.data?.reconciliation_flags || [];
					record.status = flags.includes('offline_duplicate') ? 'conflict' : 'synced';
					record.server_outcome = result.data?.reconciled_outcome || result.data?.outcome;
					record.reconciliation_flags = flags;
					record.credential_ciphertext = null;
					record.credential_iv = null;
					await gateStoreRequest(GATE_QUEUE_STORE, 'readwrite', (store) => store.put(record));
				}
			}
		}
		showGateMessage('Sync complete. Server results are shown in the queue.');
		await renderScanQueue();
	} catch (error) {
		showGateMessage(error.status === 401
			? 'Session expired. Sign in again to retry; queued scans have been preserved.'
			: 'Sync could not complete. Pending scans remain encrypted on this device for retry.', true);
	}
}

async function startGateCamera() {
	if (!navigator.mediaDevices?.getUserMedia || !('BarcodeDetector' in window)) {
		showGateMessage('This browser cannot scan QR codes with the camera. Enter the ticket code manually.', true);
		return;
	}
	try {
		state.gate.detector = new BarcodeDetector({ formats: ['qr_code'] });
		state.gate.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
		const video = byId('scan-video');
		video.srcObject = state.gate.stream;
		await video.play();
		byId('camera-frame').classList.remove('is-hidden');
		byId('camera-start').classList.add('is-hidden');
		byId('camera-stop').classList.remove('is-hidden');
		state.gate.frame = requestAnimationFrame(scanGateCameraFrame);
	} catch {
		stopGateCamera();
		showGateMessage('Camera access was unavailable. Enter the ticket code manually.', true);
	}
}

async function scanGateCameraFrame() {
	if (!state.gate.stream || !state.gate.detector) return;
	try {
		const video = byId('scan-video');
		if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA && !state.gate.scanLocked) {
			const codes = await state.gate.detector.detect(video);
			const credential = codes[0]?.rawValue;
			if (credential) {
				byId('scan-form').elements.credential.value = credential;
				await handleScanSubmit(byId('scan-form'));
			}
		}
	} catch {
		stopGateCamera();
		showGateMessage('The camera scan failed. Enter the ticket code manually.', true);
		return;
	}
	state.gate.frame = requestAnimationFrame(scanGateCameraFrame);
}

function stopGateCamera() {
	if (state.gate.frame) cancelAnimationFrame(state.gate.frame);
	state.gate.frame = null;
	state.gate.stream?.getTracks().forEach((track) => track.stop());
	state.gate.stream = null;
	state.gate.detector = null;
	const video = byId('scan-video');
	if (video) video.srcObject = null;
	byId('camera-frame')?.classList.add('is-hidden');
	byId('camera-start')?.classList.remove('is-hidden');
	byId('camera-stop')?.classList.add('is-hidden');
}

function renderCancellationRefunds(event) {
	const refunds = (event.refund_requests || []).filter((refund) => refund.reason === 'event_cancelled');
	if (!refunds.length && event.status !== 'cancelled') return '';
	if (!refunds.length) return '<div class="event-refund-status"><h4>Cancellation refunds</h4><p class="muted">No paid tickets required a refund.</p></div>';
	return `<div class="event-refund-status"><h4>Cancellation refunds</h4><div class="refund-status-list">${refunds.map((refund) => `<div class="refund-row"><span>Order #${Number(refund.order_id)} · ${Number(refund.attempts)} attempt${Number(refund.attempts) === 1 ? '' : 's'} · <b>${escapeHtml(refund.status)}</b></span><strong>${formatMoney(refund.amount_xaf)}</strong></div>`).join('')}</div></div>`;
}

async function createEvent(form) {
	try {
		const payload = Object.fromEntries(new FormData(form));
		await api('/organizer/events', { method: 'POST', body: payload });
		showToast('Event created.');
		await loadOrganizerWorkspace();
	} catch (error) { showToast(errorText(error), true); }
}

async function updateEvent(form) {
	const eventId = Number(form.dataset.eventUpdate);
	try {
		await api(`/organizer/events/${eventId}`, { method: 'PATCH', body: Object.fromEntries(new FormData(form)) });
		showToast('Event details saved.');
		await loadOrganizerWorkspace();
		byId(`manage-${eventId}`)?.classList.remove('is-hidden');
	} catch (error) { showToast(errorText(error), true); }
}

async function createTicketType(form) {
	const eventId = Number(form.dataset.eventTicketCreate);
	const values = Object.fromEntries(new FormData(form));
	values.base_price_xaf = Number(values.base_price_xaf);
	values.quantity = Number(values.quantity);
	values.discount = Number(values.discount || 0);
	try {
		await api(`/organizer/events/${eventId}/ticket-types`, { method: 'POST', body: values });
		showToast('Ticket type added.');
		await loadOrganizerWorkspace();
		byId(`manage-${eventId}`)?.classList.remove('is-hidden');
	} catch (error) { showToast(errorText(error), true); }
}

async function updateTicketType(form) {
	const ticketId = Number(form.dataset.ticketUpdate);
	const values = Object.fromEntries(new FormData(form));
	values.base_price_xaf = Number(values.base_price_xaf);
	values.quantity = Number(values.quantity);
	values.discount = Number(values.discount);
	try {
		await api(`/organizer/ticket-types/${ticketId}`, { method: 'PATCH', body: values });
		showToast('Ticket type updated.');
		await loadOrganizerWorkspace();
	} catch (error) { showToast(errorText(error), true); }
}

async function deleteTicketType(ticketId) {
	if (!window.confirm('Delete this ticket type? Ticket types with reservation or order history cannot be deleted.')) return;
	try {
		await api(`/organizer/ticket-types/${ticketId}`, { method: 'DELETE' });
		showToast('Ticket type deleted.');
		await loadOrganizerWorkspace();
	} catch (error) { showToast(errorText(error), true); }
}

async function cancelEvent(eventId) {
	if (!window.confirm('Cancel this event? Active holds will be released and paid tickets for this event will be refunded.')) return;
	try {
		const response = await api(`/organizer/events/${eventId}/cancel`, { method: 'POST' });
		const statuses = Object.entries(response.refunds || {}).map(([orderId, status]) => `Order #${orderId}: ${status}`).join(' · ');
		showToast(statuses ? `Event cancelled. Refunds: ${statuses}` : 'Event cancelled. No paid tickets needed refund.');
		await loadOrganizerWorkspace();
		byId(`manage-${eventId}`)?.classList.remove('is-hidden');
	} catch (error) { showToast(errorText(error), true); }
}

async function publishEvent(eventId) {
	if (!window.confirm('Publish this draft event? It will become visible in public event discovery.')) return;
	try {
		await api(`/organizer/events/${eventId}/publish`, { method: 'POST' });
		showToast('Event published.');
		await loadOrganizerWorkspace();
		byId(`manage-${eventId}`)?.classList.remove('is-hidden');
	} catch (error) { showToast(errorText(error), true); }
}

async function loadCurrentUser() {
	if (!state.token) {
		updateAccountControls();
		return;
	}
	const tokenIssuedAt = Number(sessionStorage.getItem(TOKEN_ISSUED_AT_KEY));
	if (tokenIssuedAt > 0 && Date.now() - tokenIssuedAt >= TOKEN_LIFETIME_MS) {
		clearSession(false);
		showToast('Your seven-day session expired. Sign in again to continue.', true);
		return;
	}
	try {
		const user = await api('/me');
		state.user = user;
	} catch {
		state.user = null;
	}
	updateAccountControls();
}

async function handleResetForm(form) {
	const message = byId('reset-message');
	const values = Object.fromEntries(new FormData(form));
	values.token = location.pathname.split('/').filter(Boolean).at(-1);
	const button = form.querySelector('button[type="submit"]');
	button.disabled = true;
	try {
		const response = await api('/reset-password', { method: 'POST', body: values });
		setMessage(message, response.status || 'Password updated. You can now sign in.', 'success');
	} catch (error) {
		setMessage(message, errorText(error), 'error');
	} finally { button.disabled = false; }
}

function renderInvitationForm() {
	const form = byId('gate-invitation-form');
	const signIn = byId('gate-invitation-signin');
	if (!state.invitationToken) {
		byId('gate-invitation-title').textContent = 'Invitation unavailable';
		byId('gate-invitation-message').textContent = 'This invitation link does not contain a valid token.';
		form.classList.add('is-hidden');
		signIn.classList.add('is-hidden');
		return;
	}

	const isSignedIn = Boolean(state.token && state.user);
	byId('invitation-account-fields').classList.toggle('is-hidden', isSignedIn);
	byId('gate-invitation-submit').textContent = isSignedIn ? 'Accept invitation' : 'Create account and accept';
	signIn.classList.toggle('is-hidden', isSignedIn);
	byId('gate-invitation-message').textContent = isSignedIn
		? `Signed in as ${state.user.email}. Accept to add event-scoped access without changing your account role.`
		: 'Create a gate staff account, or sign in with the email address that received this invitation.';
}

async function submitInvitation(form) {
	if (!state.invitationToken) return;
	const values = Object.fromEntries(new FormData(form));
	const payload = { token: state.invitationToken };
	if (!state.token) {
		payload.name = values.name;
		payload.password = values.password;
		payload.password_confirmation = values.password_confirmation;
	}
	const button = byId('gate-invitation-submit');
	button.disabled = true;
	setMessage(byId('gate-invitation-status'), 'Accepting invitation...');
	try {
		const response = await api('/gate-invitations/accept', { method: 'POST', body: payload });
		if (response.token) {
			saveSession(response.token, response.user);
		} else {
			state.user = response.user;
			updateAccountControls();
		}
		state.invitationToken = null;
		window.history.replaceState(null, '', '/gate-invitation/accept');
		byId('gate-invitation-title').textContent = 'Invitation accepted';
		byId('gate-invitation-message').textContent = 'Event-scoped gate access has been added to your account.';
		form.classList.add('is-hidden');
		byId('gate-invitation-signin').classList.add('is-hidden');
		setMessage(byId('gate-invitation-status'), 'Access accepted.', 'success');
	} catch (error) {
		const message = error.status === 404
			? 'This invitation is expired, revoked, or has already been used.'
			: error.status === 401
				? 'Sign in with the email address that received this invitation, then accept it again.'
				: errorText(error);
		setMessage(byId('gate-invitation-status'), message, 'error');
	} finally {
		button.disabled = false;
	}
}

function loadInvitationPage() {
	state.invitationToken = new URLSearchParams(location.search).get('token');
	renderInvitationForm();
}

async function handleReturnPage() {
	const success = location.pathname === '/checkout/success';
	showView('return');
	byId('return-title').textContent = success ? 'Thanks, you’re all set.' : 'Checkout was cancelled.';
	byId('return-message').textContent = success
		? 'We received your checkout return. Stripe’s webhook confirms payment; check My tickets for the final order status.'
		: 'No payment was completed. Your ticket holds remain reserved until they are released or expire.';
	if (success) {
		const sessionId = new URLSearchParams(location.search).get('session_id');
		if (sessionId) {
			try { await api(`/checkout/success?session_id=${encodeURIComponent(sessionId)}`); }
			catch (error) { showToast(errorText(error), true); }
		}
	} else {
		try { await api('/checkout/cancel'); } catch { /* The browser return does not determine payment state. */ }
	}
	state.cart = [];
	persistCart();
}

document.addEventListener('click', async (event) => {
	if (event.target.id === 'scrim') { closeCart(); return; }
	const target = event.target.closest('button');
	if (!target) return;
	if (target.dataset.nav) { showView(target.dataset.nav); return; }
	if (target.id === 'auth-open') { setAuthMode('login'); openDialog('auth-dialog'); return; }
	if (target.id === 'logout-button') {
		try { await api('/logout', { method: 'POST' }); } catch { /* Clear local state even if the network is unavailable. */ }
		clearSession();
		return;
	}
	if (target.id === 'cart-open') { openCart(); return; }
	if (target.id === 'gate-invitation-signin') { setAuthMode('login'); openDialog('auth-dialog'); return; }
	if (target.id === 'cart-close' || target.id === 'scrim') { closeCart(); return; }
	if (target.id === 'checkout-button') { await beginCheckout(); return; }
	if (target.id === 'cart-clear') { await releaseAllHolds(); return; }
	if (target.dataset.closeDialog) { closeDialog(target.dataset.closeDialog); return; }
	if (target.dataset.authMode) { setAuthMode(target.dataset.authMode); return; }
	if (target.hasAttribute('data-forgot-password')) { setAuthMode('forgot'); return; }
	if (target.hasAttribute('data-return-login')) { setAuthMode('login'); return; }
	if (target.dataset.eventOpen) { openEvent(target.dataset.eventOpen); return; }
	if (target.dataset.addEventHolds) { await addSelectedHolds(target.dataset.addEventHolds); return; }
	if (target.dataset.removeHold) { await releaseHold(target.dataset.removeHold); return; }
	if (target.dataset.page) { await loadEvents(Number(target.dataset.page)); return; }
	if (target.dataset.ticketPage) { await loadTicketPage(Number(target.dataset.ticketPage)); return; }
	if (target.dataset.ticketDetail) { openTicketDetail(Number(target.dataset.ticketDetail)); return; }
	if (target.dataset.ticketCopy) { await copyTicketCode(Number(target.dataset.ticketCopy)); return; }
	if (target.dataset.orderRefund) { await requestFullRefund(target.dataset.orderRefund); return; }
	if (target.hasAttribute('data-resend-verification')) { await resendVerification(); return; }
	if (target.dataset.eventManage) {
		const eventId = Number(target.dataset.eventManage);
		const panel = byId(`manage-${eventId}`);
		const shouldLoadGateManagement = panel.classList.contains('is-hidden');
		panel.classList.toggle('is-hidden');
		if (shouldLoadGateManagement) await loadGateManagement(eventId);
		return;
	}
	if (target.dataset.eventCancel) { await cancelEvent(target.dataset.eventCancel); return; }
	if (target.dataset.eventPublish) { await publishEvent(target.dataset.eventPublish); return; }
	if (target.dataset.invitationRevoke) { await revokeGateInvitation(Number(target.dataset.eventId), Number(target.dataset.invitationRevoke)); return; }
	if (target.dataset.assignmentRevoke) { await revokeGateAssignment(Number(target.dataset.eventId), Number(target.dataset.assignmentRevoke)); return; }
	if (target.dataset.deviceRevoke) { await revokeGateDevice(Number(target.dataset.eventId), Number(target.dataset.gateId), target.dataset.deviceRevoke); return; }
	if (target.dataset.deviceCopy) { await copyDeviceId(target.dataset.deviceCopy); return; }
	if (target.dataset.scanMode) { await setGateScanMode(target.dataset.scanMode); return; }
	if (target.id === 'snapshot-download') { await downloadGateSnapshot(); return; }
	if (target.id === 'scan-sync') { await syncOfflineScans(); return; }
	if (target.id === 'camera-start') { await startGateCamera(); return; }
	if (target.id === 'camera-stop') { stopGateCamera(); return; }
	if (target.dataset.ticketDelete) { await deleteTicketType(target.dataset.ticketDelete); }
});

document.addEventListener('submit', async (event) => {
	const form = event.target;
	if (form.id === 'event-filters') {
		event.preventDefault();
		await loadEvents(1);
	} else if (form.id === 'auth-form') {
		event.preventDefault();
		await submitAuth(form);
	} else if (form.id === 'forgot-form') {
		event.preventDefault();
		await submitForgot(form);
	} else if (form.id === 'reset-form') {
		event.preventDefault();
		await handleResetForm(form);
	} else if (form.id === 'gate-invitation-form') {
		event.preventDefault();
		await submitInvitation(form);
	} else if (form.id === 'create-event-form') {
		event.preventDefault();
		await createEvent(form);
	} else if (form.matches('.event-edit-form')) {
		event.preventDefault();
		await updateEvent(form);
	} else if (form.matches('.ticket-create-form')) {
		event.preventDefault();
		await createTicketType(form);
	} else if (form.matches('.ticket-edit-form')) {
		event.preventDefault();
		await updateTicketType(form);
	} else if (form.matches('.gate-create-form')) {
		event.preventDefault();
		await createGate(form);
	} else if (form.matches('.gate-update-form')) {
		event.preventDefault();
		await updateGate(form);
	} else if (form.matches('.gate-invitation-form')) {
		event.preventDefault();
		await inviteGateStaff(form);
	} else if (form.matches('.gate-device-form')) {
		event.preventDefault();
		await enrollGateDevice(form);
	} else if (form.id === 'scan-form') {
		event.preventDefault();
		await handleScanSubmit(form);
	}
});

document.addEventListener('change', async (event) => {
	if (event.target.id === 'scan-event-select') await handleGateSelectionChange();
	if (event.target.id === 'scan-gate-select') await handleGateChange();
	if (event.target.id === 'scan-device-select') await handleGateDeviceChange();
});

window.addEventListener('online', () => {
	updateGateNetworkStatus();
	updateGateControls();
});
window.addEventListener('offline', () => {
	updateGateNetworkStatus();
	updateGateControls();
	stopGateCamera();
});

async function boot() {
	updateAccountControls();
	renderCart();
	state.holdTimer = setInterval(updateHoldTimers, 1000);
	byId('ticket-detail-dialog').addEventListener('close', () => {
		state.ticketQrRender += 1;
		byId('ticket-detail').replaceChildren();
	});
	await loadCurrentUser();
	const path = location.pathname;
	if (path === '/password-reset' || path.startsWith('/password-reset/')) {
		showView('reset');
		byId('reset-form').elements.email.value = new URLSearchParams(location.search).get('email') || '';
	} else if (path === '/gate-invitation/accept') {
		showView('gate-invitation');
		loadInvitationPage();
	} else if (path === '/checkout/success' || path === '/checkout/cancel') {
		await handleReturnPage();
	} else {
		await loadEvents();
	}
	if (new URLSearchParams(location.search).has('verified')) showToast('Your email is verified. Organizer tools are ready.');
}

boot();
