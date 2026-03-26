// app.js – frontend logika pre tracker s hamburger menu a overlay dialógmi

const API = 'api.php';

// --------- Theme Management ---------
function initTheme() {
  const savedTheme = localStorage.getItem('tracker_theme') || 'auto';
  applyTheme(savedTheme);

  // Set up theme toggle handlers
  document.querySelectorAll('input[name="theme"]').forEach(radio => {
    if (radio.value === savedTheme) {
      radio.checked = true;
    }
    radio.addEventListener('change', (e) => {
      const theme = e.target.value;
      applyTheme(theme);
      localStorage.setItem('tracker_theme', theme);
    });
  });
}

function applyTheme(theme) {
  const root = document.documentElement;

  if (theme === 'dark') {
    root.setAttribute('data-theme', 'dark');
  } else if (theme === 'light') {
    root.setAttribute('data-theme', 'light');
  } else {
    // Auto - remove attribute to let CSS media query handle it
    root.removeAttribute('data-theme');
  }
}

// Initialize theme immediately (before PIN)
(function() {
  const savedTheme = localStorage.getItem('tracker_theme') || 'auto';
  applyTheme(savedTheme);
})();

// --------- Authentication (SSO + PIN) ---------
let pinCode = '';
const PIN_LENGTH = 4;
const SESSION_DURATION = 24 * 60 * 60 * 1000; // 24 hodín
let currentUser = null;
let ssoEnabled = false;
let isRedirecting = false; // Prevents content flash during redirect
let hasGoogleApi = false;
const DEBUG_MODE = new URLSearchParams(window.location.search).has('debug');

// URL parameters for deep linking (from email alerts)
const urlParams = new URLSearchParams(window.location.search);
const URL_LAT = urlParams.get('lat') ? parseFloat(urlParams.get('lat')) : null;
const URL_LNG = urlParams.get('lng') ? parseFloat(urlParams.get('lng')) : null;
const URL_DATE = urlParams.get('date'); // Format: YYYY-MM-DD

// Show authenticated content - call ONLY after successful auth
function showAuthenticatedContent() {
  document.body.classList.add('authenticated');
}

async function initPinSecurity() {
  try {
    // Check auth config to determine auth mode
    const configRes = await fetch(`${API}?action=auth_config`);
    const config = await configRes.json();

    if (config.ok && config.ssoEnabled) {
      ssoEnabled = true;
      await handleSSOAuth(config.loginUrl);
      return;
    }

    // User auth mode (username/password)
    if (config.ok && config.userAuthEnabled) {
      await handleUserAuth();
      return;
    }
  } catch (e) {
    console.warn('Auth config check failed, falling back to PIN mode', e);
  }

  // PIN mode fallback
  handlePINAuth();
}

async function handleUserAuth() {
  // Check if we have a valid token
  const token = getAuthToken();
  if (token) {
    try {
      const res = await fetch(`${API}?action=user_me`, {
        headers: { 'X-Auth-Token': token }
      });
      const data = await res.json();
      if (data.ok && data.authenticated && data.user) {
        currentUser = data.user;
        setStoredUser(data.user);
        showAuthenticatedContent();
        showUserInfo(data.user);
        updateAdminUI();
        initAppAfterAuth();
        return;
      }
    } catch (e) {
      console.warn('Token validation failed:', e);
    }
    clearAuthToken();
  }

  // Show login overlay
  showAuthenticatedContent(); // Show content with login overlay on top
  showLoginOverlay();
}

function showLoginOverlay() {
  let overlay = document.getElementById('loginOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'loginOverlay';
    overlay.className = 'pin-overlay';
    overlay.innerHTML = `
      <div class="pin-container" style="max-width:420px;">
        <div class="pin-header">
          <div class="app-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
              <circle cx="12" cy="10" r="3"/>
            </svg>
            <span style="font-size:1.2em;font-weight:700;color:var(--text);">Family Tracker</span>
          </div>
          <h2>Prihlásenie</h2>
          <p>Zadajte prihlasovacie údaje</p>
        </div>
        <form id="loginForm" autocomplete="on" style="display:flex;flex-direction:column;gap:16px;">
          <label style="display:flex;flex-direction:column;gap:4px;">
            <span style="font-size:0.85em;color:var(--muted-text);">Používateľské meno</span>
            <input type="text" id="loginUsername" name="username" autocomplete="username"
              style="padding:12px;border-radius:10px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);font-size:1em;"
              placeholder="admin" required autofocus>
          </label>
          <label style="display:flex;flex-direction:column;gap:4px;">
            <span style="font-size:0.85em;color:var(--muted-text);">Heslo</span>
            <input type="password" id="loginPassword" name="password" autocomplete="current-password"
              style="padding:12px;border-radius:10px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);font-size:1em;"
              placeholder="••••" required>
          </label>
          <div id="loginError" class="hidden" style="color:#ef4444;font-size:0.85em;text-align:center;padding:8px;background:rgba(239,68,68,0.1);border-radius:8px;"></div>
          <button type="submit" id="loginSubmitBtn"
            style="padding:14px;border-radius:10px;background:var(--primary,#3b82f6);color:white;border:none;font-size:1em;font-weight:600;cursor:pointer;transition:opacity 0.2s;">
            Prihlásiť sa
          </button>
        </form>
      </div>
    `;
    document.body.appendChild(overlay);

    document.getElementById('loginForm').addEventListener('submit', handleLogin);
  }
  overlay.classList.remove('hidden');
}

function hideLoginOverlay() {
  const overlay = document.getElementById('loginOverlay');
  if (overlay) overlay.classList.add('hidden');
}

async function handleLogin(e) {
  e.preventDefault();
  const username = document.getElementById('loginUsername').value.trim();
  const password = document.getElementById('loginPassword').value;
  const errorEl = document.getElementById('loginError');
  const btn = document.getElementById('loginSubmitBtn');

  if (!username || !password) {
    errorEl.textContent = 'Vyplňte meno a heslo';
    errorEl.classList.remove('hidden');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Prihlasujem...';
  errorEl.classList.add('hidden');

  try {
    const res = await fetch(`${API}?action=user_login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password })
    });
    const data = await res.json();

    if (data.ok && data.token) {
      setAuthToken(data.token);
      currentUser = data.user;
      setStoredUser(data.user);
      hideLoginOverlay();
      showUserInfo(data.user);
      updateAdminUI();
      initAppAfterAuth();
    } else {
      errorEl.textContent = data.error || 'Prihlásenie zlyhalo';
      errorEl.classList.remove('hidden');
    }
  } catch (err) {
    errorEl.textContent = 'Chyba pripojenia k serveru';
    errorEl.classList.remove('hidden');
  }

  btn.disabled = false;
  btn.textContent = 'Prihlásiť sa';
}

function updateAdminUI() {
  // Show/hide admin-only menu items
  const adminItems = document.querySelectorAll('.admin-only');
  // Handle both SSO (isAdmin) and user auth (is_admin) property names
  const isAdmin = currentUser && (currentUser.is_admin || currentUser.isAdmin);
  adminItems.forEach(el => {
    el.style.display = isAdmin ? '' : 'none';
  });
}

async function handleSSOAuth(loginUrl) {
  // If SSO fails, fall back to PIN instead of redirecting
  const fallbackToPIN = () => {
    console.log('[SSO] Falling back to PIN mode');
    ssoEnabled = false;
    handlePINAuth();
  };

  // Redirect helper - sets flag to prevent content flash and multiple redirects
  const redirectToLogin = (baseLoginUrl) => {
    if (isRedirecting) return; // Prevent multiple redirects
    isRedirecting = true;
    const returnUrl = encodeURIComponent(window.location.href);
    const url = baseLoginUrl || 'https://bagron.eu/login';
    const separator = url.includes('?') ? '&' : '?';
    window.location.href = `${url}${separator}redirect=${returnUrl}`;
    // Content stays hidden (no showAuthenticatedContent call)
  };

  try {
    const authRes = await fetch(`${API}?action=auth_me`, {
      credentials: 'include'
    });

    const authText = await authRes.text();

    // Check if response is HTML (not JSON)
    if (authText.trim().startsWith('<')) {
      console.error('[SSO] Received HTML instead of JSON - falling back to PIN');
      fallbackToPIN();
      return;
    }

    let auth;
    try {
      auth = JSON.parse(authText);
    } catch (parseErr) {
      console.error('[SSO] JSON parse failed - falling back to PIN');
      fallbackToPIN();
      return;
    }

    if (auth.ok && auth.authenticated && auth.user) {
      // User is authenticated via SSO - NOW show content
      currentUser = auth.user;
      showAuthenticatedContent(); // Show content only after confirmed auth
      hidePinOverlay();
      showUserInfo(auth.user);
      updateAdminUI();
      initAppAfterAuth();

      // Set up periodic session check (but don't redirect on failure)
      setInterval(checkSSOSession, 5 * 60 * 1000);
      window.addEventListener('focus', checkSSOSession);
    } else {
      // Not authenticated via SSO - DO NOT show content
      // SSO not authenticated

      // If debug mode, don't redirect
      if (DEBUG_MODE) {
        console.log('[SSO] Debug mode - not redirecting');
        fallbackToPIN();
        return;
      }

      // Redirect to SSO login - content stays hidden
      redirectToLogin(loginUrl);
    }
  } catch (e) {
    console.error('[SSO] Auth check failed:', e.message);
    // On any error, fall back to PIN mode instead of redirecting
    fallbackToPIN();
  }
}

async function checkSSOSession() {
  if (!ssoEnabled || isRedirecting) return;

  try {
    const authRes = await fetch(`${API}?action=auth_me`, {
      credentials: 'include'
    });
    const auth = await authRes.json();

    if (!auth.ok || !auth.authenticated) {
      if (isRedirecting) return; // Prevent multiple redirects
      isRedirecting = true;
      // Session expired - redirect to login with return URL
      const returnUrl = encodeURIComponent(window.location.href);
      const baseLoginUrl = auth.loginUrl || 'https://bagron.eu/login';
      const separator = baseLoginUrl.includes('?') ? '&' : '?';
      window.location.href = `${baseLoginUrl}${separator}redirect=${returnUrl}`;
    }
  } catch (e) {
    console.warn('Session check failed', e);
  }
}

function showUserInfo(user) {
  // Update UI to show logged in user name near hamburger menu
  const userNameDisplay = document.getElementById('userNameDisplay');
  const displayName = user.display_name || user.name || user.username || '';
  if (userNameDisplay && displayName) {
    userNameDisplay.textContent = displayName;
    userNameDisplay.classList.remove('hidden');
  }

  // Add logout button to hamburger menu
  addLogoutMenuItem();
}

function addLogoutMenuItem() {
  const hamburgerMenu = document.getElementById('hamburgerMenu');
  if (!hamburgerMenu) return;

  // Check if logout already exists
  if (document.getElementById('menuLogout')) return;

  const logoutBtn = document.createElement('button');
  logoutBtn.className = 'menu-item';
  logoutBtn.id = 'menuLogout';
  logoutBtn.innerHTML = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    Odhlásiť sa
  `;
  // Click handled by event delegation in initHamburgerMenu()
  hamburgerMenu.appendChild(logoutBtn);
}

async function handleLogout() {
  try {
    // User auth logout
    const token = getAuthToken();
    if (token) {
      await fetch(`${API}?action=user_logout`, {
        method: 'POST',
        headers: { 'X-Auth-Token': token }
      });
      clearAuthToken();
      currentUser = null;
      sessionStorage.clear();
      window.location.reload();
      return;
    }

    // SSO logout
    const res = await fetch(`${API}?action=auth_logout`, {
      credentials: 'include'
    });
    const data = await res.json();

    if (data.redirectUrl) {
      window.location.href = data.redirectUrl;
    } else {
      // Clear local session and reload
      sessionStorage.clear();
      window.location.reload();
    }
  } catch (e) {
    console.error('Logout failed', e);
    sessionStorage.clear();
    window.location.reload();
  }
}

function handlePINAuth() {
  // Original PIN auth logic
  const authToken = sessionStorage.getItem('tracker_auth');
  const authTime = sessionStorage.getItem('tracker_auth_time');

  if (authToken && authTime) {
    const elapsed = Date.now() - parseInt(authTime);
    if (elapsed < SESSION_DURATION) {
      // Autentifikácia je platná - show content
      showAuthenticatedContent();
      hidePinOverlay();
      initAppAfterAuth();
      return;
    }
  }

  // Show PIN overlay - content stays hidden until PIN is verified
  showAuthenticatedContent(); // Show content with PIN overlay on top
  showPinOverlay();
  setupPinHandlers();
}

function initAppAfterAuth() {
  // This will be called after successful auth (SSO, PIN, or User auth)
  // initializeApp() already calls initMap, initCalendar, initHamburgerMenu, addUIHandlers
  if (ssoEnabled || currentUser) {
    initTheme();
    initializeApp();
    initPerimeterManagement();
    // Preload Google API availability flag for data browser
    apiGet(`${API}?action=get_settings`).then(s => {
      if (s && s.ok && s.data) hasGoogleApi = !!s.data.has_google_api;
    }).catch(() => {});
  }
}

function showPinOverlay() {
  const overlay = $('#pinOverlay');
  if (overlay) {
    overlay.classList.remove('hidden');
  }
}

function hidePinOverlay() {
  const overlay = $('#pinOverlay');
  if (overlay) {
    overlay.classList.add('hidden');
  }
}

function setupPinHandlers() {
  // Číselné tlačidlá
  document.querySelectorAll('.pin-key[data-key]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-key');
      addPinDigit(key);
    });
  });

  // Delete tlačidlo
  const deleteBtn = $('#pinDelete');
  if (deleteBtn) {
    deleteBtn.addEventListener('click', removePinDigit);
  }

  // Klávesnica
  document.addEventListener('keydown', handlePinKeyboard);
}

function addPinDigit(digit) {
  if (pinCode.length < PIN_LENGTH) {
    pinCode += digit;
    updatePinDisplay();

    // Ak je PIN kompletný, over ho
    if (pinCode.length === PIN_LENGTH) {
      setTimeout(() => verifyPin(), 300);
    }
  }
}

function removePinDigit() {
  if (pinCode.length > 0) {
    pinCode = pinCode.slice(0, -1);
    updatePinDisplay();
    hidePinError();
  }
}

function handlePinKeyboard(e) {
  const overlay = $('#pinOverlay');
  if (overlay && overlay.classList.contains('hidden')) {
    document.removeEventListener('keydown', handlePinKeyboard);
    return;
  }

  if (e.key >= '0' && e.key <= '9') {
    e.preventDefault();
    addPinDigit(e.key);
  } else if (e.key === 'Backspace' || e.key === 'Delete') {
    e.preventDefault();
    removePinDigit();
  } else if (e.key === 'Enter' && pinCode.length === PIN_LENGTH) {
    e.preventDefault();
    verifyPin();
  }
}

function updatePinDisplay() {
  for (let i = 1; i <= PIN_LENGTH; i++) {
    const dot = $(`#pinDot${i}`);
    if (dot) {
      if (i <= pinCode.length) {
        dot.classList.add('filled');
      } else {
        dot.classList.remove('filled');
      }
    }
  }
}

async function verifyPin() {
  try {
    const response = await apiPost('verify_pin', { pin: pinCode });

    if (response && response.ok) {
      // PIN správny - ulož autentifikáciu
      const token = btoa(pinCode + Date.now());
      sessionStorage.setItem('tracker_auth', token);
      sessionStorage.setItem('tracker_auth_time', Date.now().toString());

      // Skry overlay
      hidePinOverlay();
      document.removeEventListener('keydown', handlePinKeyboard);

      // Inicializuj aplikáciu
      initializeApp();
    } else {
      // PIN nesprávny
      showPinError();
      resetPin();
    }
  } catch (err) {
    console.error('PIN verification error:', err);
    showPinError();
    resetPin();
  }
}

function showPinError() {
  const error = $('#pinError');
  if (error) {
    error.classList.remove('hidden');
  }
}

function hidePinError() {
  const error = $('#pinError');
  if (error) {
    error.classList.add('hidden');
  }
}

function resetPin() {
  pinCode = '';
  updatePinDisplay();
}

async function initializeApp() {
  initMap();
  initCalendar();
  initHamburgerMenu();
  initSubdomainSwitcher();
  addUIHandlers();
  await loadDevices();
  initDeviceUI();
  await loadAvailableDates();

  // Handle URL date parameter (from email alerts)
  if (URL_DATE && /^\d{4}-\d{2}-\d{2}$/.test(URL_DATE)) {
    const [year, month, day] = URL_DATE.split('-').map(Number);
    currentDate = new Date(year, month - 1, day);
  }

  await refresh();

  // Handle URL lat/lng parameters - zoom to specified location
  if (URL_LAT !== null && URL_LNG !== null && !isNaN(URL_LAT) && !isNaN(URL_LNG)) {
    // Zoom to the specified location
    map.setView([URL_LAT, URL_LNG], 17);

    // Add a temporary highlight marker
    const highlightIcon = L.divIcon({
      className: '',
      html: `
        <div style="
          width: 24px;
          height: 24px;
          background: #ef4444;
          border-radius: 50%;
          border: 4px solid white;
          box-shadow: 0 0 12px rgba(239, 68, 68, 0.8);
          animation: pulse-highlight 2s ease-in-out infinite;
        "></div>
        <style>
          @keyframes pulse-highlight {
            0%, 100% { transform: scale(1); box-shadow: 0 0 12px rgba(239, 68, 68, 0.8); }
            50% { transform: scale(1.2); box-shadow: 0 0 20px rgba(239, 68, 68, 1); }
          }
        </style>
      `,
      iconSize: [32, 32],
      iconAnchor: [16, 16]
    });

    const highlightMarker = L.marker([URL_LAT, URL_LNG], { icon: highlightIcon })
      .bindTooltip('Poloha z upozornenia', { permanent: false, opacity: 1 })
      .addTo(map);

    // Remove the highlight marker after 30 seconds
    setTimeout(() => {
      highlightMarker.remove();
    }, 30000);

    // Clean URL parameters after handling (optional - keeps URL clean)
    if (window.history.replaceState) {
      const cleanUrl = window.location.pathname;
      window.history.replaceState({}, document.title, cleanUrl);
    }
  }

}

// --------- Helpers ---------

// --------- Helpers ---------
const $ = (sel) => document.querySelector(sel);
const fmt = (d) => {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
};
const toTime = (iso) => {
  const d = new Date(iso);
  return d.toLocaleTimeString('sk-SK', { hour:'2-digit', minute:'2-digit' });
};
const dur = (fromIso, toIso) => {
  if (!toIso) return '';
  const a = new Date(fromIso).getTime();
  const b = new Date(toIso).getTime();
  const m = Math.max(0, Math.round((b - a) / 60000));
  return m + ' min';
};
// Auth token management
function getAuthToken() {
  return sessionStorage.getItem('tracker_user_token') || null;
}
function setAuthToken(token) {
  sessionStorage.setItem('tracker_user_token', token);
}
function clearAuthToken() {
  sessionStorage.removeItem('tracker_user_token');
  sessionStorage.removeItem('tracker_user_data');
}
function getStoredUser() {
  try {
    const data = sessionStorage.getItem('tracker_user_data');
    return data ? JSON.parse(data) : null;
  } catch { return null; }
}
function setStoredUser(user) {
  sessionStorage.setItem('tracker_user_data', JSON.stringify(user));
}

function authHeaders() {
  const token = getAuthToken();
  const headers = {};
  if (token) headers['X-Auth-Token'] = token;
  return headers;
}

async function apiGet(url) {
  const r = await fetch(url, { method:'GET', headers: authHeaders() });
  if (r.status === 401) { handleAuthExpired(); throw new Error('Auth expired'); }
  if (!r.ok) throw new Error(`GET ${url} -> ${r.status}`);
  return r.json();
}
async function apiPost(action, payload) {
  const r = await fetch(`${API}?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type':'application/json', ...authHeaders() },
    body: JSON.stringify(payload)
  });
  if (r.status === 401) { handleAuthExpired(); throw new Error('Auth expired'); }
  if (!r.ok) throw new Error(`POST ${action} -> ${r.status}`);
  return r.json();
}
async function apiDelete(action, payload) {
  const r = await fetch(`${API}?action=${action}`, {
    method: 'DELETE',
    headers: { 'Content-Type':'application/json', ...authHeaders() },
    body: JSON.stringify(payload)
  });
  if (r.status === 401) { handleAuthExpired(); throw new Error('Auth expired'); }
  if (!r.ok) throw new Error(`DELETE ${action} -> ${r.status}`);
  return r.json();
}

function handleAuthExpired() {
  clearAuthToken();
  currentUser = null;
  document.body.classList.remove('authenticated');
  showLoginOverlay();
}

// --------- Multi-Device State ---------
let devices = [];                    // All devices from API
let activeDeviceId = null;           // Selected device ID (null = all devices)
let deviceViewMode = 'all';          // 'all' or 'single'
let deviceLayers = {};               // Map of device_id -> L.layerGroup
let deviceVisibility = {};           // Map of device_id -> bool (toggle visibility)
let comparisonMode = false;          // Multi-device comparison
let comparisonDeviceIds = [];        // Devices selected for comparison
let comparisonDateFrom = '';
let comparisonDateTo = '';
let activeTableDeviceId = null;      // Which device's data is shown in the table

async function loadDevices() {
  try {
    const res = await apiGet(`${API}?action=get_devices`);
    if (res.ok && Array.isArray(res.data)) {
      devices = res.data;
      // Initialize visibility for all devices
      devices.forEach(d => {
        if (deviceVisibility[d.id] === undefined) {
          deviceVisibility[d.id] = true;
        }
      });
    }
  } catch (e) {
    console.error('Failed to load devices:', e);
    devices = [];
  }
  return devices;
}

function getDeviceById(id) {
  return devices.find(d => d.id === id) || null;
}

function getDeviceColor(deviceId) {
  const dev = getDeviceById(deviceId);
  return dev ? dev.color : '#3388ff';
}

function getDeviceName(deviceId) {
  const dev = getDeviceById(deviceId);
  return dev ? dev.name : 'Neznáme';
}

// --------- Map init ---------
let map, trackLayer, ibeaconLayer, legendControl, lastPositionMarker;
let selectorMap, selectorMarker; // Pre map selector v overlay
let circleMarkers = []; // Store markers for highlighting

function initMap() {
  map = L.map('map');
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap'
  }).addTo(map);
  trackLayer = L.layerGroup().addTo(map);
  ibeaconLayer = L.layerGroup().addTo(map);

  // Legenda (collapsible)
  legendControl = L.control({ position: 'bottomright' });
  legendControl.onAdd = function() {
    const container = L.DomUtil.create('div', 'leaflet-control custom-legend-container');
    
    // Toggle button
    const toggleBtn = L.DomUtil.create('button', 'legend-toggle-btn', container);
    toggleBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>';
    toggleBtn.title = 'Zobraziť/Skryť legendu';
    toggleBtn.setAttribute('aria-label', 'Zobraziť/Skryť legendu');
    
    // Legend content (initially hidden)
    const content = L.DomUtil.create('div', 'legend-content hidden', container);
    content.innerHTML = `
      <div class="legend-header">Legenda</div>
      <div class="leg-item"><span class="leg-line"></span> Trasa (GNSS / Wi-Fi)</div>
      <div class="leg-item"><span class="leg-dot blinking"></span> Posledná poloha</div>
      <div class="leg-item"><span class="leg-dot red"></span> iBeacon</div>
    `;
    
    // Toggle functionality
    toggleBtn.onclick = function(e) {
      e.stopPropagation();
      e.preventDefault();
      content.classList.toggle('hidden');
      toggleBtn.classList.toggle('active');
      return false;
    };
    
    // Prevent map interactions
    L.DomEvent.disableClickPropagation(container);
    L.DomEvent.disableScrollPropagation(container);
    
    return container;
  };
  legendControl.addTo(map);
}

function initSelectorMap() {
  if (selectorMap) return; // Už inicializovaná
  
  selectorMap = L.map('selectorMap').setView([48.1486, 17.1077], 13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap'
  }).addTo(selectorMap);
  
  // Klik na mapu pridá/presunie marker
  selectorMap.on('click', (e) => {
    if (selectorMarker) {
      selectorMarker.setLatLng(e.latlng);
    } else {
      selectorMarker = L.marker(e.latlng, { draggable: true }).addTo(selectorMap);
    }
    
    // Aktualizuj form fields
    $('#beaconLat').value = e.latlng.lat.toFixed(6);
    $('#beaconLng').value = e.latlng.lng.toFixed(6);
  });
}

// --------- Battery Status ---------
function formatTimeAgo(isoTimestamp) {
  try {
    const uploadTime = new Date(isoTimestamp);
    const now = new Date();
    const diffMs = now - uploadTime;
    const diffMinutes = Math.floor(diffMs / 60000);
    if (diffMinutes < 1) return 'teraz';
    if (diffMinutes < 60) return `pred ${diffMinutes}m`;
    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) {
      const rem = diffMinutes % 60;
      return rem > 0 && diffHours < 6 ? `pred ${diffHours}h ${rem}m` : `pred ${diffHours}h`;
    }
    const diffDays = Math.floor(diffHours / 24);
    const remH = diffHours % 24;
    return remH > 0 && diffDays < 7 ? `pred ${diffDays}d ${remH}h` : `pred ${diffDays}d`;
  } catch (e) {
    return '—';
  }
}

async function updateBatteryStatus() {
  // Device status is now shown in device management overlay via loadDevices()
  // Refresh devices data so status is up to date
  await loadDevices();
}

// --------- Hamburger Menu ---------
function initHamburgerMenu() {
  const btn = $('#hamburgerBtn');
  const menu = $('#hamburgerMenu');
  if (!btn || !menu) return;

  btn.addEventListener('click', () => {
    const isOpen = !menu.classList.contains('hidden');

    if (isOpen) {
      menu.classList.add('hidden');
      btn.classList.remove('active');
    } else {
      menu.classList.remove('hidden');
      btn.classList.add('active');

      // Zavri menu pri kliknuti mimo
      setTimeout(() => {
        const closeHandler = (e) => {
          if (!menu.contains(e.target) && !btn.contains(e.target)) {
            menu.classList.add('hidden');
            btn.classList.remove('active');
            document.removeEventListener('click', closeHandler);
          }
        };
        document.addEventListener('click', closeHandler);
      }, 0);
    }
  });

  // Use event delegation for all menu items - more robust on mobile
  menu.addEventListener('click', (e) => {
    const item = e.target.closest('.menu-item');
    if (!item) return;

    const id = item.id;

    // iBeacon submenu parent toggles submenu, does not close menu
    if (id === 'menuIBeacons') {
      e.stopPropagation();
      const submenuItems = item.parentElement.querySelector('.submenu-items');
      if (submenuItems) {
        submenuItems.classList.toggle('hidden');
        item.classList.toggle('expanded');
      }
      return;
    }

    // Close menu for all other items
    menu.classList.add('hidden');
    btn.classList.remove('active');

    switch (id) {
      case 'menuRefetch':
        refetchDay();
        break;
      case 'menuManageIBeacons':
        openIBeaconOverlay();
        break;
      case 'menuViewIBeacons':
        openIBeaconListOverlay();
        break;
      case 'menuSettings':
        openSettingsOverlay().catch(err => {
          console.error('Error opening settings:', err);
        });
        break;
      case 'menuPerimeters':
        openPerimeterOverlay();
        break;
      case 'menuDevices':
        openDeviceManagement();
        break;
      case 'menuUsers':
        openUserManagement();
        break;
      case 'menuLogout':
        handleLogout();
        break;
    }
  });
}

// --------- Site Switcher Widget (SSO) ---------
const SITE_SWITCHER_CONFIG = {
  sitesUrl: 'https://bagron.eu/sites.json',
  iconsUrl: 'https://bagron.eu/icons.json',
  // Fallback icons for common icon names
  fallbackIcons: {
    home: '🏠',
    book: '📚',
    graduation: '🎓',
    map: '🗺️',
    euro: '💶',
    pot: '🍲',
    chart: '📊',
    calendar: '📅',
    folder: '📁',
    settings: '⚙️',
    user: '👤',
    location: '📍',
    tracker: '🗺️'
  }
};

let sitesData = [];
let iconsData = {};

async function initSiteSwitcher() {
  const toggleBtn = $('#site-nav-toggle');
  const menu = $('#site-nav-menu');

  if (!toggleBtn || !menu) return;

  // Load sites and icons data
  await loadSiteSwitcherData();

  // Toggle menu on click
  toggleBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = !menu.classList.contains('hidden');

    if (isOpen) {
      menu.classList.add('hidden');
      toggleBtn.classList.remove('active');
    } else {
      menu.classList.remove('hidden');
      toggleBtn.classList.add('active');

      // Close menu when clicking outside
      setTimeout(() => {
        const closeHandler = (ev) => {
          if (!menu.contains(ev.target) && !toggleBtn.contains(ev.target)) {
            menu.classList.add('hidden');
            toggleBtn.classList.remove('active');
            document.removeEventListener('click', closeHandler);
          }
        };
        document.addEventListener('click', closeHandler);
      }, 0);
    }
  });
}

async function loadSiteSwitcherData() {
  const menu = $('#site-nav-menu');

  try {
    // Fetch sites.json with SSO credentials (required)
    const sitesRes = await fetch(SITE_SWITCHER_CONFIG.sitesUrl, { credentials: 'include' });

    if (sitesRes.ok) {
      sitesData = await sitesRes.json();
    } else {
      throw new Error('Failed to fetch sites.json');
    }

    // Try to fetch icons.json (optional - fallbacks will be used if unavailable)
    try {
      const iconsRes = await fetch(SITE_SWITCHER_CONFIG.iconsUrl, { credentials: 'include' });
      if (iconsRes.ok) {
        iconsData = await iconsRes.json();
      } else {
        // icons.json is optional, use fallbacks silently
        iconsData = {};
      }
    } catch {
      // icons.json fetch failed, use fallbacks silently
      iconsData = {};
    }

    // Render the sites menu
    renderSitesMenu();

    // Update current site indicator in the toggle button
    updateCurrentSiteIndicator();

  } catch (err) {
    console.error('Site switcher load error:', err);
    menu.innerHTML = '<div class="site-nav-error">Nepodarilo sa načítať weby</div>';

    // Keep default values
    sitesData = [];
    iconsData = {};
  }
}

function getIconEmoji(iconName) {
  // If iconName is already an emoji (charCode > 255), use it directly
  if (iconName && iconName.length > 0) {
    const firstChar = iconName.codePointAt(0);
    if (firstChar > 255) {
      return iconName;
    }
  }

  // Try to get from loaded icons.json
  if (iconsData && iconsData[iconName]) {
    return iconsData[iconName];
  }

  // Use fallback icons
  if (SITE_SWITCHER_CONFIG.fallbackIcons[iconName]) {
    return SITE_SWITCHER_CONFIG.fallbackIcons[iconName];
  }

  // Default emoji
  return '🌐';
}

function renderSitesMenu() {
  const menu = $('#site-nav-menu');
  if (!menu || !sitesData || sitesData.length === 0) {
    menu.innerHTML = '<div class="site-nav-loading">Žiadne weby</div>';
    return;
  }

  const currentOrigin = window.location.origin;

  const menuHtml = sitesData.map(site => {
    const emoji = getIconEmoji(site.icon);
    const isActive = isCurrentSite(site.url, currentOrigin);
    const activeClass = isActive ? 'active' : '';

    return `
      <a href="${escapeHtml(site.url)}" class="site-nav-item ${activeClass}">
        <span class="site-nav-icon">${emoji}</span>
        <div class="site-nav-info">
          <span class="site-nav-title">${escapeHtml(site.name)}</span>
          ${site.description ? `<span class="site-nav-desc">${escapeHtml(site.description)}</span>` : ''}
        </div>
      </a>
    `;
  }).join('');

  menu.innerHTML = menuHtml;
}

function isCurrentSite(siteUrl, currentOrigin) {
  try {
    const siteOrigin = new URL(siteUrl).origin;
    return siteOrigin === currentOrigin;
  } catch {
    return false;
  }
}

function updateCurrentSiteIndicator() {
  const iconEl = $('#site-nav-icon');
  const nameEl = $('#site-nav-name');

  if (!sitesData || sitesData.length === 0) return;

  const currentOrigin = window.location.origin;
  const currentSite = sitesData.find(site => isCurrentSite(site.url, currentOrigin));

  if (currentSite) {
    if (iconEl) {
      iconEl.textContent = getIconEmoji(currentSite.icon);
    }
    if (nameEl) {
      nameEl.textContent = currentSite.name;
    }
  }
}

// Legacy function name for backwards compatibility
function initSubdomainSwitcher() {
  initSiteSwitcher();
}

// --------- iBeacon Management Overlay ---------
let editingBeaconId = null;

function openIBeaconOverlay(beacon = null) {
  const overlay = $('#ibeaconOverlay');
  const title = $('#overlayTitle');
  
  if (beacon) {
    // Edit mode
    editingBeaconId = beacon.id;
    title.textContent = 'Upraviť iBeacon';
    $('#beaconId').value = beacon.id;
    $('#beaconName').value = beacon.name;
    $('#beaconMac').value = beacon.mac_address;
    $('#beaconLat').value = beacon.latitude;
    $('#beaconLng').value = beacon.longitude;
  } else {
    // Add mode
    editingBeaconId = null;
    title.textContent = 'Pridať iBeacon';
    $('#beaconId').value = '';
    $('#beaconName').value = '';
    $('#beaconMac').value = '';
    $('#beaconLat').value = '';
    $('#beaconLng').value = '';
  }
  
  // Skry map selector
  $('#mapSelector').classList.add('hidden');
  $('#ibeaconForm').classList.remove('hidden');
  
  overlay.classList.remove('hidden');
  
  // Focus prvý input
  setTimeout(() => $('#beaconName').focus(), 100);
}

function closeIBeaconOverlay() {
  $('#ibeaconOverlay').classList.add('hidden');
  editingBeaconId = null;
  
  // Vyčisti selector marker
  if (selectorMarker) {
    selectorMarker.remove();
    selectorMarker = null;
  }
}

function openMapSelector() {
  $('#ibeaconForm').classList.add('hidden');
  $('#mapSelector').classList.remove('hidden');
  
  // Inicializuj selector map ak ešte nie je
  if (!selectorMap) {
    initSelectorMap();
  }
  
  // Invalidate size po zobrazení
  setTimeout(() => {
    selectorMap.invalidateSize();
    
    // Ak sú vyplnené súradnice, zobraz marker
    const lat = parseFloat($('#beaconLat').value);
    const lng = parseFloat($('#beaconLng').value);
    
    if (!isNaN(lat) && !isNaN(lng)) {
      if (selectorMarker) {
        selectorMarker.setLatLng([lat, lng]);
      } else {
        selectorMarker = L.marker([lat, lng], { draggable: true }).addTo(selectorMap);
      }
      selectorMap.setView([lat, lng], 15);
    } else {
      // Predvolená pozícia (centrum Bratislavy)
      selectorMap.setView([48.1486, 17.1077], 13);
    }
  }, 100);
}

function confirmMapLocation() {
  if (selectorMarker) {
    const latlng = selectorMarker.getLatLng();
    $('#beaconLat').value = latlng.lat.toFixed(6);
    $('#beaconLng').value = latlng.lng.toFixed(6);
  }
  
  // Vráť sa späť na form
  $('#mapSelector').classList.add('hidden');
  $('#ibeaconForm').classList.remove('hidden');
}

function cancelMapSelect() {
  // Vráť sa späť na form bez zmien
  $('#mapSelector').classList.add('hidden');
  $('#ibeaconForm').classList.remove('hidden');
}

async function submitIBeacon() {
  const name = $('#beaconName').value.trim();
  const mac = $('#beaconMac').value.trim();
  const lat = parseFloat($('#beaconLat').value);
  const lng = parseFloat($('#beaconLng').value);
  
  if (!name || !mac || Number.isNaN(lat) || Number.isNaN(lng)) {
    alert('Vyplň všetky polia (názov, MAC, súradnice).');
    return;
  }
  
  await apiPost('upsert_ibeacon', { name, mac, latitude: lat, longitude: lng });
  
  // Zavri overlay a refresh
  closeIBeaconOverlay();
  await loadIBeacons();
  
  alert(editingBeaconId ? 'iBeacon aktualizovaný!' : 'iBeacon pridaný!');
}

// --------- iBeacon List Overlay ---------
async function openIBeaconListOverlay() {
  const overlay = $('#ibeaconListOverlay');
  overlay.classList.remove('hidden');
  
  // Načítaj zoznam
  await loadIBeaconsIntoOverlay();
}

function closeIBeaconListOverlay() {
  $('#ibeaconListOverlay').classList.add('hidden');
}

async function loadIBeaconsIntoOverlay() {
  const container = $('#ibeaconList');
  container.innerHTML = '<p>Načítavam...</p>';
  
  try {
    const list = await apiGet(`${API}?action=get_ibeacons`);
    
    if (!Array.isArray(list) || list.length === 0) {
      container.innerHTML = '<p style="color:#999;padding:16px">Žiadne iBeacony.</p>';
      return;
    }
    
    let html = list.map(b => `
      <div class="row" data-id="${b.id}">
        <div class="info">
          <strong>${b.name}</strong><br>
          <small>${b.mac_address}</small><br>
          <small style="color:#666">(${b.latitude?.toFixed(6)}, ${b.longitude?.toFixed(6)})</small>
        </div>
        <div class="actions">
          <button class="btn-edit" data-edit="${b.id}">Upraviť</button>
          <button class="btn-delete" data-del="${b.id}">Odstrániť</button>
        </div>
      </div>`).join('');
    
    container.innerHTML = html;
    
    // Edit handlers
    container.querySelectorAll('button[data-edit]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = parseInt(btn.getAttribute('data-edit'));
        const beacon = list.find(b => b.id === id);
        if (beacon) {
          closeIBeaconListOverlay();
          openIBeaconOverlay(beacon);
        }
      });
    });
    
    // Delete handlers
    container.querySelectorAll('button[data-del]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-del');
        if (!confirm('Zmazať iBeacon?')) return;
        
        await apiDelete('delete_ibeacon', { id: Number(id) });
        await loadIBeaconsIntoOverlay();
        await loadIBeacons(); // Refresh mapu
      });
    });
  } catch (err) {
    container.innerHTML = `<p style="color:#ef4444;padding:16px">Chyba: ${err.message}</p>`;
  }
}

// --------- Settings Overlay ---------
async function openSettingsOverlay() {
  const overlay = $('#settingsOverlay');

  if (!overlay) {
    console.error('Settings overlay not found in DOM!');
    return;
  }

  overlay.classList.remove('hidden');

  // Initialize tabs only once
  if (!overlay._tabsInitialized) {
    initSettingsTabs();
    overlay._tabsInitialized = true;
  }

  // Load current settings
  try {
    await loadCurrentSettings();
  } catch (err) {
    console.error('Error loading settings:', err);
  }
}

function initSettingsTabs() {
  const tabs = document.querySelectorAll('.settings-tab');
  const sections = document.querySelectorAll('.settings-section');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const targetSection = tab.dataset.tab;

      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');

      sections.forEach(s => s.classList.remove('open'));
      const target = document.querySelector(`.settings-section[data-section="${targetSection}"]`);
      if (target) {
        target.classList.add('open');
      }
    });
  });

  // Default first tab
  if (sections.length > 0 && !document.querySelector('.settings-section.open')) {
    sections[0].classList.add('open');
  }
  if (tabs.length > 0 && !document.querySelector('.settings-tab.active')) {
    tabs[0].classList.add('active');
  }
}

function closeSettingsOverlay() {
  $('#settingsOverlay').classList.add('hidden');
}

async function loadCurrentSettings() {
  try {
    const settings = await apiGet(`${API}?action=get_settings`);

    if (settings && settings.ok) {
      const data = settings.data;
      hasGoogleApi = !!data.has_google_api;
      // Naplň form fields
      $('#hysteresisMeters').value = data.hysteresis_meters || 50;
      $('#hysteresisMinutes').value = data.hysteresis_minutes || 30;
      $('#uniquePrecision').value = data.unique_precision || 6;
      $('#uniqueBucketMinutes').value = data.unique_bucket_minutes || 30;
      $('#macCacheMaxAgeDays').value = data.mac_cache_max_age_days || 3600;
      $('#googleForce').checked = data.google_force === '1' || data.google_force === 1;
      $('#logLevel').value = data.log_level || 'info';
      $('#fetchFrequencyMinutes').value = data.fetch_frequency_minutes || 5;
      $('#smartRefetchFrequencyMinutes').value = data.smart_refetch_frequency_minutes || 30;
      $('#smartRefetchDays').value = data.smart_refetch_days || 7;
      $('#batteryAlertEnabled').checked = data.battery_alert_enabled || false;
      $('#batteryAlertEmail').value = data.battery_alert_email || '';

      // Aktualizuj "aktuálne hodnoty" labels
      $('#currentHysteresisMeters').textContent = `(${data.hysteresis_meters || 50} m)`;
      $('#currentHysteresisMinutes').textContent = `(${data.hysteresis_minutes || 30} min)`;
      $('#currentUniquePrecision').textContent = `(${data.unique_precision || 6})`;
      $('#currentUniqueBucketMinutes').textContent = `(${data.unique_bucket_minutes || 30} min)`;
      $('#currentMacCacheMaxAgeDays').textContent = `(${data.mac_cache_max_age_days || 3600} dní)`;
      $('#currentGoogleForce').textContent = (data.google_force === '1' || data.google_force === 1) ? '(Zapnuté)' : '(Vypnuté)';
      const logLevelLabels = { 'error': 'Error', 'info': 'Info', 'debug': 'Debug' };
      $('#currentLogLevel').textContent = `(${logLevelLabels[data.log_level] || 'Info'})`;
      $('#currentFetchFrequencyMinutes').textContent = `(${data.fetch_frequency_minutes || 5} min)`;
      $('#currentSmartRefetchFrequencyMinutes').textContent = `(${data.smart_refetch_frequency_minutes || 30} min)`;
      $('#currentSmartRefetchDays').textContent = `(${data.smart_refetch_days || 7} dní)`;
      $('#currentBatteryAlertEnabled').textContent = data.battery_alert_enabled ? '(Zapnuté)' : '(Vypnuté)';
      $('#currentBatteryAlertEmail').textContent = data.battery_alert_email ? `(${data.battery_alert_email})` : '(—)';

      // Initialize theme toggle in settings
      initTheme();

    } else {
      console.warn('Settings response not OK');
    }
  } catch (err) {
    console.error('Failed to load settings:', err);
    alert(`Nepodarilo sa načítať nastavenia: ${err.message}`);
  }
}

async function saveSettings() {
  try {
    // Zober hodnoty z formulára
    const settings = {
      hysteresis_meters: parseInt($('#hysteresisMeters').value) || 50,
      hysteresis_minutes: parseInt($('#hysteresisMinutes').value) || 30,
      unique_precision: parseInt($('#uniquePrecision').value) || 6,
      unique_bucket_minutes: parseInt($('#uniqueBucketMinutes').value) || 30,
      mac_cache_max_age_days: parseInt($('#macCacheMaxAgeDays').value) || 3600,
      google_force: $('#googleForce').checked ? '1' : '0',
      log_level: $('#logLevel').value || 'info',
      fetch_frequency_minutes: parseInt($('#fetchFrequencyMinutes').value) || 5,
      smart_refetch_frequency_minutes: parseInt($('#smartRefetchFrequencyMinutes').value) || 30,
      smart_refetch_days: parseInt($('#smartRefetchDays').value) || 7,
      battery_alert_enabled: $('#batteryAlertEnabled').checked,
      battery_alert_email: $('#batteryAlertEmail').value.trim()
    };

    // Validácia hraničných hodnôt
    if (settings.hysteresis_meters < 10 || settings.hysteresis_meters > 500) {
      alert('Minimálna vzdialenosť zmeny polohy musí byť medzi 10 a 500 m');
      return;
    }
    if (settings.hysteresis_minutes < 5 || settings.hysteresis_minutes > 180) {
      alert('Minimálny čas zmeny polohy musí byť medzi 5 a 180 min');
      return;
    }
    if (settings.unique_precision < 4 || settings.unique_precision > 8) {
      alert('Presnosť súradníc musí byť medzi 4 a 8 desatinnými miestami');
      return;
    }
    if (settings.unique_bucket_minutes < 5 || settings.unique_bucket_minutes > 180) {
      alert('Časový interval pre unikátne polohy musí byť medzi 5 a 180 min');
      return;
    }
    if (settings.mac_cache_max_age_days < 1 || settings.mac_cache_max_age_days > 7200) {
      alert('Platnosť cache musí byť medzi 1 a 7200 dňami');
      return;
    }
    if (settings.fetch_frequency_minutes < 1 || settings.fetch_frequency_minutes > 60) {
      alert('Frekvencia bežného fetchu musí byť medzi 1 a 60 min');
      return;
    }
    if (settings.smart_refetch_frequency_minutes < 5 || settings.smart_refetch_frequency_minutes > 1440) {
      alert('Frekvencia Smart Refetch musí byť medzi 5 a 1440 min');
      return;
    }
    if (settings.smart_refetch_days < 1 || settings.smart_refetch_days > 30) {
      alert('Kontrolované obdobie musí byť medzi 1 a 30 dňami');
      return;
    }
    // Validate email if battery alert is enabled
    if (settings.battery_alert_enabled && !settings.battery_alert_email) {
      alert('Pre aktiváciu notifikácií o batérii je potrebné zadať emailovú adresu');
      return;
    }
    if (settings.battery_alert_email && !settings.battery_alert_email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
      alert('Zadajte platnú emailovú adresu');
      return;
    }

    // Ulož cez API
    const result = await apiPost('save_settings', settings);

    if (result && result.ok) {
      alert('Nastavenia boli úspešne uložené!');
      closeSettingsOverlay();
    } else {
      alert(`Chyba pri ukladaní: ${result.error || 'Neznáma chyba'}`);
    }
  } catch (err) {
    alert(`Chyba pri ukladaní nastavení: ${err.message}`);
  }
}

function resetSettingToDefault(settingName, defaultValue) {
  const fieldMap = {
    'hysteresis_meters': 'hysteresisMeters',
    'hysteresis_minutes': 'hysteresisMinutes',
    'unique_precision': 'uniquePrecision',
    'unique_bucket_minutes': 'uniqueBucketMinutes',
    'mac_cache_max_age_days': 'macCacheMaxAgeDays',
    'google_force': 'googleForce',
    'log_level': 'logLevel',
    'fetch_frequency_minutes': 'fetchFrequencyMinutes',
    'smart_refetch_frequency_minutes': 'smartRefetchFrequencyMinutes',
    'smart_refetch_days': 'smartRefetchDays',
    'battery_alert_enabled': 'batteryAlertEnabled',
    'battery_alert_email': 'batteryAlertEmail'
  };

  const fieldId = fieldMap[settingName];
  if (!fieldId) return;

  const field = $(`#${fieldId}`);
  if (!field) return;

  if (field.type === 'checkbox') {
    field.checked = (defaultValue === '1' || defaultValue === 1);
  } else {
    field.value = defaultValue;
  }
}

async function resetAllSettings() {
  if (!confirm('Naozaj chcete obnoviť všetky nastavenia na predvolené hodnoty?')) {
    return;
  }

  try {
    const result = await apiPost('reset_settings', {});

    if (result && result.ok) {
      alert('Všetky nastavenia boli obnovené na predvolené hodnoty!');
      await loadCurrentSettings();
    } else {
      alert(`Chyba pri obnove nastavení: ${result.error || 'Neznáma chyba'}`);
    }
  } catch (err) {
    alert(`Chyba pri obnove nastavení: ${err.message}`);
  }
}

// --------- Load iBeacons na mapu ---------
async function loadIBeacons() {
  ibeaconLayer.clearLayers();
  
  try {
    const list = await apiGet(`${API}?action=get_ibeacons`);
    
    if (!Array.isArray(list) || list.length === 0) return;
    
    // Markery
    list.forEach(b => {
      if (b.latitude == null || b.longitude == null) return;
      L.circleMarker([b.latitude, b.longitude], { 
        radius: 5, 
        color: '#ff3b30', 
        fillColor: '#ff3b30', 
        fillOpacity: 1 
      })
        .bindPopup(`<b>${b.name}</b><br>${b.mac_address}`)
        .addTo(ibeaconLayer);
    });
  } catch (err) {
    console.error('Load iBeacons error:', err);
  }
}

// --------- Table Sorting ---------
let sortColumn = null;
let sortDirection = 'asc';

function sortTable(columnIndex, rows) {
  const compare = (a, b) => {
    let aVal = a.sortValues[columnIndex];
    let bVal = b.sortValues[columnIndex];
    
    // Handle numbers
    if (typeof aVal === 'number' && typeof bVal === 'number') {
      return sortDirection === 'asc' ? aVal - bVal : bVal - aVal;
    }
    
    // Handle strings
    aVal = String(aVal);
    bVal = String(bVal);
    if (sortDirection === 'asc') {
      return aVal.localeCompare(bVal);
    } else {
      return bVal.localeCompare(aVal);
    }
  };
  
  return rows.sort(compare);
}

// --------- History + table ---------
async function loadHistory(dateStr) {
  $('#positionsTable').innerHTML = 'Načítavam…';
  trackLayer.clearLayers();
  circleMarkers = [];
  if (lastPositionMarker) {
    lastPositionMarker.remove();
    lastPositionMarker = null;
  }
  // Clean up cluster markers from previous load
  if (window._clusterMarkers) {
    window._clusterMarkers.forEach(m => m.remove());
    window._clusterMarkers = [];
  }
  window._lastPositionsForClustering = null;

  // Always load all devices' data — map shows visible, table filters per device
  let histUrl = `${API}?action=get_history&date=${encodeURIComponent(dateStr)}`;
  const data = await apiGet(histUrl);

  // Load previous day's last point
  const prevDate = new Date(dateStr);
  prevDate.setDate(prevDate.getDate() - 1);
  const prevDateStr = fmt(prevDate);
  let prevUrl = `${API}?action=get_history&date=${encodeURIComponent(prevDateStr)}`;
  const prevData = await apiGet(prevUrl);
  const lastPrevPoint = (prevData && prevData.length > 0) ? prevData[prevData.length - 1] : null;
  
  // OPRAVA: Rozlišuj medzi dneškom a minulým dňom
  if (!Array.isArray(data) || data.length === 0) {
    const today = fmt(new Date());
    const isToday = (dateStr === today);
    
    if (isToday) {
      // Pre dnešok zobraz poslednú známu polohu pre každé zariadenie
      if (devices.length > 1) {
        // Multi-device: fetch last position per device
        const allBounds = [];
        const lastPositions = [];

        // Clear device layers
        Object.values(deviceLayers).forEach(l => { l.clearLayers(); map.removeLayer(l); });
        deviceLayers = {};

        for (const dev of devices) {
          try {
            const lp = await apiGet(`${API}?action=get_last_position&device_id=${dev.id}`);
            if (lp && lp.latitude && lp.longitude) {
              const color = dev.color || '#3388ff';
              const layer = L.layerGroup();
              deviceLayers[dev.id] = layer;
              if (deviceVisibility[dev.id] !== false) layer.addTo(map);

              lastPositions.push({ p: lp, color, name: dev.name, did: dev.id, layer });
              allBounds.push([lp.latitude, lp.longitude]);
            }
          } catch (e) { /* skip */ }
        }

        if (lastPositions.length > 0) {
          renderLastPositionMarkers(lastPositions);
          if (allBounds.length > 0) map.fitBounds(allBounds, { padding: [50, 50] });

          // Table shows info for active device
          const activeLp = lastPositions.find(lp => lp.did === activeTableDeviceId) || lastPositions[0];
          const rows = [{
            i: 1, id: activeLp.p.id, from: activeLp.p.timestamp, to: null, durMin: 0,
            source: activeLp.p.source, lat: activeLp.p.latitude, lng: activeLp.p.longitude,
            isPrevDay: false, isLastKnown: true,
            sortValues: [1, new Date(activeLp.p.timestamp).getTime(), 0, 0, activeLp.p.source, activeLp.p.latitude, activeLp.p.longitude]
          }];
          renderTable(rows);
        } else {
          $('#positionsTable').innerHTML = 'Žiadne dáta pre dnes a žiadna posledná známa poloha.';
        }

        const info = document.createElement('div');
        info.style.cssText = 'padding: 12px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin-bottom: 12px; color: #92400e;';
        info.innerHTML = '<strong>Žiadny pohyb v tento deň.</strong> Zobrazená je posledná známa poloha.';
        if (lastPositions.length > 0) $('#positionsTable').prepend(info);
      } else {
        // Single device mode
        let lpUrl = `${API}?action=get_last_position`;
        const lastPosition = await apiGet(lpUrl);

        if (lastPosition && lastPosition.latitude && lastPosition.longitude) {
          const lpColor = (activeDeviceId) ? getDeviceColor(activeDeviceId) : '#ff9800';
          const markerHtml = `
            <style>@keyframes blink-marker{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(1.3)}}</style>
            <div style="width:16px;height:16px;background:${lpColor};border-radius:50%;border:3px solid white;box-shadow:0 0 4px rgba(0,0,0,.3);animation:blink-marker 1.5s ease-in-out infinite"></div>`;
          const icon = L.divIcon({ className: '', html: markerHtml, iconSize: [22, 22], iconAnchor: [11, 11] });
          lastPositionMarker = L.marker([lastPosition.latitude, lastPosition.longitude], { icon })
            .bindTooltip(`Posledná známa poloha<br>${new Date(lastPosition.timestamp).toLocaleString('sk-SK')}`,
              { permanent: false, opacity: 1 })
            .addTo(map);
          map.setView([lastPosition.latitude, lastPosition.longitude], 15);

          const rows = [{
            i: 1, id: lastPosition.id, from: lastPosition.timestamp, to: null, durMin: 0,
            source: lastPosition.source, lat: lastPosition.latitude, lng: lastPosition.longitude,
            isPrevDay: false, isLastKnown: true,
            sortValues: [1, new Date(lastPosition.timestamp).getTime(), 0, 0, lastPosition.source, lastPosition.latitude, lastPosition.longitude]
          }];
          renderTable(rows);

          const info = document.createElement('div');
          info.style.cssText = 'padding: 12px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin-bottom: 12px; color: #92400e;';
          info.innerHTML = '<strong>Žiadny pohyb v tento deň.</strong> Zobrazená je posledná známa poloha.';
          $('#positionsTable').prepend(info);
        } else {
          $('#positionsTable').innerHTML = 'Žiadne dáta pre dnes a žiadna posledná známa poloha.';
        }
      }
    } else {
      // OPRAVA: Pre minulý deň bez pohybu nezobrazuj tabuľku ani posledný bod
      $('#positionsTable').innerHTML = '<div style="padding: 12px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin: 12px; color: #92400e;"><strong>Žiadny pohyb v tento deň.</strong></div>';
    }
    return;
  }

  // Polyline - multi-device aware
  if (devices.length > 1) {
    // Group data by device_id
    const byDevice = {};
    data.forEach(p => {
      const did = p.device_id || 'unknown';
      if (!byDevice[did]) byDevice[did] = [];
      byDevice[did].push(p);
    });

    // Clear device layers
    Object.values(deviceLayers).forEach(l => { l.clearLayers(); map.removeLayer(l); });
    deviceLayers = {};

    let allBounds = [];
    Object.keys(byDevice).forEach(did => {
      const pts = byDevice[did];
      const color = pts[0].device_color || getDeviceColor(parseInt(did)) || '#3388ff';
      const name = pts[0].device_name || getDeviceName(parseInt(did));
      const lls = pts.map(p => [p.latitude, p.longitude]);
      allBounds.push(...lls);

      const layer = L.layerGroup();
      const polyline = L.polyline(lls, { color, weight: 3 });
      polyline.bindTooltip(name, { sticky: true });
      polyline.addTo(layer);

      // Last point for this device
      const lastPt = pts[pts.length - 1];
      L.circleMarker([lastPt.latitude, lastPt.longitude], {
        radius: 8, color, fillColor: color, fillOpacity: 0.9, weight: 2
      }).bindTooltip(`${name}: ${toTime(lastPt.timestamp)}`).addTo(layer);

      deviceLayers[did] = layer;
      if (deviceVisibility[parseInt(did)] !== false) {
        layer.addTo(map);
      }
    });

    if (allBounds.length > 0) {
      map.fitBounds(allBounds, { padding: [20, 20] });
    }
  } else {
    // Single device or legacy mode
    const routeColor = (deviceViewMode === 'single' && activeDeviceId) ? getDeviceColor(activeDeviceId) : '#3388ff';
    const latlngs = data.map(p => [p.latitude, p.longitude]);
    const line = L.polyline(latlngs, { color: routeColor, weight:3 }).addTo(trackLayer);
    map.fitBounds(line.getBounds(), { padding:[20,20] });
  }

  // Position dots and last-position markers
  if (devices.length > 1) {
    // Multi-device: group by device, add dots to each device layer
    const byDeviceDots = {};
    data.forEach(p => {
      const did = p.device_id || 'unknown';
      if (!byDeviceDots[did]) byDeviceDots[did] = [];
      byDeviceDots[did].push(p);
    });

    // Per-device last position markers for clustering
    const lastPositions = [];

    Object.keys(byDeviceDots).forEach(did => {
      const pts = byDeviceDots[did];
      const color = pts[0].device_color || getDeviceColor(parseInt(did)) || '#3388ff';
      const name = pts[0].device_name || getDeviceName(parseInt(did));
      const layer = deviceLayers[did];
      if (!layer) return;

      pts.forEach((p, idx) => {
        if (idx === pts.length - 1) {
          // Last position for this device - collect for cluster check
          lastPositions.push({ p, color, name, did, layer });
        } else {
          // Regular dot in device color
          const marker = L.circleMarker([p.latitude, p.longitude], {
            radius: 4, fillColor: color, color: '#fff', weight: 1, fillOpacity: 0.8
          })
          .bindTooltip(`${name}: ${new Date(p.timestamp).toLocaleString('sk-SK')}`, { permanent: false, opacity: 1 })
          .addTo(layer);

          marker.on('click', () => {
            highlightTableRowByCoords(p.latitude, p.longitude);
            highlightMarker(marker);
          });

          circleMarkers.push({ marker, lat: p.latitude, lng: p.longitude, deviceColor: color });
        }
      });
    });

    // Render last position markers with clustering for overlapping positions
    renderLastPositionMarkers(lastPositions);
  } else {
    // Single device mode
    const singleColor = (activeDeviceId) ? getDeviceColor(activeDeviceId) : '#3388ff';
    data.forEach((p, idx) => {
      if (idx === data.length - 1) {
        const markerHtml = `
          <style>@keyframes blink-marker{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(1.3)}}</style>
          <div style="width:16px;height:16px;background:${singleColor};border-radius:50%;border:3px solid white;box-shadow:0 0 4px rgba(0,0,0,.3);animation:blink-marker 1.5s ease-in-out infinite"></div>`;
        const icon = L.divIcon({ className: '', html: markerHtml, iconSize: [22, 22], iconAnchor: [11, 11] });
        lastPositionMarker = L.marker([p.latitude, p.longitude], { icon })
          .bindTooltip(new Date(p.timestamp).toLocaleString('sk-SK'), { permanent: false, opacity: 1 })
          .addTo(map);
        lastPositionMarker.on('click', () => { highlightTableRowByCoords(p.latitude, p.longitude); });
      } else {
        const marker = L.circleMarker([p.latitude, p.longitude], {
          radius: 4, fillColor: singleColor, color: '#fff', weight: 1, fillOpacity: 0.8
        })
        .bindTooltip(new Date(p.timestamp).toLocaleString('sk-SK'), { permanent: false, opacity: 1 })
        .addTo(trackLayer);
        marker.on('click', () => { highlightTableRowByCoords(p.latitude, p.longitude); highlightMarker(marker); });
        circleMarkers.push({ marker, lat: p.latitude, lng: p.longitude, deviceColor: singleColor });
      }
    });
  }

  // Cache data for table filtering by device
  window._lastHistoryData = data;
  window._lastHistoryDate = dateStr;
  window._lastPrevData = prevData;

  // Build table for active table device only
  buildTableFromData(data, dateStr);
}

// Render last position markers for each device, clustering nearby positions
function renderLastPositionMarkers(lastPositions) {
  if (!lastPositions.length) return;

  // Store for rebuilding clusters on visibility toggle
  window._lastPositionsForClustering = lastPositions;

  // Add per-device blinking markers to their device layers
  lastPositions.forEach(({ p, color, name, layer }) => {
    addBlinkingLastMarker(p, color, name, layer);
  });

  // Build cluster overlays for visible devices
  const visibleLastPos = lastPositions.filter(
    lp => deviceVisibility[parseInt(lp.did)] !== false
  );
  renderClusterOverlays(visibleLastPos);
}

// Render cluster overlay markers for nearby last positions (on map layer, not device layer)
function renderClusterOverlays(visibleLastPositions) {
  const CLUSTER_DISTANCE = 100;
  const clusters = [];
  const used = new Set();

  for (let i = 0; i < visibleLastPositions.length; i++) {
    if (used.has(i)) continue;
    const cluster = [visibleLastPositions[i]];
    used.add(i);
    for (let j = i + 1; j < visibleLastPositions.length; j++) {
      if (used.has(j)) continue;
      const d = haversineJs(visibleLastPositions[i].p.latitude, visibleLastPositions[i].p.longitude,
                            visibleLastPositions[j].p.latitude, visibleLastPositions[j].p.longitude);
      if (d < CLUSTER_DISTANCE) {
        cluster.push(visibleLastPositions[j]);
        used.add(j);
      }
    }
    clusters.push(cluster);
  }

  // Only create cluster overlay markers for groups of 2+
  clusters.forEach(cluster => {
    if (cluster.length < 2) return;

    const avgLat = cluster.reduce((s, c) => s + c.p.latitude, 0) / cluster.length;
    const avgLng = cluster.reduce((s, c) => s + c.p.longitude, 0) / cluster.length;

    const clusterHtml = `
      <style>@keyframes blink-marker{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(1.3)}}</style>
      <div style="width:28px;height:28px;background:linear-gradient(135deg,${cluster[0].color},${cluster[1] ? cluster[1].color : cluster[0].color});border-radius:50%;border:3px solid white;box-shadow:0 0 6px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:13px;animation:blink-marker 1.5s ease-in-out infinite">${cluster.length}</div>`;
    const clusterIcon = L.divIcon({ className: '', html: clusterHtml, iconSize: [34, 34], iconAnchor: [17, 17] });

    const popupContent = cluster.map(c => {
      const ts = new Date(c.p.timestamp).toLocaleString('sk-SK');
      return `<div style="display:flex;align-items:center;gap:6px;padding:3px 0"><span style="width:10px;height:10px;border-radius:50%;background:${c.color};display:inline-block;flex-shrink:0"></span><b>${c.name}</b>&nbsp;<small>${ts}</small></div>`;
    }).join('');

    const clusterMarker = L.marker([avgLat, avgLng], { icon: clusterIcon })
      .bindPopup(`<div style="min-width:180px">${popupContent}</div>`, { maxWidth: 300 })
      .addTo(map);

    if (!window._clusterMarkers) window._clusterMarkers = [];
    window._clusterMarkers.push(clusterMarker);
  });
}

function addBlinkingLastMarker(p, color, name, layer) {
  const markerHtml = `
    <style>@keyframes blink-marker{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(1.3)}}</style>
    <div style="width:16px;height:16px;background:${color};border-radius:50%;border:3px solid white;box-shadow:0 0 4px rgba(0,0,0,.3);animation:blink-marker 1.5s ease-in-out infinite"></div>`;
  const icon = L.divIcon({ className: '', html: markerHtml, iconSize: [22, 22], iconAnchor: [11, 11] });
  const m = L.marker([p.latitude, p.longitude], { icon })
    .bindTooltip(`${name}: ${new Date(p.timestamp).toLocaleString('sk-SK')}`, { permanent: false, opacity: 1 })
    .addTo(layer);
  m.on('click', () => { highlightTableRowByCoords(p.latitude, p.longitude); });
}

// Simple haversine for client-side distance check (meters)
function haversineJs(lat1, lon1, lat2, lon2) {
  const R = 6371000;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon/2) * Math.sin(dLon/2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

function buildTableFromData(data, dateStr) {
  // Filter data for the active table device
  let tableData = data;
  let tablePrevData = window._lastPrevData || [];
  if (devices.length > 1 && activeTableDeviceId) {
    tableData = data.filter(p => p.device_id === activeTableDeviceId);
    tablePrevData = tablePrevData.filter(p => p.device_id === activeTableDeviceId);
  }
  const lastPrevPoint = (tablePrevData && tablePrevData.length > 0) ? tablePrevData[tablePrevData.length - 1] : null;

  if (!tableData || tableData.length === 0) {
    $('#positionsTable').innerHTML = '<div style="padding: 12px; background: var(--muted-bg); border: 1px solid var(--border); border-radius: 8px; margin: 12px; color: var(--muted-text);">Žiadne dáta pre toto zariadenie v tento deň.</div>';
    return;
  }

  // tabuľka "od–do" s prvým riadkom z predošlého dňa
  const rows = [];

  // Ak existuje posledný bod z predošlého dňa, pridaj ho ako prvý riadok
  if (lastPrevPoint && tableData.length > 0) {
    const firstCur = tableData[0];
    const durMin = Math.max(0, Math.round((new Date(firstCur.timestamp) - new Date(lastPrevPoint.timestamp)) / 60000));
    rows.push({
      i: 0,
      id: lastPrevPoint.id,
      from: lastPrevPoint.timestamp,
      to: firstCur.timestamp,
      durMin: durMin,
      source: lastPrevPoint.source,
      lat: firstCur.latitude,
      lng: firstCur.longitude,
      isPrevDay: true,
      sortValues: [
        0,
        new Date(lastPrevPoint.timestamp).getTime(),
        new Date(firstCur.timestamp).getTime(),
        durMin,
        lastPrevPoint.source,
        firstCur.latitude,
        firstCur.longitude
      ]
    });
  }

  // Ostatné riadky z aktuálneho dňa
  for (let i=0; i<tableData.length; i++) {
    const cur = tableData[i];
    const nxt = tableData[i+1];
    const durMin = nxt ? Math.max(0, Math.round((new Date(nxt.timestamp) - new Date(cur.timestamp)) / 60000)) : 0;
    rows.push({
      i: i+1,
      id: cur.id,
      from: cur.timestamp,
      to: nxt ? nxt.timestamp : null,
      durMin: durMin,
      source: cur.source,
      lat: cur.latitude,
      lng: cur.longitude,
      isPrevDay: false,
      sortValues: [
        i+1,
        new Date(cur.timestamp).getTime(),
        nxt ? new Date(nxt.timestamp).getTime() : 0,
        durMin,
        cur.source,
        cur.latitude,
        cur.longitude
      ]
    });
  }
  renderTable(rows);
}

function renderTable(rows) {
  const headers = [
    { label: '#', sortable: true },
    { label: 'Od', sortable: true },
    { label: 'Do', sortable: true },
    { label: 'Trvanie', sortable: true },
    { label: 'Zdroj', sortable: true },
    { label: 'Lat', sortable: true },
    { label: 'Lng', sortable: true },
    { label: '✎', sortable: false }
  ];

  const thHtml = headers.map((h, idx) =>
    `<th class="${h.sortable ? 'sortable' : ''}" data-col="${idx}">${h.label}</th>`
  ).join('');

  const th = `<thead><tr>${thHtml}</tr></thead>`;

  const tb = rows.map((r, idx) => {
    let rowClass = '';
    let displayNum = r.i;
    let fromDisplay = toTime(r.from);
    let toDisplay = r.to ? toTime(r.to) : '—';

    if (r.isPrevDay) {
      rowClass = 'prev-day-row';
      displayNum = '↑';
    } else if (r.isLastKnown) {
      rowClass = 'last-known-row';
      displayNum = '📍';
      // Pre poslednú známu polohu zobraz plný dátum a čas
      const d = new Date(r.from);
      fromDisplay = d.toLocaleDateString('sk-SK', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
      }) + ' ' + d.toLocaleTimeString('sk-SK', {
        hour: '2-digit',
        minute: '2-digit'
      });
      toDisplay = 'teraz';
    }

    const editBtn = r.id ? `
      <button class="edit-position-btn" data-id="${r.id}" title="Upraviť záznam">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
      </button>` : '';

    return `
    <tr class="${rowClass}" data-lat="${r.lat}" data-lng="${r.lng}" data-idx="${idx}" data-id="${r.id || ''}">
      <td>${displayNum}</td>
      <td>${fromDisplay}</td>
      <td>${toDisplay}</td>
      <td>${dur(r.from, r.to)}</td>
      <td>${r.source}</td>
      <td>${r.lat.toFixed(6)}</td>
      <td>${r.lng.toFixed(6)}</td>
      <td>${editBtn}</td>
    </tr>`;
  }).join('');
  
  const html = `<table class="pos-table">${th}<tbody>${tb}</tbody></table>`;
  $('#positionsTable').innerHTML = html;

  // Add sorting handlers
  $('#positionsTable').querySelectorAll('th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const col = parseInt(th.getAttribute('data-col'));
      
      // Toggle direction or set new column
      if (sortColumn === col) {
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
      } else {
        sortColumn = col;
        sortDirection = 'asc';
      }
      
      // Remove all sort classes
      $('#positionsTable').querySelectorAll('th').forEach(h => {
        h.classList.remove('sort-asc', 'sort-desc');
      });
      
      // Add sort class to current
      th.classList.add(`sort-${sortDirection}`);
      
      // Sort and re-render
      const sorted = sortTable(col, rows);
      renderTable(sorted);
    });
  });

  // klik na riadok => zoom na bod a zvýrazni ho
  $('#positionsTable').querySelectorAll('tbody tr').forEach((tr, idx) => {
    tr.addEventListener('click', () => {
      const lat = parseFloat(tr.getAttribute('data-lat'));
      const lng = parseFloat(tr.getAttribute('data-lng'));
      
      // Remove previous highlights
      $('#positionsTable').querySelectorAll('tbody tr').forEach(row => {
        row.classList.remove('highlighted');
      });
      
      // Highlight clicked row
      tr.classList.add('highlighted');
      
      // Find and highlight corresponding marker
      circleMarkers.forEach(cm => {
        const markerLatLng = cm.marker.getLatLng();
        if (Math.abs(markerLatLng.lat - lat) < 0.000001 && Math.abs(markerLatLng.lng - lng) < 0.000001) {
          // Reset all markers to their device color
          circleMarkers.forEach(m => {
            m.marker.setStyle({
              radius: 4,
              fillColor: m.deviceColor || '#3388ff',
              color: '#fff',
              weight: 1,
              fillOpacity: 0.8
            });
          });

          // Highlight selected marker
          cm.marker.setStyle({
            radius: 8,
            fillColor: '#ff6b00',
            color: '#fff',
            weight: 2,
            fillOpacity: 1
          });
          
          // Open tooltip
          cm.marker.openTooltip();
        }
      });
      
      // Zoom to location
      map.setView([lat, lng], 16);
    });
  });

  // Add edit button click handlers
  $('#positionsTable').querySelectorAll('.edit-position-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation(); // Prevent row click
      const positionId = btn.getAttribute('data-id');
      if (positionId) {
        openPositionEditModal(parseInt(positionId));
      }
    });
  });
}

// --------- Map Marker Click Handlers ---------
function highlightTableRowByCoords(lat, lng) {
  const table = $('#positionsTable');
  if (!table) return;

  // Remove previous highlights
  table.querySelectorAll('tbody tr').forEach(row => {
    row.classList.remove('highlighted');
  });

  // Find and highlight matching row
  table.querySelectorAll('tbody tr').forEach(row => {
    const rowLat = parseFloat(row.getAttribute('data-lat'));
    const rowLng = parseFloat(row.getAttribute('data-lng'));
    if (Math.abs(rowLat - lat) < 0.000001 && Math.abs(rowLng - lng) < 0.000001) {
      row.classList.add('highlighted');
      // Scroll row into view
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
}

function highlightMarker(selectedMarker) {
  // Reset all markers to their device color
  circleMarkers.forEach(cm => {
    cm.marker.setStyle({
      radius: 4,
      fillColor: cm.deviceColor || '#3388ff',
      color: '#fff',
      weight: 1,
      fillOpacity: 0.8
    });
  });

  // Highlight selected marker
  selectedMarker.setStyle({
    radius: 8,
    fillColor: '#ff6b00',
    color: '#fff',
    weight: 2,
    fillOpacity: 1
  });

  // Open tooltip
  selectedMarker.openTooltip();
}

// --------- Position Edit Modal ---------
let positionEditMap = null;
let positionEditCurrentMarker = null;
let positionEditNewMarker = null;
let currentEditPosition = null;

function openPositionEditOverlay() {
  $('#positionEditOverlay').classList.remove('hidden');

  // Reset Google comparison UI
  $('#googleComparisonResult').style.display = 'none';
  pendingGoogleCoords = null;
  if (window.googleComparisonMarker && positionEditMap) {
    positionEditMap.removeLayer(window.googleComparisonMarker);
    window.googleComparisonMarker = null;
  }

  // Initialize map if not already done
  if (!positionEditMap) {
    setTimeout(() => {
      positionEditMap = L.map('positionEditMap').setView([48.1486, 17.1077], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
      }).addTo(positionEditMap);

      positionEditMap.on('click', (e) => {
        const { lat, lng } = e.latlng;
        $('#editNewLat').value = lat.toFixed(6);
        $('#editNewLng').value = lng.toFixed(6);
        updatePositionEditNewMarker(lat, lng);
      });
    }, 100);
  } else {
    setTimeout(() => positionEditMap.invalidateSize(), 100);
  }
}

function closePositionEditOverlay() {
  $('#positionEditOverlay').classList.add('hidden');
  // Clear markers
  if (positionEditCurrentMarker) {
    positionEditMap.removeLayer(positionEditCurrentMarker);
    positionEditCurrentMarker = null;
  }
  if (positionEditNewMarker) {
    positionEditMap.removeLayer(positionEditNewMarker);
    positionEditNewMarker = null;
  }
  currentEditPosition = null;
}

function updatePositionEditNewMarker(lat, lng) {
  if (positionEditNewMarker) {
    positionEditMap.removeLayer(positionEditNewMarker);
  }
  positionEditNewMarker = L.marker([lat, lng], {
    icon: L.divIcon({
      className: 'custom-marker',
      html: '<div style="background: #ef4444; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
      iconSize: [16, 16],
      iconAnchor: [8, 8]
    })
  }).addTo(positionEditMap);
}

let positionEditHistoryLayer = null;

async function openPositionEditModal(positionId) {
  try {
    const result = await apiGet(`${API}?action=get_position_detail&id=${positionId}`);

    if (!result.ok) {
      alert(`Chyba: ${result.error || 'Nepodarilo sa načítať záznam'}`);
      return;
    }

    currentEditPosition = result.data;

    // Fill in the form
    $('#editPositionId').value = currentEditPosition.id;
    $('#editPositionIdDisplay').textContent = currentEditPosition.id;

    const timestamp = new Date(currentEditPosition.timestamp);
    $('#editPositionTimestamp').textContent = timestamp.toLocaleString('sk-SK');
    $('#editPositionSource').textContent = currentEditPosition.source || '—';

    // MAC address display - separate primary_mac and all_macs
    const primaryMac = currentEditPosition.primary_mac;
    const allMacs = currentEditPosition.all_macs;
    // For backward compatibility: use first MAC from all_macs if primary_mac not set
    const effectiveMac = primaryMac || (allMacs ? allMacs.split(',')[0].trim() : null);
    const isWifiSource = currentEditPosition.source && currentEditPosition.source.includes('wifi');

    // Show primary MAC (the one that determined the position)
    if (primaryMac) {
      $('#editPrimaryMacRow').style.display = 'flex';
      $('#editPrimaryMac').textContent = primaryMac;
    } else {
      $('#editPrimaryMacRow').style.display = 'none';
    }

    // Show all MACs (comma-separated)
    if (allMacs) {
      $('#editAllMacsRow').style.display = 'flex';
      $('#editAllMacs').textContent = allMacs;
    } else {
      $('#editAllMacsRow').style.display = 'none';
    }

    // Show invalidate button and history for wifi sources with any MAC info
    if (isWifiSource && effectiveMac) {
      $('#invalidateMac').style.display = 'inline-flex';
      $('#macHistorySection').style.display = 'block';
      $('#macHistoryMacAddress').textContent = effectiveMac;
      loadMacHistory(effectiveMac);
    } else {
      $('#invalidateMac').style.display = 'none';
      $('#macHistorySection').style.display = 'none';
      $('#macHistoryBody').innerHTML = '';
    }

    $('#editCurrentLat').value = currentEditPosition.lat;
    $('#editCurrentLng').value = currentEditPosition.lng;
    $('#editNewLat').value = '';
    $('#editNewLng').value = '';

    openPositionEditOverlay();

    // Set map view and add current position marker
    setTimeout(() => {
      if (positionEditMap && currentEditPosition.lat && currentEditPosition.lng) {
        positionEditMap.setView([currentEditPosition.lat, currentEditPosition.lng], 16);

        // Clear previous layers
        if (positionEditCurrentMarker) {
          positionEditMap.removeLayer(positionEditCurrentMarker);
        }
        if (positionEditHistoryLayer) {
          positionEditMap.removeLayer(positionEditHistoryLayer);
          positionEditHistoryLayer = null;
        }

        positionEditCurrentMarker = L.marker([currentEditPosition.lat, currentEditPosition.lng], {
          icon: L.divIcon({
            className: 'custom-marker',
            html: '<div style="background: #3b82f6; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
            iconSize: [16, 16],
            iconAnchor: [8, 8]
          })
        }).addTo(positionEditMap);
      }
    }, 200);

  } catch (err) {
    alert(`Chyba pri načítavaní záznamu: ${err.message}`);
  }
}

let currentMacHistoryMac = null;

async function loadMacHistory(mac) {
  const tbody = $('#macHistoryBody');
  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Načítavam...</td></tr>';
  currentMacHistoryMac = mac;

  try {
    const result = await apiGet(`${API}?action=get_mac_history&mac=${encodeURIComponent(mac)}`);

    if (!result.ok || !result.data || result.data.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--muted-text);">Žiadna história</td></tr>';
      $('#refetchSelectedDays').disabled = true;
      $('#refetchAllMacDays').disabled = true;
      return;
    }

    tbody.innerHTML = result.data.map(h => {
      const d = new Date(h.timestamp);
      const dateStr = d.toLocaleDateString('sk-SK');
      const timeStr = d.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
      const coords = h.lat && h.lng ? `${h.lat.toFixed(5)}, ${h.lng.toFixed(5)}` : '—';
      const dateValue = d.toISOString().split('T')[0];
      return `<tr data-date="${dateValue}" data-lat="${h.lat || ''}" data-lng="${h.lng || ''}">
        <td><input type="checkbox" class="mac-history-checkbox" data-date="${dateValue}"></td>
        <td>${dateStr}</td>
        <td>${timeStr}</td>
        <td>${coords}</td>
      </tr>`;
    }).join('');

    $('#refetchAllMacDays').disabled = false;
    updateMacHistoryRefetchButton();

    // Add click handlers for history rows (on the row, not checkbox)
    tbody.querySelectorAll('tr').forEach(tr => {
      tr.addEventListener('click', (e) => {
        if (e.target.type === 'checkbox') return; // Don't handle checkbox clicks
        const date = tr.getAttribute('data-date');
        if (date) {
          tbody.querySelectorAll('tr').forEach(r => r.classList.remove('active'));
          tr.classList.add('active');
          loadHistoryDayOnMap(date);
        }
      });
    });

    // Checkbox change handlers
    tbody.querySelectorAll('.mac-history-checkbox').forEach(cb => {
      cb.addEventListener('change', updateMacHistoryRefetchButton);
    });

  } catch (err) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#ef4444;">Chyba načítania</td></tr>';
  }
}

function updateMacHistoryRefetchButton() {
  const checked = document.querySelectorAll('#macHistoryBody .mac-history-checkbox:checked').length;
  $('#refetchSelectedDays').disabled = checked === 0;
}

async function refetchSelectedMacDays() {
  const checkboxes = document.querySelectorAll('#macHistoryBody .mac-history-checkbox:checked');
  const dates = [...checkboxes].map(cb => cb.dataset.date);

  if (dates.length === 0) {
    alert('Vyberte aspoň jeden deň');
    return;
  }

  if (!confirm(`Refetch ${dates.length} dní? Existujúce záznamy budú vymazané a znovu načítané.`)) {
    return;
  }

  try {
    const result = await apiPost('batch_refetch_days', { dates });
    if (result.ok) {
      alert(`Úspešne naplánovaný refetch pre ${result.days.length} dní`);
      closePositionEditOverlay();
      loadCurrentDate();
    } else {
      alert(`Chyba: ${result.error}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

async function refetchAllMacDays() {
  if (!currentMacHistoryMac) return;

  if (!confirm(`Refetch všetkých dní kde MAC ${currentMacHistoryMac} určila polohu? Existujúce záznamy budú vymazané a znovu načítané.`)) {
    return;
  }

  try {
    const result = await apiPost('batch_refetch_days', { mac: currentMacHistoryMac });
    if (result.ok) {
      alert(`Úspešne naplánovaný refetch pre ${result.days.length} dní`);
      closePositionEditOverlay();
      loadCurrentDate();
    } else {
      alert(`Chyba: ${result.error}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

async function loadHistoryDayOnMap(date) {
  if (!positionEditMap) return;

  // Clear previous history layer
  if (positionEditHistoryLayer) {
    positionEditMap.removeLayer(positionEditHistoryLayer);
  }
  positionEditHistoryLayer = L.layerGroup().addTo(positionEditMap);

  try {
    const data = await apiGet(`${API}?action=get_history&date=${encodeURIComponent(date)}`);
    if (!Array.isArray(data) || data.length === 0) return;

    const coords = data.filter(p => p.latitude && p.longitude).map(p => [p.latitude, p.longitude]);

    if (coords.length === 0) return;

    // Draw polyline in red
    const polyline = L.polyline(coords, { color: '#ef4444', weight: 3, opacity: 0.7 });
    positionEditHistoryLayer.addLayer(polyline);

    // Add markers for each point
    data.forEach((p, i) => {
      if (!p.latitude || !p.longitude) return;
      const marker = L.circleMarker([p.latitude, p.longitude], {
        radius: 5,
        color: '#ef4444',
        fillColor: '#ef4444',
        fillOpacity: 0.8,
        weight: 2
      });
      const time = new Date(p.timestamp).toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
      marker.bindTooltip(`${time} - ${p.source}`);
      positionEditHistoryLayer.addLayer(marker);
    });

    // Fit bounds to show all points
    if (coords.length > 0) {
      const bounds = L.latLngBounds(coords);
      positionEditMap.fitBounds(bounds, { padding: [20, 20] });
    }
  } catch (err) {
    console.error('Failed to load history day:', err);
  }
}

async function savePositionEdit() {
  const positionId = parseInt($('#editPositionId').value);
  const newLat = parseFloat($('#editNewLat').value);
  const newLng = parseFloat($('#editNewLng').value);

  if (!positionId) {
    alert('Chýba ID záznamu');
    return;
  }

  if (isNaN(newLat) || isNaN(newLng)) {
    alert('Zadajte nové súradnice (kliknutím na mapu alebo manuálne)');
    return;
  }

  if (newLat < -90 || newLat > 90) {
    alert('Šírka musí byť medzi -90 a 90');
    return;
  }
  if (newLng < -180 || newLng > 180) {
    alert('Dĺžka musí byť medzi -180 a 180');
    return;
  }

  try {
    const result = await apiPost('update_position', {
      id: positionId,
      lat: newLat,
      lng: newLng
    });

    if (result.ok) {
      alert('Poloha bola úspešne aktualizovaná');
      closePositionEditOverlay();
      // Reload the current day's data
      loadData(fmt(currentDate));
    } else {
      alert(`Chyba: ${result.error || 'Nepodarilo sa uložiť zmeny'}`);
    }
  } catch (err) {
    alert(`Chyba pri ukladaní: ${err.message}`);
  }
}

async function invalidateMacCache() {
  if (!currentEditPosition || !currentEditPosition.mac_address) {
    alert('Tento záznam nemá MAC adresu');
    return;
  }

  const mac = currentEditPosition.mac_address;
  if (!confirm(`Naozaj chcete zneplatniť cache pre MAC adresu ${mac}?\n\nToto odstráni súradnice zo všetkých záznamov s touto MAC adresou a označí ju ako neplatnú.`)) {
    return;
  }

  try {
    const result = await apiPost('invalidate_mac', {
      mac: mac,
      position_id: currentEditPosition.id
    });

    if (result.ok) {
      alert(result.message || 'MAC cache bola zneplatnená');
      closePositionEditOverlay();
      // Reload the current day's data
      loadData(fmt(currentDate));
    } else {
      alert(`Chyba: ${result.error || 'Nepodarilo sa zneplatniť cache'}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

// Store pending Google coordinates for accept/reject
let pendingGoogleCoords = null;

async function testGoogleApiForPosition() {
  if (!currentEditPosition || !currentEditPosition.id) {
    alert('Nie je vybraný žiadny záznam');
    return;
  }

  const btn = $('#testGoogleApiBtn');
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/></svg> Volám API...`;

  try {
    const result = await apiPost('test_google_api', { position_id: currentEditPosition.id });

    if (!result.ok) {
      alert('Chyba: ' + (result.error || 'Nepodarilo sa zavolať Google API'));
      return;
    }

    // Store Google coordinates for accept action
    pendingGoogleCoords = {
      lat: result.google.lat,
      lng: result.google.lng
    };

    // Display comparison result
    const compDiv = $('#googleComparisonResult');
    compDiv.style.display = 'block';

    $('#compCacheLat').textContent = result.cache.lat.toFixed(6);
    $('#compCacheLng').textContent = result.cache.lng.toFixed(6);
    $('#compGoogleLat').textContent = result.google.lat.toFixed(6);
    $('#compGoogleLng').textContent = result.google.lng.toFixed(6);

    const diffLat = result.google.lat - result.cache.lat;
    const diffLng = result.google.lng - result.cache.lng;
    $('#compDiffLat').textContent = (diffLat >= 0 ? '+' : '') + diffLat.toFixed(6);
    $('#compDiffLng').textContent = (diffLng >= 0 ? '+' : '') + diffLng.toFixed(6);

    let distanceText = `Vzdialenosť: <strong>${result.distance_meters} m</strong>`;
    if (result.google.accuracy) {
      distanceText += ` (presnosť Google: ${result.google.accuracy} m)`;
    }
    distanceText += ` | Použitých ${result.macs_used} MAC adries`;
    $('#compDistance').innerHTML = distanceText;

    // Also show on map
    if (positionEditMap) {
      // Add green marker for Google position
      if (window.googleComparisonMarker) {
        positionEditMap.removeLayer(window.googleComparisonMarker);
      }
      window.googleComparisonMarker = L.marker([result.google.lat, result.google.lng], {
        icon: L.divIcon({
          className: 'custom-marker',
          html: '<div style="background: #22c55e; width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
          iconSize: [18, 18],
          iconAnchor: [9, 9]
        })
      }).addTo(positionEditMap);
      window.googleComparisonMarker.bindPopup('Google API').openPopup();
    }

  } catch (err) {
    console.error('Test Google API error:', err);
    alert('Chyba pri volaní Google API: ' + err.message);
  } finally {
    btn.innerHTML = originalText;
    btn.disabled = false;
  }
}

async function acceptGoogleCoordinates() {
  if (!pendingGoogleCoords || !currentEditPosition) {
    alert('Nie sú dostupné Google súradnice');
    return;
  }

  // Update position coordinates
  try {
    const result = await apiPost('update_position', {
      id: currentEditPosition.id,
      lat: pendingGoogleCoords.lat,
      lng: pendingGoogleCoords.lng
    });

    if (result.ok) {
      // Also update mac_locations for all MACs in this position
      if (currentEditPosition.all_macs) {
        const macs = currentEditPosition.all_macs.split(',').map(m => m.trim());
        await apiPost('retry_google_macs', { macs });
      }

      alert('Súradnice boli aktualizované na Google hodnoty');
      closePositionEditOverlay();
      loadData(fmt(currentDate));
    } else {
      alert('Chyba: ' + (result.error || 'Nepodarilo sa aktualizovať súradnice'));
    }
  } catch (err) {
    alert('Chyba: ' + err.message);
  }
}

// --------- Refetch day ---------
async function refetchDay() {
  const dateStr = fmt(currentDate);
  
  if (!confirm(`Znova načítať všetky dáta zo SenseCAP pre ${dateStr}?\n\nToto môže trvať niekoľko minút a overí, či neboli nahrané oneskorené záznamy.`)) {
    return;
  }
  
  try {
    const result = await apiPost('refetch_day', { date: dateStr });
    
    if (result.ok) {
      alert(`Refetch úspešne spustený pre ${dateStr}.\n\nPočkajte cca 30-60 sekúnd a potom obnovte stránku alebo prepnite na iný deň a späť.`);
    } else {
      alert(`Chyba pri refetch: ${result.error || 'Neznáma chyba'}`);
    }
  } catch (err) {
    alert(`Chyba pri refetch: ${err.message}`);
  }
}

// --------- Calendar ---------
let currentDate = new Date();
let calendarDate = new Date();
let availableDates = new Set();

async function loadAvailableDates() {
  try {
    const today = new Date();
    const past = new Date();
    past.setDate(today.getDate() - 90);
    
    const data = await apiGet(`${API}?action=get_history_range&since=${past.toISOString()}&until=${today.toISOString()}`);
    availableDates.clear();
    if (Array.isArray(data)) {
      data.forEach(p => {
        const date = p.timestamp.slice(0, 10);
        availableDates.add(date);
      });
    }
  } catch (e) {
    console.error('Failed to load available dates:', e);
  }
}

function renderCalendar() {
  const year = calendarDate.getFullYear();
  const month = calendarDate.getMonth();
  
  $('#calMonthYear').textContent = new Date(year, month).toLocaleDateString('sk-SK', { 
    year: 'numeric', 
    month: 'long' 
  });
  
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const adjustedFirstDay = (firstDay === 0) ? 6 : firstDay - 1;
  
  const grid = $('#calendarGrid');
  grid.innerHTML = '';
  
  // Headers
  ['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'].forEach(day => {
    const el = document.createElement('div');
    el.className = 'calendar-day header';
    el.textContent = day;
    grid.appendChild(el);
  });
  
  // Empty cells
  for (let i = 0; i < adjustedFirstDay; i++) {
    const el = document.createElement('div');
    el.className = 'calendar-day empty';
    grid.appendChild(el);
  }
  
  // Days
  const today = fmt(new Date());
  const selected = fmt(currentDate);
  
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month, day);
    const dateStr = fmt(date);
    const el = document.createElement('div');
    el.className = 'calendar-day active';
    el.textContent = day;
    
    if (dateStr === today) el.classList.add('today');
    if (dateStr === selected) el.classList.add('selected');
    if (availableDates.has(dateStr)) el.classList.add('has-data');
    
    el.addEventListener('click', async () => {
      currentDate = new Date(date);
      toggleCalendar();
      await refresh();
    });
    
    grid.appendChild(el);
  }
}

function toggleCalendar() {
  const dropdown = $('#calendarDropdown');
  const isHidden = dropdown.classList.contains('hidden');
  
  if (isHidden) {
    calendarDate = new Date(currentDate);
    renderCalendar();
    dropdown.classList.remove('hidden');
    
    setTimeout(() => {
      const closeHandler = (e) => {
        if (!dropdown.contains(e.target) && !$('#calendarToggle').contains(e.target)) {
          dropdown.classList.add('hidden');
          document.removeEventListener('click', closeHandler);
        }
      };
      document.addEventListener('click', closeHandler);
    }, 0);
  } else {
    dropdown.classList.add('hidden');
  }
}

function initCalendar() {
  $('#calendarToggle').addEventListener('click', toggleCalendar);
  
  $('#calPrevMonth').addEventListener('click', (e) => {
    e.stopPropagation();
    calendarDate.setMonth(calendarDate.getMonth() - 1);
    renderCalendar();
  });
  
  $('#calNextMonth').addEventListener('click', (e) => {
    e.stopPropagation();
    calendarDate.setMonth(calendarDate.getMonth() + 1);
    renderCalendar();
  });
}

// --------- Date controls ---------
function setDateLabel() {
  $('#currentDate').textContent = fmt(currentDate);
}

async function refresh() {
  setDateLabel();
  renderDeviceTabs();
  await loadHistory(fmt(currentDate));
  await loadIBeacons();
  await loadPerimetersOnMainMap();
  await updateBatteryStatus();
}

function addUIHandlers() {
  // Date navigation
  $('#prevDay').addEventListener('click', async () => {
    currentDate.setDate(currentDate.getDate() - 1);
    await refresh();
  });
  
  $('#nextDay').addEventListener('click', async () => {
    currentDate.setDate(currentDate.getDate() + 1);
    await refresh();
  });
  
  $('#today').addEventListener('click', async () => {
    currentDate = new Date();
    await refresh();
  });

  // Overlay controls
  $('#overlayClose').addEventListener('click', closeIBeaconOverlay);
  $('#listOverlayClose').addEventListener('click', closeIBeaconListOverlay);
  $('#settingsOverlayClose').addEventListener('click', closeSettingsOverlay);

  // Settings form buttons
  $('#saveSettings').addEventListener('click', saveSettings);
  $('#cancelSettings').addEventListener('click', closeSettingsOverlay);
  $('#resetAllSettings').addEventListener('click', resetAllSettings);

  // Individual reset buttons
  document.querySelectorAll('.btn-default').forEach(btn => {
    btn.addEventListener('click', () => {
      const settingName = btn.getAttribute('data-setting');
      const defaultValue = btn.getAttribute('data-default');
      resetSettingToDefault(settingName, defaultValue);
    });
  });

  // Form buttons
  $('#selectOnMap').addEventListener('click', openMapSelector);
  $('#confirmLocation').addEventListener('click', confirmMapLocation);
  $('#cancelMapSelect').addEventListener('click', cancelMapSelect);
  $('#submit').addEventListener('click', submitIBeacon);
  $('#cancelEdit').addEventListener('click', closeIBeaconOverlay);
  
  // Close overlay on background click
  $('#ibeaconOverlay').addEventListener('click', (e) => {
    if (e.target === $('#ibeaconOverlay')) {
      closeIBeaconOverlay();
    }
  });
  
  $('#ibeaconListOverlay').addEventListener('click', (e) => {
    if (e.target === $('#ibeaconListOverlay')) {
      closeIBeaconListOverlay();
    }
  });

  $('#settingsOverlay').addEventListener('click', (e) => {
    if (e.target === $('#settingsOverlay')) {
      closeSettingsOverlay();
    }
  });

  // Perimeter overlay handlers
  const perimeterOverlayClose = $('#perimeterOverlayClose');
  if (perimeterOverlayClose) {
    perimeterOverlayClose.addEventListener('click', closePerimeterOverlay);
  }

  const addNewPerimeter = $('#addNewPerimeter');
  if (addNewPerimeter) {
    addNewPerimeter.addEventListener('click', () => showPerimeterEditView(null));
  }

  const savePerimeterBtn = $('#savePerimeter');
  if (savePerimeterBtn) {
    savePerimeterBtn.addEventListener('click', savePerimeter);
  }

  const cancelPerimeterEdit = $('#cancelPerimeterEdit');
  if (cancelPerimeterEdit) {
    cancelPerimeterEdit.addEventListener('click', closePerimeterOverlay);
  }

  const clearPolygonBtn = $('#clearPolygon');
  if (clearPolygonBtn) {
    clearPolygonBtn.addEventListener('click', clearPolygon);
  }

  const addPerimeterEmailBtn = $('#addPerimeterEmail');
  if (addPerimeterEmailBtn) {
    addPerimeterEmailBtn.addEventListener('click', addEmailEntry);
  }

  const perimeterColorInput = $('#perimeterColor');
  if (perimeterColorInput) {
    perimeterColorInput.addEventListener('change', drawPolygonOnMap);
  }

  const sendTestEmailBtn = $('#sendTestEmail');
  if (sendTestEmailBtn) {
    sendTestEmailBtn.addEventListener('click', sendTestEmail);
  }

  const perimeterOverlay = $('#perimeterOverlay');
  if (perimeterOverlay) {
    perimeterOverlay.addEventListener('click', (e) => {
      if (e.target === perimeterOverlay) {
        closePerimeterOverlay();
      }
    });
  }

  // Trail date loading
  const loadTrailBtn = $('#loadTrailBtn');
  if (loadTrailBtn) {
    loadTrailBtn.addEventListener('click', async () => {
      const trailDateInput = $('#trailDate');
      if (trailDateInput && trailDateInput.value) {
        await loadPerimeterTrail(trailDateInput.value);
      }
    });
  }

  // Also load trail on date change (Enter key or change)
  const trailDateInput = $('#trailDate');
  if (trailDateInput) {
    trailDateInput.addEventListener('change', async () => {
      if (trailDateInput.value) {
        await loadPerimeterTrail(trailDateInput.value);
      }
    });
  }

  // Position edit modal handlers
  const positionEditClose = $('#positionEditClose');
  if (positionEditClose) {
    positionEditClose.addEventListener('click', closePositionEditOverlay);
  }

  const savePositionEditBtn = $('#savePositionEdit');
  if (savePositionEditBtn) {
    savePositionEditBtn.addEventListener('click', savePositionEdit);
  }

  const cancelPositionEditBtn = $('#cancelPositionEdit');
  if (cancelPositionEditBtn) {
    cancelPositionEditBtn.addEventListener('click', closePositionEditOverlay);
  }

  const invalidateMacBtn = $('#invalidateMac');
  if (invalidateMacBtn) {
    invalidateMacBtn.addEventListener('click', invalidateMacCache);
  }

  // MAC History refetch buttons
  const refetchSelectedBtn = $('#refetchSelectedDays');
  if (refetchSelectedBtn) {
    refetchSelectedBtn.addEventListener('click', refetchSelectedMacDays);
  }

  const refetchAllMacBtn = $('#refetchAllMacDays');
  if (refetchAllMacBtn) {
    refetchAllMacBtn.addEventListener('click', refetchAllMacDays);
  }

  const macHistorySelectAll = $('#macHistorySelectAll');
  if (macHistorySelectAll) {
    macHistorySelectAll.addEventListener('change', (e) => {
      document.querySelectorAll('#macHistoryBody .mac-history-checkbox').forEach(cb => {
        cb.checked = e.target.checked;
      });
      updateMacHistoryRefetchButton();
    });
  }

  // Test Google API button
  const testGoogleApiBtn = $('#testGoogleApiBtn');
  if (testGoogleApiBtn) {
    testGoogleApiBtn.addEventListener('click', testGoogleApiForPosition);
  }

  // Accept/Reject Google coordinates
  const acceptGoogleBtn = $('#acceptGoogleCoords');
  if (acceptGoogleBtn) {
    acceptGoogleBtn.addEventListener('click', acceptGoogleCoordinates);
  }

  const rejectGoogleBtn = $('#rejectGoogleCoords');
  if (rejectGoogleBtn) {
    rejectGoogleBtn.addEventListener('click', () => {
      $('#googleComparisonResult').style.display = 'none';
      pendingGoogleCoords = null;
    });
  }

  const positionEditOverlay = $('#positionEditOverlay');
  if (positionEditOverlay) {
    positionEditOverlay.addEventListener('click', (e) => {
      if (e.target === positionEditOverlay) {
        closePositionEditOverlay();
      }
    });
  }

  // Update new marker when input fields change
  const editNewLatInput = $('#editNewLat');
  const editNewLngInput = $('#editNewLng');
  if (editNewLatInput && editNewLngInput) {
    const updateMarkerFromInputs = () => {
      const lat = parseFloat(editNewLatInput.value);
      const lng = parseFloat(editNewLngInput.value);
      if (!isNaN(lat) && !isNaN(lng) && positionEditMap) {
        updatePositionEditNewMarker(lat, lng);
        positionEditMap.setView([lat, lng], positionEditMap.getZoom());
      }
    };
    editNewLatInput.addEventListener('change', updateMarkerFromInputs);
    editNewLngInput.addEventListener('change', updateMarkerFromInputs);
  }
}

// --------- MAC Address Management ---------
let macManagementMap = null;
let macManagementMarker = null;
let macManagementData = [];
let selectedMacAddress = null;
let macEditMarker = null;       // Marker on the main map for MAC editing
let macEditMode = false;        // Whether we're in MAC location editing mode

function initMacManagement() {
  // Filter apply button
  const filterBtn = $('#macFilterApply');
  if (filterBtn) {
    filterBtn.addEventListener('click', () => loadMacLocations());
  }

  // Quick filter: Last 7 days
  const last7DaysBtn = $('#macFilterLast7Days');
  if (last7DaysBtn) {
    last7DaysBtn.addEventListener('click', () => {
      const today = new Date();
      const weekAgo = new Date(today);
      weekAgo.setDate(weekAgo.getDate() - 7);
      $('#macFilterDateFrom').value = weekAgo.toISOString().split('T')[0];
      $('#macFilterDateTo').value = today.toISOString().split('T')[0];
      loadMacLocations();
    });
  }

  // Clear filter
  const clearFilterBtn = $('#macFilterClear');
  if (clearFilterBtn) {
    clearFilterBtn.addEventListener('click', () => {
      $('#macFilterSearch').value = '';
      $('#macFilterType').value = 'no-coords';
      $('#macFilterDateFrom').value = '';
      $('#macFilterDateTo').value = '';
      loadMacLocations();
    });
  }

  // Select all checkbox
  const selectAllCb = $('#macSelectAll');
  if (selectAllCb) {
    selectAllCb.addEventListener('change', (e) => {
      document.querySelectorAll('#macManagementBody input[type="checkbox"]').forEach(cb => {
        cb.checked = e.target.checked;
      });
      updateMacSelectedCount();
    });
  }

  // Save coordinates button
  const saveBtn = $('#macSaveCoords');
  if (saveBtn) {
    saveBtn.addEventListener('click', saveMacCoordinates);
  }

  // Retry Google button
  const retryBtn = $('#macRetryGoogle');
  if (retryBtn) {
    retryBtn.addEventListener('click', retryGoogleForSelectedMacs);
  }

  // Delete coordinates button
  const deleteBtn = $('#macDeleteCoords');
  if (deleteBtn) {
    deleteBtn.addEventListener('click', deleteSelectedMacCoordinates);
  }

  // Sortable headers
  document.querySelectorAll('.mac-management-table th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const sort = th.dataset.sort;
      loadMacLocations(sort);
    });
  });

  // Load initial data when section is opened (using MutationObserver for reliability)
  const macSection = document.querySelector('[data-section="mac-management"]');
  if (macSection) {
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.attributeName === 'class' && macSection.classList.contains('open')) {
          if (macManagementData.length === 0) {
            loadMacLocations();
          }
          // Init map when visible
          setTimeout(() => initMacManagementMap(), 200);
        }
      });
    });
    observer.observe(macSection, { attributes: true });
  }

  // MAC edit panel buttons (floating panel on main map)
  const panelClose = $('#macEditPanelClose');
  if (panelClose) panelClose.addEventListener('click', () => closeMacEditPanel());

  const panelCancel = $('#macEditPanelCancel');
  if (panelCancel) panelCancel.addEventListener('click', () => closeMacEditPanel());

  const panelSave = $('#macEditPanelSave');
  if (panelSave) panelSave.addEventListener('click', saveMacFromPanel);
}

function initMacManagementMap() {
  if (macManagementMap) return;

  const mapContainer = $('#macManagementMap');
  if (!mapContainer) return;

  macManagementMap = L.map('macManagementMap').setView([48.1486, 17.1077], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap'
  }).addTo(macManagementMap);

  macManagementMap.on('click', (e) => {
    const { lat, lng } = e.latlng;
    $('#macEditLat').value = lat.toFixed(6);
    $('#macEditLng').value = lng.toFixed(6);
    updateMacManagementMarker(lat, lng);
  });
}

function updateMacManagementMarker(lat, lng) {
  if (!macManagementMap) return;

  if (macManagementMarker) {
    macManagementMap.removeLayer(macManagementMarker);
  }

  macManagementMarker = L.marker([lat, lng]).addTo(macManagementMap);
  macManagementMap.setView([lat, lng], 16);
}

async function loadMacLocations(sortBy = 'date') {
  const tbody = $('#macManagementBody');
  if (!tbody) return;

  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;">Načítavam...</td></tr>';

  const type = $('#macFilterType')?.value || 'no-coords';
  const search = $('#macFilterSearch')?.value || '';
  const dateFrom = $('#macFilterDateFrom')?.value || '';
  const dateTo = $('#macFilterDateTo')?.value || '';

  try {
    const params = new URLSearchParams({
      action: 'get_mac_locations',
      type,
      sort: sortBy,
      dir: 'desc'
    });
    if (search) params.append('search', search);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);

    const result = await apiGet(`${API}?${params}`);

    if (!result.ok || !result.data) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#ef4444;">Chyba načítania</td></tr>';
      return;
    }

    macManagementData = result.data;

    if (result.data.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--muted-text);">Žiadne MAC adresy</td></tr>';
      return;
    }

    tbody.innerHTML = result.data.map(m => {
      const lastQueried = m.last_queried ? new Date(m.last_queried).toLocaleDateString('sk-SK') : '—';
      const lat = m.lat !== null ? m.lat.toFixed(5) : '—';
      const lng = m.lng !== null ? m.lng.toFixed(5) : '—';
      return `<tr data-mac="${m.mac}">
        <td><input type="checkbox" class="mac-select-cb" data-mac="${m.mac}"></td>
        <td style="font-family:monospace;font-size:0.85em;">${m.mac}</td>
        <td>${lat}</td>
        <td>${lng}</td>
        <td>${lastQueried}</td>
      </tr>`;
    }).join('');

    // Row click handlers
    tbody.querySelectorAll('tr').forEach(tr => {
      tr.addEventListener('click', (e) => {
        if (e.target.type === 'checkbox') return;
        const mac = tr.dataset.mac;
        selectMacForEdit(mac);
      });
    });

    // Checkbox handlers
    tbody.querySelectorAll('.mac-select-cb').forEach(cb => {
      cb.addEventListener('change', updateMacSelectedCount);
    });

  } catch (err) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#ef4444;">Chyba: ' + err.message + '</td></tr>';
  }
}

function selectMacForEdit(mac) {
  const macData = macManagementData.find(m => m.mac === mac);
  if (!macData) return;

  selectedMacAddress = mac;

  // Show edit section in overlay (keep for inline editing)
  $('#macSelectedInfo').style.display = 'block';
  $('#macSelectedAddress').textContent = mac;
  $('#macEditLat').value = macData.lat !== null ? macData.lat : '';
  $('#macEditLng').value = macData.lng !== null ? macData.lng : '';

  // Update inline map
  initMacManagementMap();
  if (macData.lat !== null && macData.lng !== null) {
    updateMacManagementMarker(macData.lat, macData.lng);
  } else {
    if (macManagementMarker) {
      macManagementMap.removeLayer(macManagementMarker);
      macManagementMarker = null;
    }
  }

  // Highlight selected row
  document.querySelectorAll('#macManagementBody tr').forEach(tr => {
    tr.classList.toggle('selected', tr.dataset.mac === mac);
  });

  // If MAC has coordinates, zoom main map to it and show edit panel
  openMacEditOnMainMap(macData);
}

function openMacEditOnMainMap(macData) {
  if (!map) return;

  // Close settings overlay to reveal the main map
  closeSettingsOverlay();

  // Enable MAC edit mode
  macEditMode = true;

  // Populate the floating panel
  const panel = $('#macEditPanel');
  $('#macEditPanelAddress').textContent = macData.mac;
  $('#macEditPanelLat').value = macData.lat !== null ? macData.lat : '';
  $('#macEditPanelLng').value = macData.lng !== null ? macData.lng : '';
  panel.classList.remove('hidden');

  // Remove previous edit marker
  if (macEditMarker) {
    map.removeLayer(macEditMarker);
    macEditMarker = null;
  }

  // Set view and add draggable marker if coords exist
  if (macData.lat !== null && macData.lng !== null) {
    map.setView([macData.lat, macData.lng], 17);
    placeMacEditMarker(macData.lat, macData.lng);
  } else {
    // No coords - keep current view, user will click to set
    map.setView(map.getCenter(), map.getZoom());
  }

  // Add map click handler for picking location
  map.on('click', onMacEditMapClick);
}

function onMacEditMapClick(e) {
  if (!macEditMode) return;
  const { lat, lng } = e.latlng;
  $('#macEditPanelLat').value = lat.toFixed(6);
  $('#macEditPanelLng').value = lng.toFixed(6);
  placeMacEditMarker(lat, lng);
}

function placeMacEditMarker(lat, lng) {
  if (!map) return;
  if (macEditMarker) {
    macEditMarker.setLatLng([lat, lng]);
  } else {
    macEditMarker = L.marker([lat, lng], { draggable: true }).addTo(map);
    macEditMarker.on('dragend', () => {
      const pos = macEditMarker.getLatLng();
      $('#macEditPanelLat').value = pos.lat.toFixed(6);
      $('#macEditPanelLng').value = pos.lng.toFixed(6);
    });
  }
  macEditMarker.bindPopup(`MAC: ${selectedMacAddress}`).openPopup();
}

async function saveMacFromPanel() {
  if (!selectedMacAddress) return;

  const lat = parseFloat($('#macEditPanelLat').value);
  const lng = parseFloat($('#macEditPanelLng').value);

  if (isNaN(lat) || isNaN(lng)) {
    alert('Zadajte platné súradnice');
    return;
  }

  try {
    const result = await apiPost('update_mac_coordinates', {
      mac: selectedMacAddress,
      lat,
      lng
    });

    if (result.ok) {
      alert('Súradnice uložené');
      closeMacEditPanel();  // This reopens settings on MAC tab
      loadMacLocations();
    } else {
      alert(`Chyba: ${result.error}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

function closeMacEditPanel(reopenSettings = true) {
  macEditMode = false;

  // Hide floating panel
  const panel = $('#macEditPanel');
  if (panel) panel.classList.add('hidden');

  // Remove edit marker from main map
  if (macEditMarker && map) {
    map.removeLayer(macEditMarker);
    macEditMarker = null;
  }

  // Remove map click handler
  if (map) {
    map.off('click', onMacEditMapClick);
  }

  // Reopen settings overlay on the MAC tab
  if (reopenSettings) {
    openSettingsOverlay();
    switchToSettingsTab('mac-management');
  }
}

function switchToSettingsTab(tabName) {
  const tabs = document.querySelectorAll('.settings-tab');
  const sections = document.querySelectorAll('.settings-section');
  tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
  sections.forEach(s => s.classList.toggle('open', s.dataset.section === tabName));
}

async function saveMacCoordinates() {
  if (!selectedMacAddress) {
    alert('Vyberte MAC adresu');
    return;
  }

  const lat = parseFloat($('#macEditLat').value);
  const lng = parseFloat($('#macEditLng').value);

  if (isNaN(lat) || isNaN(lng)) {
    alert('Zadajte platné súradnice');
    return;
  }

  try {
    const result = await apiPost('update_mac_coordinates', {
      mac: selectedMacAddress,
      lat,
      lng
    });

    if (result.ok) {
      alert('Súradnice uložené');
      loadMacLocations();
    } else {
      alert(`Chyba: ${result.error}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

function updateMacSelectedCount() {
  const count = document.querySelectorAll('#macManagementBody .mac-select-cb:checked').length;
  $('#macSelectedCount').textContent = `${count} vybraných`;
  $('#macRetryGoogle').disabled = count === 0;
  $('#macDeleteCoords').disabled = count === 0;
}

async function retryGoogleForSelectedMacs() {
  const checkboxes = document.querySelectorAll('#macManagementBody .mac-select-cb:checked');
  const macs = [...checkboxes].map(cb => cb.dataset.mac);

  if (macs.length === 0) {
    alert('Vyberte aspoň jednu MAC adresu');
    return;
  }

  const btn = $('#macRetryGoogle');
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="32"/></svg> Spracovávam...`;

  try {
    const response = await apiPost('retry_google_macs', { macs });

    if (!response.ok) {
      alert('Chyba: ' + (response.error || 'Neznáma chyba'));
      return;
    }

    // Build result message
    const summary = response.summary;
    const results = response.results || [];

    let message = `Google API výsledky:\n\n`;
    message += `Úspešných: ${summary.success} z ${summary.total}\n\n`;

    results.forEach(r => {
      if (r.status === 'success') {
        message += `✓ ${r.mac}: ${r.lat.toFixed(6)}, ${r.lng.toFixed(6)} (presnosť: ${r.accuracy ? r.accuracy + 'm' : '?'})\n`;
      } else {
        message += `✗ ${r.mac}: ${r.message || r.status}\n`;
      }
    });

    alert(message);

    // Reload the MAC list to show updated coordinates
    await loadMacLocations();

  } catch (err) {
    console.error('Retry Google API error:', err);
    alert('Chyba pri volaní Google API: ' + err.message);
  } finally {
    btn.innerHTML = originalText;
    btn.disabled = false;
    updateMacSelectionCount();
  }
}

async function deleteSelectedMacCoordinates() {
  const checkboxes = document.querySelectorAll('#macManagementBody .mac-select-cb:checked');
  const macs = [...checkboxes].map(cb => cb.dataset.mac);

  if (macs.length === 0) {
    alert('Vyberte aspoň jednu MAC adresu');
    return;
  }

  if (!confirm(`Naozaj chcete zmazať súradnice pre ${macs.length} MAC adries?\n\nToto nastaví lat/lng na NULL v mac_locations.`)) {
    return;
  }

  const btn = $('#macDeleteCoords');
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/></svg> Mažem...`;

  try {
    const response = await apiPost('delete_mac_coordinates', { macs });

    if (!response.ok) {
      alert('Chyba: ' + (response.error || 'Neznáma chyba'));
      return;
    }

    alert(response.message || `Súradnice zmazané pre ${response.deleted} MAC adries`);

    // Reload the MAC list
    await loadMacLocations();

  } catch (err) {
    console.error('Delete MAC coordinates error:', err);
    alert('Chyba pri mazaní súradníc: ' + err.message);
  } finally {
    btn.innerHTML = originalText;
    btn.disabled = false;
    updateMacSelectionCount();
  }
}

// --------- Perimeter Management ---------
let perimeterDrawMap = null;
let perimeterPolygonLayer = null;
let perimeterTrailLayer = null;
let perimeterPolygonPoints = [];
let perimeterMainMapLayer = null;
let editingPerimeterId = null;
let perimetersData = [];

function openPerimeterOverlay() {
  const overlay = $('#perimeterOverlay');
  overlay.classList.remove('hidden');
  showPerimeterListView();
  loadPerimeterList();
}

function closePerimeterOverlay() {
  $('#perimeterOverlay').classList.add('hidden');

  // Clean up edit state
  editingPerimeterId = null;
  perimeterPolygonPoints = [];

  // Clean up map if it exists
  if (perimeterDrawMap) {
    perimeterDrawMap.remove();
    perimeterDrawMap = null;
  }
  perimeterPolygonLayer = null;
  perimeterTrailLayer = null;
}

function showPerimeterListView() {
  $('#perimeterListView').classList.remove('hidden');
  $('#perimeterEditView').classList.add('hidden');
  $('#perimeterOverlayTitle').textContent = 'Perimeter zóny';

  // Clean up edit state
  editingPerimeterId = null;
  perimeterPolygonPoints = [];

  // Clean up map if it exists
  if (perimeterDrawMap) {
    perimeterDrawMap.remove();
    perimeterDrawMap = null;
  }
  perimeterPolygonLayer = null;
  perimeterTrailLayer = null;
}

function showPerimeterEditView(perimeter = null) {
  $('#perimeterListView').classList.add('hidden');
  $('#perimeterEditView').classList.remove('hidden');

  // Populate device selector
  const deviceSelect = $('#perimeterDeviceId');
  const deviceRow = $('#perimeterDeviceRow');
  if (deviceSelect && devices.length > 1) {
    deviceSelect.innerHTML = '<option value="">Všetky zariadenia</option>';
    devices.forEach(d => {
      const opt = document.createElement('option');
      opt.value = d.id;
      opt.textContent = d.name;
      deviceSelect.appendChild(opt);
    });
    if (deviceRow) deviceRow.classList.remove('hidden');
  } else if (deviceRow) {
    deviceRow.classList.add('hidden');
  }

  if (perimeter) {
    editingPerimeterId = perimeter.id;
    $('#perimeterOverlayTitle').textContent = 'Upraviť perimeter';
    $('#perimeterId').value = perimeter.id;
    $('#perimeterName').value = perimeter.name;
    $('#perimeterColor').value = perimeter.color || '#ff6b6b';
    perimeterPolygonPoints = perimeter.polygon || [];
    if (deviceSelect) deviceSelect.value = perimeter.device_id || '';

    // Populate emails list
    renderEmailsList(perimeter.emails || []);
  } else {
    editingPerimeterId = null;
    $('#perimeterOverlayTitle').textContent = 'Nový perimeter';
    $('#perimeterId').value = '';
    $('#perimeterName').value = '';
    $('#perimeterColor').value = '#ff6b6b';
    perimeterPolygonPoints = [];
    if (deviceSelect) deviceSelect.value = '';

    // Start with one empty email entry
    renderEmailsList([{ email: '', alert_on_enter: true, alert_on_exit: true }]);
  }

  updatePolygonPointCount();

  // Set default date to today
  const trailDateInput = $('#trailDate');
  if (trailDateInput) {
    trailDateInput.value = fmt(new Date());
  }

  // Initialize map after view is shown
  setTimeout(async () => {
    initPerimeterDrawMap();
    drawPolygonOnMap();

    // Load today's trail by default
    const dateStr = trailDateInput ? trailDateInput.value : fmt(new Date());
    await loadPerimeterTrail(dateStr);
  }, 100);
}

// Multiple emails management
function renderEmailsList(emails) {
  const container = $('#perimeterEmailsList');
  if (!container) return;

  container.innerHTML = '';

  if (emails.length === 0) {
    container.innerHTML = '<p class="perimeter-emails-empty">Žiadne e-maily. Pridajte aspoň jeden pre prijímanie notifikácií.</p>';
    return;
  }

  emails.forEach((emailData, index) => {
    container.appendChild(createEmailEntry(emailData, index));
  });
}

function createEmailEntry(emailData = {}, index = 0) {
  const div = document.createElement('div');
  div.className = 'perimeter-email-entry';
  div.dataset.index = index;

  const email = emailData.email || '';
  const alertOnEnter = emailData.alert_on_enter !== false;
  const alertOnExit = emailData.alert_on_exit !== false;

  div.innerHTML = `
    <input type="email" class="email-input" value="${email}" placeholder="email@example.com" autocomplete="off">
    <div class="perimeter-email-options">
      <label class="checkbox-label">
        <input type="checkbox" class="alert-enter" ${alertOnEnter ? 'checked' : ''}>
        <span>Vstup</span>
      </label>
      <label class="checkbox-label">
        <input type="checkbox" class="alert-exit" ${alertOnExit ? 'checked' : ''}>
        <span>Výstup</span>
      </label>
      <button type="button" class="btn-remove-email" onclick="removeEmailEntry(this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        Odstrániť
      </button>
    </div>
  `;

  return div;
}

function addEmailEntry() {
  const container = $('#perimeterEmailsList');
  if (!container) return;

  // Remove empty message if present
  const emptyMsg = container.querySelector('.perimeter-emails-empty');
  if (emptyMsg) emptyMsg.remove();

  const index = container.querySelectorAll('.perimeter-email-entry').length;
  container.appendChild(createEmailEntry({ email: '', alert_on_enter: true, alert_on_exit: true }, index));
}

function removeEmailEntry(btn) {
  const entry = btn.closest('.perimeter-email-entry');
  if (entry) {
    entry.remove();

    // Show empty message if no entries left
    const container = $('#perimeterEmailsList');
    if (container && container.querySelectorAll('.perimeter-email-entry').length === 0) {
      container.innerHTML = '<p class="perimeter-emails-empty">Žiadne e-maily. Pridajte aspoň jeden pre prijímanie notifikácií.</p>';
    }
  }
}

function getEmailsFromList() {
  const container = $('#perimeterEmailsList');
  if (!container) return [];

  const emails = [];
  container.querySelectorAll('.perimeter-email-entry').forEach(entry => {
    const email = entry.querySelector('.email-input')?.value?.trim() || '';
    const alertOnEnter = entry.querySelector('.alert-enter')?.checked ?? true;
    const alertOnExit = entry.querySelector('.alert-exit')?.checked ?? true;

    if (email) {
      emails.push({
        email: email,
        alert_on_enter: alertOnEnter,
        alert_on_exit: alertOnExit
      });
    }
  });

  return emails;
}

// Expose to global scope for onclick
window.removeEmailEntry = removeEmailEntry;

// Initialize perimeter management (called after auth)
function initPerimeterManagement() {
  // Add any perimeter-specific initialization here
  console.log('Perimeter management initialized');
}

function initPerimeterDrawMap() {
  if (perimeterDrawMap) {
    perimeterDrawMap.remove();
    perimeterDrawMap = null;
  }

  perimeterDrawMap = L.map('perimeterDrawMap').setView([48.1486, 17.1077], 13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap'
  }).addTo(perimeterDrawMap);

  // Trail layer (underneath polygon)
  perimeterTrailLayer = L.layerGroup().addTo(perimeterDrawMap);
  // Polygon layer (on top)
  perimeterPolygonLayer = L.layerGroup().addTo(perimeterDrawMap);

  // Click to add points
  perimeterDrawMap.on('click', (e) => {
    perimeterPolygonPoints.push({
      lat: e.latlng.lat,
      lng: e.latlng.lng
    });
    drawPolygonOnMap();
    updatePolygonPointCount();
  });

  // If editing and has points, fit bounds
  if (perimeterPolygonPoints.length >= 3) {
    const bounds = L.latLngBounds(perimeterPolygonPoints.map(p => [p.lat, p.lng]));
    perimeterDrawMap.fitBounds(bounds, { padding: [50, 50] });
  }
}

// Load tracker trail on perimeter map
async function loadPerimeterTrail(dateStr) {
  if (!perimeterTrailLayer || !perimeterDrawMap) return;

  perimeterTrailLayer.clearLayers();

  try {
    const data = await apiGet(`${API}?action=get_history&date=${encodeURIComponent(dateStr)}`);

    if (!Array.isArray(data) || data.length === 0) {
      console.log('No trail data for', dateStr);
      return;
    }

    // Draw polyline for the trail
    const latlngs = data.map(p => [p.latitude, p.longitude]);
    const line = L.polyline(latlngs, {
      color: '#3388ff',
      weight: 3,
      opacity: 0.7,
      dashArray: '5, 5'
    }).addTo(perimeterTrailLayer);

    // Add small markers for each point
    data.forEach((p, idx) => {
      const marker = L.circleMarker([p.latitude, p.longitude], {
        radius: 3,
        fillColor: '#3388ff',
        color: '#fff',
        weight: 1,
        fillOpacity: 0.8
      }).bindTooltip(new Date(p.timestamp).toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' }), {
        permanent: false,
        opacity: 0.9
      });
      perimeterTrailLayer.addLayer(marker);
    });

    // Fit to trail bounds if no polygon points
    if (perimeterPolygonPoints.length === 0) {
      const bounds = L.latLngBounds(latlngs);
      perimeterDrawMap.fitBounds(bounds, { padding: [50, 50] });
    }

  } catch (err) {
    console.error('Error loading trail:', err);
  }
}

function drawPolygonOnMap() {
  if (!perimeterPolygonLayer) return;

  perimeterPolygonLayer.clearLayers();

  const color = $('#perimeterColor').value || '#ff6b6b';

  // Draw markers for each point
  perimeterPolygonPoints.forEach((p, idx) => {
    const marker = L.circleMarker([p.lat, p.lng], {
      radius: 8,
      fillColor: color,
      color: '#fff',
      weight: 2,
      fillOpacity: 1
    }).bindTooltip(`Bod ${idx + 1}`, { permanent: false });

    // Right click to remove point
    marker.on('contextmenu', (e) => {
      e.originalEvent.preventDefault();
      perimeterPolygonPoints.splice(idx, 1);
      drawPolygonOnMap();
      updatePolygonPointCount();
    });

    perimeterPolygonLayer.addLayer(marker);
  });

  // Draw polygon if we have at least 3 points
  if (perimeterPolygonPoints.length >= 3) {
    const latlngs = perimeterPolygonPoints.map(p => [p.lat, p.lng]);
    const polygon = L.polygon(latlngs, {
      color: color,
      fillColor: color,
      fillOpacity: 0.2,
      weight: 2
    });
    perimeterPolygonLayer.addLayer(polygon);
  } else if (perimeterPolygonPoints.length === 2) {
    // Draw line if we have 2 points
    const latlngs = perimeterPolygonPoints.map(p => [p.lat, p.lng]);
    const line = L.polyline(latlngs, { color: color, weight: 2 });
    perimeterPolygonLayer.addLayer(line);
  }
}

function updatePolygonPointCount() {
  const count = perimeterPolygonPoints.length;
  const el = $('#polygonPointCount');
  if (el) {
    el.textContent = `Body: ${count}${count < 3 ? ' (min. 3)' : ''}`;
    el.style.color = count < 3 ? '#ef4444' : 'inherit';
  }
}

function clearPolygon() {
  perimeterPolygonPoints = [];
  drawPolygonOnMap();
  updatePolygonPointCount();
}

async function loadPerimeterList() {
  const container = $('#perimeterList');
  container.innerHTML = '<p class="perimeter-loading">Načítavam...</p>';

  try {
    const response = await apiGet(`${API}?action=get_perimeters`);

    if (!response.ok) {
      container.innerHTML = `<p class="perimeter-loading" style="color:#ef4444">${response.error || 'Chyba pri načítaní'}</p>`;
      return;
    }

    perimetersData = response.data || [];

    if (perimetersData.length === 0) {
      container.innerHTML = `
        <div class="perimeter-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5"/>
          </svg>
          <p>Zatiaľ nemáte žiadne perimeter zóny.<br>Pridajte prvú kliknutím na tlačidlo vyššie.</p>
        </div>
      `;
      return;
    }

    let html = perimetersData.map(p => {
      const badges = [];
      // Check if any email has entry/exit alerts enabled
      const hasEnterAlert = p.emails?.some(e => e.alert_on_enter) || p.alert_on_enter;
      const hasExitAlert = p.emails?.some(e => e.alert_on_exit) || p.alert_on_exit;
      if (hasEnterAlert) badges.push('<span class="perimeter-badge enter">Vstup</span>');
      if (hasExitAlert) badges.push('<span class="perimeter-badge exit">Opustenie</span>');
      if (!p.is_active) badges.push('<span class="perimeter-badge inactive">Neaktívny</span>');

      // Count emails from the emails array
      const emailCount = p.emails?.length || 0;
      const emailText = emailCount === 0 ? 'Bez e-mailu' :
                        emailCount === 1 ? '1 e-mail' :
                        `${emailCount} e-maily`;

      // Device name for multi-device
      let deviceText = '';
      if (devices.length > 1) {
        if (p.device_id) {
          const dev = devices.find(d => d.id === p.device_id);
          deviceText = dev ? dev.name : `#${p.device_id}`;
        } else {
          deviceText = 'Všetky';
        }
      }

      return `
        <div class="perimeter-item ${p.is_active ? '' : 'inactive'}" data-id="${p.id}">
          <div class="perimeter-color" style="background-color:${p.color || '#ff6b6b'}"></div>
          <div class="perimeter-info">
            <h4>${escapeHtml(p.name)}</h4>
            <small>${emailText} | ${p.polygon?.length || 0} bodov${deviceText ? ' | ' + escapeHtml(deviceText) : ''}</small>
            <div class="perimeter-badges">${badges.join('')}</div>
          </div>
          <div class="perimeter-actions">
            <button class="btn-toggle" data-toggle="${p.id}" title="${p.is_active ? 'Deaktivovať' : 'Aktivovať'}">
              ${p.is_active ? 'Vyp' : 'Zap'}
            </button>
            <button class="btn-edit" data-edit="${p.id}">Upraviť</button>
            <button class="btn-delete" data-delete="${p.id}">Zmazať</button>
          </div>
        </div>
      `;
    }).join('');

    container.innerHTML = html;

    // Add event handlers
    container.querySelectorAll('.btn-toggle').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.dataset.toggle);
        const perimeter = perimetersData.find(p => p.id === id);
        if (perimeter) {
          await togglePerimeter(id, !perimeter.is_active);
        }
      });
    });

    container.querySelectorAll('.btn-edit').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = parseInt(btn.dataset.edit);
        const perimeter = perimetersData.find(p => p.id === id);
        if (perimeter) {
          showPerimeterEditView(perimeter);
        }
      });
    });

    container.querySelectorAll('.btn-delete').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.dataset.delete);
        await deletePerimeter(id);
      });
    });

    // Refresh perimeters on main map
    loadPerimetersOnMainMap();

  } catch (err) {
    container.innerHTML = `<p class="perimeter-loading" style="color:#ef4444">Chyba: ${err.message}</p>`;
  }
}

async function savePerimeter() {
  const name = $('#perimeterName').value.trim();
  const color = $('#perimeterColor').value;
  const emails = getEmailsFromList();

  if (!name) {
    alert('Zadajte názov perimetra');
    return;
  }

  if (perimeterPolygonPoints.length < 3) {
    alert('Polygón musí mať aspoň 3 body');
    return;
  }

  // Validate emails
  for (const e of emails) {
    if (!isValidEmail(e.email)) {
      alert(`Neplatný e-mail: ${e.email}`);
      return;
    }
  }

  try {
    const deviceIdVal = $('#perimeterDeviceId')?.value || '';
    const payload = {
      name: name,
      polygon: perimeterPolygonPoints,
      emails: emails,
      color: color,
      is_active: true,
      device_id: deviceIdVal ? parseInt(deviceIdVal) : null
    };

    if (editingPerimeterId) {
      payload.id = editingPerimeterId;
    }

    const result = await apiPost('save_perimeter', payload);

    if (result.ok) {
      alert(editingPerimeterId ? 'Perimeter aktualizovaný!' : 'Perimeter vytvorený!');
      showPerimeterListView();
      await loadPerimeterList();
      await loadPerimetersOnMainMap();
    } else {
      alert(`Chyba: ${result.error || 'Neznáma chyba'}`);
    }
  } catch (err) {
    alert(`Chyba pri ukladaní: ${err.message}`);
  }
}

async function togglePerimeter(id, isActive) {
  try {
    const result = await apiPost('toggle_perimeter', { id: id, is_active: isActive });
    if (result.ok) {
      await loadPerimeterList();
    } else {
      alert(`Chyba: ${result.error}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

async function deletePerimeter(id) {
  if (!confirm('Naozaj chcete zmazať tento perimeter?')) {
    return;
  }

  try {
    const result = await apiDelete('delete_perimeter', { id: id });
    if (result.ok) {
      await loadPerimeterList();
    } else {
      alert(`Chyba: ${result.error}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Load perimeters on main map
async function loadPerimetersOnMainMap() {
  if (!perimeterMainMapLayer) {
    perimeterMainMapLayer = L.layerGroup().addTo(map);
  }

  perimeterMainMapLayer.clearLayers();

  try {
    const response = await apiGet(`${API}?action=get_perimeters`);

    if (!response.ok || !response.data) return;

    response.data.forEach(p => {
      if (!p.is_active || !p.polygon || p.polygon.length < 3) return;

      const latlngs = p.polygon.map(pt => [pt.lat, pt.lng]);
      const polygon = L.polygon(latlngs, {
        color: p.color || '#ff6b6b',
        fillColor: p.color || '#ff6b6b',
        fillOpacity: 0.15,
        weight: 2,
        dashArray: '5, 5'
      });

      polygon.bindTooltip(p.name, { permanent: false, direction: 'center' });
      perimeterMainMapLayer.addLayer(polygon);
    });
  } catch (err) {
    console.error('Error loading perimeters on map:', err);
  }
}

// Test email function - defined on window for onclick access
window.sendTestEmail = async function() {
  const emailInput = $('#testEmailAddress');
  const resultEl = $('#testEmailResult');
  const btn = $('#sendTestEmail');

  const email = emailInput?.value?.trim();

  if (!email) {
    resultEl.textContent = 'Zadajte e-mailovú adresu';
    resultEl.className = 'test-email-result error';
    return;
  }

  // Basic email validation
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(email)) {
    resultEl.textContent = 'Neplatná e-mailová adresa';
    resultEl.className = 'test-email-result error';
    return;
  }

  // Show loading state
  btn.disabled = true;
  resultEl.textContent = 'Odosielam testovací e-mail...';
  resultEl.className = 'test-email-result loading';

  try {
    // Use fetch directly to handle error responses with JSON body
    const r = await fetch(`${API}?action=test_email`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    });

    let response;
    try {
      response = await r.json();
    } catch (e) {
      // If JSON parsing fails, create error response
      response = { ok: false, error: `Server vrátil HTTP ${r.status} (nie JSON odpoveď)` };
    }

    if (response.ok) {
      resultEl.textContent = response.message || 'Testovací e-mail bol odoslaný!';
      resultEl.className = 'test-email-result success';
    } else {
      let errorMsg = response.error || `Chyba servera (HTTP ${r.status})`;

      resultEl.textContent = errorMsg;
      resultEl.className = 'test-email-result error';
    }
  } catch (err) {
    console.error('Test email error:', err);
    resultEl.textContent = 'Chyba pri komunikácii so serverom: ' + err.message;
    resultEl.className = 'test-email-result error';
  } finally {
    btn.disabled = false;
  }
};

// --------- Log Viewer ---------
let logViewerState = {
  logs: [],
  total: 0,
  offset: 0,
  limit: 100,
  level: '',
  search: '',
  dateFrom: null,
  dateTo: null,
  quickRangeHours: 6,
  customMode: false,
  selectedDays: [],
  calendarMonth: null, // Date object for current calendar view
  debounceTimer: null
};

function openLogViewer() {
  const overlay = $('#logViewerOverlay');
  if (overlay) {
    overlay.classList.remove('hidden');
    loadLogStats();
    // Default: last 6 hours
    setQuickRange(6);
    loadDeleteMonths();
  }
}

function closeLogViewer() {
  const overlay = $('#logViewerOverlay');
  if (overlay) {
    overlay.classList.add('hidden');
  }
}

async function loadLogStats() {
  const days = $('#statsDays')?.value || 7;

  try {
    const response = await apiGet(`${API}?action=get_log_stats&days=${days}`);

    if (response && response.ok && response.data) {
      const d = response.data;

      $('#statFetchRuns').textContent = d.fetch_runs || 0;
      $('#statGoogleCalls').textContent = d.google_api_calls || 0;
      $('#statGoogleDetail').textContent = `${d.google_api_success || 0} OK / ${d.google_api_failed || 0} chýb`;
      $('#statPositions').textContent = d.positions_inserted || 0;
      $('#statCacheHits').textContent = d.cache_hits || 0;
      $('#statIBeaconHits').textContent = d.ibeacon_hits || 0;
      $('#statPerimeterAlerts').textContent = d.perimeter_alerts || 0;
      $('#statErrorCount').textContent = d.counts?.error || 0;
      $('#statInfoCount').textContent = d.counts?.info || 0;
      $('#statDebugCount').textContent = d.counts?.debug || 0;
    }
  } catch (err) {
    console.error('Failed to load log stats:', err);
  }
}

function setQuickRange(hours) {
  logViewerState.quickRangeHours = hours;
  logViewerState.customMode = false;
  logViewerState.offset = 0;

  // Update active button
  document.querySelectorAll('.log-quick-range').forEach(btn => btn.classList.remove('active'));
  const activeBtn = document.querySelector(`.log-quick-range[data-hours="${hours}"]`);
  if (activeBtn) activeBtn.classList.add('active');

  // Hide custom range
  const customPanel = $('#logCustomDateRange');
  if (customPanel) customPanel.classList.add('hidden');

  // Calculate date range
  const now = new Date();
  const from = new Date(now.getTime() - hours * 60 * 60 * 1000);
  logViewerState.dateFrom = formatLocalDateTime(from);
  logViewerState.dateTo = null; // null = up to now

  // Update info
  const rangeInfo = $('#logDateRangeInfo');
  if (rangeInfo) {
    rangeInfo.textContent = `Rozsah: posledných ${hours} hodín`;
  }

  loadLogs();
}

function openCustomDateRange() {
  logViewerState.customMode = true;
  logViewerState.selectedDays = [];

  // Update active button
  document.querySelectorAll('.log-quick-range').forEach(btn => btn.classList.remove('active'));
  const customBtn = document.querySelector('.log-quick-range[data-custom]');
  if (customBtn) customBtn.classList.add('active');

  // Show custom range panel
  const customPanel = $('#logCustomDateRange');
  if (customPanel) customPanel.classList.remove('hidden');

  // Set default dates to today
  const today = new Date();
  const todayStr = formatDateISO(today);
  const fromEl = $('#logDateFrom');
  const toEl = $('#logDateTo');
  if (fromEl) fromEl.value = todayStr;
  if (toEl) toEl.value = todayStr;
  if ($('#logTimeFrom')) $('#logTimeFrom').value = '00:00';
  if ($('#logTimeTo')) $('#logTimeTo').value = '23:59';

  // Init calendar to current month
  logViewerState.calendarMonth = new Date(today.getFullYear(), today.getMonth(), 1);
  renderLogCalendar();
}

function formatLocalDateTime(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  const h = String(date.getHours()).padStart(2, '0');
  const min = String(date.getMinutes()).padStart(2, '0');
  const s = String(date.getSeconds()).padStart(2, '0');
  return `${y}-${m}-${d}T${h}:${min}:${s}`;
}

function formatDateISO(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function renderLogCalendar() {
  const grid = $('#logCalGrid') || $('#logCalDays');
  const label = $('#logCalMonthLabel');
  if (!grid || !label) return;

  // Initialize calendarMonth if not set
  if (!logViewerState.calendarMonth) {
    const now = new Date();
    logViewerState.calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
  }

  const monthDate = logViewerState.calendarMonth;
  const year = monthDate.getFullYear();
  const month = monthDate.getMonth();

  const monthNames = ['Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún',
    'Júl', 'August', 'September', 'Október', 'November', 'December'];
  label.textContent = `${monthNames[month]} ${year}`;

  const firstDay = new Date(year, month, 1);
  let startDow = firstDay.getDay(); // 0=Sun
  startDow = startDow === 0 ? 6 : startDow - 1; // Convert to Mon=0

  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const today = formatDateISO(new Date());

  // Day headers
  let html = ['Po','Ut','St','Št','Pi','So','Ne'].map(d =>
    `<div class="log-cal-day-header">${d}</div>`
  ).join('');

  // Empty cells before first day
  for (let i = 0; i < startDow; i++) {
    html += '<div class="log-cal-day log-cal-day-empty"></div>';
  }

  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const isSelected = logViewerState.selectedDays.includes(dateStr);
    const isToday = dateStr === today;
    const isFuture = dateStr > today;

    let classes = 'log-cal-day';
    if (isSelected) classes += ' log-cal-day-selected';
    if (isToday) classes += ' log-cal-day-today';
    if (isFuture) classes += ' log-cal-day-disabled';

    html += `<div class="${classes}" data-date="${dateStr}">${d}</div>`;
  }

  grid.innerHTML = html;

  // Event delegation for day clicks
  grid.querySelectorAll('.log-cal-day[data-date]').forEach(el => {
    if (!el.classList.contains('log-cal-day-disabled') && !el.classList.contains('log-cal-day-empty')) {
      el.addEventListener('click', () => toggleLogCalDay(el.dataset.date));
    }
  });
}

function toggleLogCalDay(dateStr) {
  const idx = logViewerState.selectedDays.indexOf(dateStr);
  if (idx >= 0) {
    logViewerState.selectedDays.splice(idx, 1);
  } else {
    if (logViewerState.selectedDays.length >= 7) {
      alert('Maximálne 7 dní je možné vybrať.');
      return;
    }
    logViewerState.selectedDays.push(dateStr);
  }
  logViewerState.selectedDays.sort();

  // Auto-update date inputs based on selected days
  if (logViewerState.selectedDays.length > 0) {
    const first = logViewerState.selectedDays[0];
    const last = logViewerState.selectedDays[logViewerState.selectedDays.length - 1];
    if ($('#logDateFrom')) $('#logDateFrom').value = first;
    if ($('#logDateTo')) $('#logDateTo').value = last;
  }

  renderLogCalendar();
}

function logCalPrevMonth() {
  if (!logViewerState.calendarMonth) {
    const now = new Date();
    logViewerState.calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
  }
  const d = logViewerState.calendarMonth;
  logViewerState.calendarMonth = new Date(d.getFullYear(), d.getMonth() - 1, 1);
  renderLogCalendar();
}

function logCalNextMonth() {
  if (!logViewerState.calendarMonth) {
    const now = new Date();
    logViewerState.calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
  }
  const d = logViewerState.calendarMonth;
  logViewerState.calendarMonth = new Date(d.getFullYear(), d.getMonth() + 1, 1);
  renderLogCalendar();
}

function applyCustomDateRange() {
  const dateFrom = $('#logDateFrom')?.value;
  const dateTo = $('#logDateTo')?.value;
  const timeFrom = $('#logTimeFrom')?.value || '00:00';
  const timeTo = $('#logTimeTo')?.value || '23:59';

  if (!dateFrom || !dateTo) {
    alert('Prosím zvoľte dátumový rozsah.');
    return;
  }

  // Validate max 7 days
  const from = new Date(dateFrom);
  const to = new Date(dateTo);
  const diffDays = Math.ceil((to - from) / (1000 * 60 * 60 * 24)) + 1;
  if (diffDays > 7) {
    alert('Maximálny rozsah je 7 dní.');
    return;
  }
  if (diffDays < 1) {
    alert('Dátum "Od" musí byť pred alebo rovný dátumu "Do".');
    return;
  }

  logViewerState.dateFrom = `${dateFrom}T${timeFrom}:00`;
  logViewerState.dateTo = `${dateTo}T${timeTo}:59`;
  logViewerState.offset = 0;

  // Update info
  const rangeInfo = $('#logDateRangeInfo');
  if (rangeInfo) {
    rangeInfo.textContent = `Rozsah: ${dateFrom} ${timeFrom} — ${dateTo} ${timeTo}`;
  }

  loadLogs();
}

async function loadLogs() {
  const container = $('#logContainer');
  if (!container) return;

  container.innerHTML = '<div class="log-loading">Načítavam logy...</div>';

  try {
    const params = new URLSearchParams({
      limit: logViewerState.limit,
      offset: logViewerState.offset,
      order: 'desc'
    });

    if (logViewerState.dateFrom) {
      params.append('date_from', logViewerState.dateFrom);
    }
    if (logViewerState.dateTo) {
      params.append('date_to', logViewerState.dateTo);
    }
    if (logViewerState.level) {
      params.append('level', logViewerState.level);
    }
    if (logViewerState.search) {
      params.append('search', logViewerState.search);
    }

    const response = await apiGet(`${API}?action=get_logs&${params.toString()}`);

    if (response && response.ok && response.data) {
      const d = response.data;
      logViewerState.logs = d.logs || [];
      logViewerState.total = d.total || 0;

      // Debug info - output to console for troubleshooting
      if (d.debug) {
        console.log('[Log Viewer Debug]', {
          file_path: d.file_path,
          file_size: d.file_size,
          date_from: d.date_from,
          date_to: d.date_to,
          total_lines_read: d.debug.total_lines_read,
          lines_matched: d.debug.lines_matched,
          lines_after_filter: d.debug.lines_after_filter,
          newest_timestamp: d.debug.newest_timestamp,
          oldest_timestamp: d.debug.oldest_timestamp
        });
      }

      // Update file info
      const fileInfo = $('#logFileInfo');
      if (fileInfo) {
        const sizeKB = d.file_size ? (d.file_size / 1024).toFixed(1) : 0;
        fileInfo.textContent = `Súbor: ${d.file || 'N/A'} (${sizeKB} KB)`;
      }

      const countInfo = $('#logCountInfo');
      if (countInfo) {
        countInfo.textContent = `Záznamov: ${d.total} (zobrazených: ${d.logs.length})`;
      }

      renderLogs();
      updatePagination();
    } else {
      container.innerHTML = '<div class="log-empty">Nepodarilo sa načítať logy</div>';
    }
  } catch (err) {
    console.error('Failed to load logs:', err);
    container.innerHTML = `<div class="log-empty">Chyba: ${err.message}</div>`;
  }
}

function renderLogs() {
  const container = $('#logContainer');
  if (!container) return;

  if (logViewerState.logs.length === 0) {
    container.innerHTML = '<div class="log-empty">Žiadne logy na zobrazenie</div>';
    return;
  }

  const html = logViewerState.logs.map(log => {
    const levelClass = `log-level-${log.level}`;
    const time = log.timestamp.split('T')[1]?.split('+')[0] || log.timestamp;
    const date = log.timestamp.split('T')[0] || '';

    return `
      <div class="log-entry ${levelClass}">
        <span class="log-time" title="${log.timestamp}">${date} ${time}</span>
        <span class="log-level">${log.level.toUpperCase()}</span>
        <span class="log-message">${escapeHtml(log.message)}</span>
      </div>
    `;
  }).join('');

  container.innerHTML = html;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function updatePagination() {
  const pageInfo = $('#logPageInfo');
  const prevBtn = $('#logPrevPage');
  const nextBtn = $('#logNextPage');

  const currentPage = Math.floor(logViewerState.offset / logViewerState.limit) + 1;
  const totalPages = Math.ceil(logViewerState.total / logViewerState.limit) || 1;

  if (pageInfo) {
    pageInfo.textContent = `Strana ${currentPage} z ${totalPages}`;
  }

  if (prevBtn) {
    prevBtn.disabled = logViewerState.offset === 0;
  }

  if (nextBtn) {
    nextBtn.disabled = logViewerState.offset + logViewerState.limit >= logViewerState.total;
  }
}

function goToPrevPage() {
  if (logViewerState.offset > 0) {
    logViewerState.offset = Math.max(0, logViewerState.offset - logViewerState.limit);
    loadLogs();
  }
}

function goToNextPage() {
  if (logViewerState.offset + logViewerState.limit < logViewerState.total) {
    logViewerState.offset += logViewerState.limit;
    loadLogs();
  }
}

function filterLogs() {
  logViewerState.level = $('#logLevelFilter')?.value || '';
  logViewerState.offset = 0;
  loadLogs();
}

function searchLogs() {
  // Debounce search
  if (logViewerState.debounceTimer) {
    clearTimeout(logViewerState.debounceTimer);
  }

  logViewerState.debounceTimer = setTimeout(() => {
    logViewerState.search = $('#logSearchFilter')?.value || '';
    logViewerState.offset = 0;
    loadLogs();
  }, 300);
}

async function clearLogs() {
  if (!confirm('Naozaj chcete vymazať všetky logy?')) {
    return;
  }

  try {
    const response = await apiPost('clear_logs', {});

    if (response && response.ok) {
      alert('Logy boli vymazané.');
      loadLogs();
      loadLogStats();
    } else {
      alert(`Chyba: ${response.error || 'Neznáma chyba'}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
  }
}

async function loadDeleteMonths() {
  const select = $('#logDeleteBeforeMonth');
  const btn = $('#logDeleteOldBtn');
  if (!select) return;

  try {
    const response = await apiGet(`${API}?action=get_log_date_range`);
    if (response && response.ok && response.data && response.data.months) {
      const months = response.data.months;
      const monthKeys = Object.keys(months);

      if (monthKeys.length === 0) {
        select.innerHTML = '<option value="">Žiadne logy</option>';
        if (btn) btn.disabled = true;
        return;
      }

      const monthNames = ['Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún',
        'Júl', 'August', 'September', 'Október', 'November', 'December'];

      let html = '<option value="">Vyberte mesiac...</option>';
      for (const key of monthKeys) {
        const [y, m] = key.split('-');
        const name = `${monthNames[parseInt(m, 10) - 1]} ${y}`;
        html += `<option value="${key}">${name} (${months[key]} záznamov)</option>`;
      }
      select.innerHTML = html;

      select.addEventListener('change', () => {
        if (btn) btn.disabled = !select.value;
      });
    }
  } catch (err) {
    console.error('Failed to load log date range:', err);
    select.innerHTML = '<option value="">Chyba načítania</option>';
  }
}

async function deleteOldLogs() {
  const select = $('#logDeleteBeforeMonth');
  const beforeMonth = select?.value;
  if (!beforeMonth) return;

  const [y, m] = beforeMonth.split('-');
  const monthNames = ['Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún',
    'Júl', 'August', 'September', 'Október', 'November', 'December'];
  const monthName = `${monthNames[parseInt(m, 10) - 1]} ${y}`;

  if (!confirm(`Naozaj chcete zmazať všetky logy za mesiac ${monthName} a staršie?`)) {
    return;
  }

  const infoEl = $('#logDeleteInfo');

  try {
    if (infoEl) {
      infoEl.classList.remove('hidden');
      infoEl.textContent = 'Mazanie logov...';
      infoEl.className = 'log-delete-info';
    }

    const response = await apiPost('delete_old_logs', { before_month: beforeMonth });

    if (response && response.ok) {
      if (infoEl) {
        infoEl.textContent = `Zmazaných ${response.deleted || 0} záznamov. Zostáva ${response.remaining || 0}.`;
        infoEl.className = 'log-delete-info log-delete-success';
      }
      loadLogs();
      loadLogStats();
      loadDeleteMonths();
    } else {
      if (infoEl) {
        infoEl.textContent = `Chyba: ${response.error || 'Neznáma chyba'}`;
        infoEl.className = 'log-delete-info log-delete-error';
      }
    }
  } catch (err) {
    if (infoEl) {
      infoEl.textContent = `Chyba: ${err.message}`;
      infoEl.className = 'log-delete-info log-delete-error';
    }
  }
}

function initLogViewer() {
  // Close button
  const closeBtn = $('#logViewerClose');
  if (closeBtn) {
    closeBtn.addEventListener('click', closeLogViewer);
  }

  // Background click to close
  const overlay = $('#logViewerOverlay');
  if (overlay) {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        closeLogViewer();
      }
    });
  }

  // Open log viewer button (from settings)
  const openBtn = $('#openLogViewer');
  if (openBtn) {
    openBtn.addEventListener('click', () => {
      closeSettingsOverlay();
      openLogViewer();
    });
  }

  // Stats refresh
  const refreshStatsBtn = $('#refreshStats');
  if (refreshStatsBtn) {
    refreshStatsBtn.addEventListener('click', loadLogStats);
  }

  // Stats period selector
  const statsDays = $('#statsDays');
  if (statsDays) {
    statsDays.addEventListener('change', loadLogStats);
  }

  // Quick range buttons
  document.querySelectorAll('.log-quick-range[data-hours]').forEach(btn => {
    btn.addEventListener('click', () => setQuickRange(parseInt(btn.dataset.hours, 10)));
  });

  // Custom range button
  const customRangeBtn = document.querySelector('.log-quick-range[data-custom]');
  if (customRangeBtn) {
    customRangeBtn.addEventListener('click', openCustomDateRange);
  }

  // Calendar navigation
  const calPrev = $('#logCalPrev');
  if (calPrev) calPrev.addEventListener('click', logCalPrevMonth);
  const calNext = $('#logCalNext');
  if (calNext) calNext.addEventListener('click', logCalNextMonth);

  // Apply date range
  const applyBtn = $('#logApplyDateRange');
  if (applyBtn) applyBtn.addEventListener('click', applyCustomDateRange);

  // Log refresh
  const refreshLogsBtn = $('#refreshLogs');
  if (refreshLogsBtn) {
    refreshLogsBtn.addEventListener('click', loadLogs);
  }

  // Clear logs
  const clearLogsBtn = $('#clearLogs');
  if (clearLogsBtn) {
    clearLogsBtn.addEventListener('click', clearLogs);
  }

  // Level filter
  const levelFilter = $('#logLevelFilter');
  if (levelFilter) {
    levelFilter.addEventListener('change', filterLogs);
  }

  // Search filter
  const searchFilter = $('#logSearchFilter');
  if (searchFilter) {
    searchFilter.addEventListener('input', searchLogs);
  }

  // Pagination
  const prevPageBtn = $('#logPrevPage');
  if (prevPageBtn) {
    prevPageBtn.addEventListener('click', goToPrevPage);
  }

  const nextPageBtn = $('#logNextPage');
  if (nextPageBtn) {
    nextPageBtn.addEventListener('click', goToNextPage);
  }

  // Bulk delete
  const deleteOldBtn = $('#logDeleteOldBtn');
  if (deleteOldBtn) {
    deleteOldBtn.addEventListener('click', deleteOldLogs);
  }
}

// ========== MULTI-DEVICE UI ==========

function initDeviceUI() {
  const dropdownContainer = $('#deviceDropdownContainer');
  const tabsContainer = $('#deviceTabsContainer');

  if (devices.length <= 1) {
    // Single device mode - hide multi-device controls
    if (dropdownContainer) dropdownContainer.classList.add('hidden');
    if (tabsContainer) tabsContainer.classList.add('hidden');
    // Set single device as active for table
    if (devices.length === 1) {
      activeTableDeviceId = devices[0].id;
      deviceVisibility[devices[0].id] = true;
    }
    return;
  }

  // Multi-device: show dropdown in header and tabs above table
  if (dropdownContainer) dropdownContainer.classList.remove('hidden');
  if (tabsContainer) tabsContainer.classList.remove('hidden');

  // Set default active table device
  if (!activeTableDeviceId && devices.length > 0) {
    activeTableDeviceId = devices[0].id;
  }

  renderDeviceDropdown();
  renderDeviceTabs();
  initDeviceDropdownHandlers();
}

function renderDeviceDropdown() {
  const menu = $('#deviceDropdownMenu');
  const label = $('#deviceDropdownLabel');
  if (!menu) return;

  const visibleCount = devices.filter(d => deviceVisibility[d.id] !== false).length;
  if (label) label.textContent = visibleCount;

  menu.innerHTML = devices.map(d => {
    const checked = deviceVisibility[d.id] !== false;
    return `
      <label class="device-dropdown-item" data-device-id="${d.id}">
        <input type="checkbox" class="device-dd-toggle" data-device-id="${d.id}" ${checked ? 'checked' : ''}>
        <span class="device-dd-dot" style="background:${d.color}"></span>
        <span class="device-dd-name">${d.name}</span>
      </label>
    `;
  }).join('');

  // Checkbox handlers
  menu.querySelectorAll('.device-dd-toggle').forEach(cb => {
    cb.addEventListener('change', (e) => {
      e.stopPropagation();
      const did = parseInt(e.target.dataset.deviceId);
      // Ensure at least one device stays visible
      const othersVisible = devices.some(d => d.id !== did && deviceVisibility[d.id] !== false);
      if (!e.target.checked && !othersVisible) {
        e.target.checked = true;
        return;
      }
      deviceVisibility[did] = e.target.checked;
      updateDeviceLayerVisibility();
      renderDeviceDropdown();
      renderDeviceTabs();
      // If active table device was hidden, switch to first visible
      if (!deviceVisibility[activeTableDeviceId]) {
        const firstVisible = devices.find(d => deviceVisibility[d.id] !== false);
        if (firstVisible) {
          activeTableDeviceId = firstVisible.id;
          refilterTableForDevice();
        }
      }
    });
  });
}

let _dropdownHandlersInited = false;
let _dropdownAbort = null;

function initDeviceDropdownHandlers() {
  const btn = $('#deviceDropdownBtn');
  const menu = $('#deviceDropdownMenu');
  if (!btn || !menu) return;

  // Prevent duplicate handler registration
  if (_dropdownAbort) {
    _dropdownAbort.abort();
  }
  _dropdownAbort = new AbortController();
  const signal = _dropdownAbort.signal;

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    menu.classList.toggle('hidden');
  }, { signal });

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.device-dropdown-container')) {
      menu.classList.add('hidden');
    }
  }, { signal });
}

function renderDeviceTabs() {
  const container = $('#deviceTabs');
  if (!container) return;

  const visibleDevices = devices.filter(d => deviceVisibility[d.id] !== false);
  if (visibleDevices.length <= 1) {
    $('#deviceTabsContainer').classList.add('hidden');
    if (visibleDevices.length === 1) {
      activeTableDeviceId = visibleDevices[0].id;
    }
    return;
  }

  $('#deviceTabsContainer').classList.remove('hidden');

  container.innerHTML = visibleDevices.map(d => `
    <button class="device-tab ${d.id === activeTableDeviceId ? 'active' : ''}" data-device-id="${d.id}">
      <span class="device-tab-dot" style="background:${d.color}"></span>
      ${d.name}
    </button>
  `).join('');

  container.querySelectorAll('.device-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      activeTableDeviceId = parseInt(tab.dataset.deviceId);
      renderDeviceTabs();
      refilterTableForDevice();
    });
  });
}

function refilterTableForDevice() {
  // Re-render table from cached data for the active table device
  if (window._lastHistoryData && window._lastHistoryDate) {
    buildTableFromData(window._lastHistoryData, window._lastHistoryDate);
  }
}

function updateDeviceLayerVisibility() {
  Object.keys(deviceLayers).forEach(did => {
    const layer = deviceLayers[did];
    const visible = deviceVisibility[parseInt(did)] !== false;
    if (visible && !map.hasLayer(layer)) {
      map.addLayer(layer);
    } else if (!visible && map.hasLayer(layer)) {
      map.removeLayer(layer);
    }
  });

  // Rebuild cluster markers based on visible devices
  if (window._lastPositionsForClustering) {
    // Remove old clusters
    if (window._clusterMarkers) {
      window._clusterMarkers.forEach(m => m.remove());
      window._clusterMarkers = [];
    }
    // Re-render with only visible devices
    const visibleLastPos = window._lastPositionsForClustering.filter(
      lp => deviceVisibility[parseInt(lp.did)] !== false
    );
    renderClusterOverlays(visibleLastPos);
  }
}

// ========== DEVICE MANAGEMENT (Settings) ==========

function openDeviceManagement() {
  const overlay = $('#deviceManagementOverlay');
  if (overlay) {
    overlay.classList.remove('hidden');
    loadDeviceManagementList();

    // Close on background click (consistent with other overlays)
    overlay.onclick = (e) => {
      if (e.target === overlay) closeDeviceManagement();
    };
  }
}

function closeDeviceManagement() {
  const overlay = $('#deviceManagementOverlay');
  if (overlay) overlay.classList.add('hidden');
}

async function loadDeviceManagementList() {
  await loadDevices();
  const container = $('#deviceList');
  if (!container) return;

  if (devices.length === 0) {
    container.innerHTML = '<p class="empty-state">Žiadne zariadenia</p>';
    return;
  }

  container.innerHTML = devices.map(d => {
    const batteryLabel = d.status ? (d.status.battery_state === 1 ? 'Dobrá' : d.status.battery_state === 0 ? 'Slabá' : '—') : '—';
    const batteryClass = d.status ? (d.status.battery_state === 1 ? 'good' : d.status.battery_state === 0 ? 'low' : '') : '';
    const lastOnline = d.status && d.status.latest_message_time ? formatTimeAgo(d.status.latest_message_time) : '—';
    const onlineClass = d.status ? (d.status.online_status === 1 ? 'online' : 'offline') : '';
    return `
    <div class="device-card" data-id="${d.id}">
      <div class="device-card-header">
        <span class="device-color-indicator" style="background:${d.color}"></span>
        <span class="device-card-name">${d.name}</span>
        <span class="device-card-eui">${d.device_eui}</span>
        <span class="device-card-status ${d.is_active ? 'active' : 'inactive'}">${d.is_active ? 'Aktívne' : 'Neaktívne'}</span>
      </div>
      <div class="device-card-info">
        ${d.last_position ? `<span>Posledná poloha: ${toTime(d.last_position.timestamp)}</span>` : '<span>Bez dát</span>'}
        <span class="device-battery ${batteryClass}">Batéria: ${batteryLabel}</span>
        <span class="device-online ${onlineClass}">Online: ${lastOnline}</span>
      </div>
      <div class="device-card-actions">
        <button class="btn-sm btn-edit" onclick="editDevice(${d.id})">Upraviť</button>
        <button class="btn-sm btn-buzzer" onclick="sendBuzzerCommand(${d.id}, '${d.name}', event)" title="Zapnúť pípanie na zariadení">Pípanie</button>
        <button class="btn-sm btn-data" onclick="openDataBrowser(${d.id}, '${d.name}')" title="Prehliadač dát zariadenia">Dáta</button>
        <button class="btn-sm btn-toggle" onclick="toggleDevice(${d.id}, ${d.is_active ? 0 : 1})">${d.is_active ? 'Deaktivovať' : 'Aktivovať'}</button>
        <button class="btn-sm btn-danger" onclick="deleteDevice(${d.id}, '${d.name}')">Zmazať</button>
      </div>
    </div>
  `;}).join('');
}

function openDeviceForm(device = null) {
  const form = $('#deviceForm');
  if (!form) return;

  form.querySelector('#deviceFormTitle').textContent = device ? 'Upraviť zariadenie' : 'Nové zariadenie';
  form.querySelector('#deviceId').value = device ? device.id : '';
  form.querySelector('#deviceName').value = device ? device.name : '';
  form.querySelector('#deviceEui').value = device ? device.device_eui : '';
  form.querySelector('#deviceColor').value = device ? device.color : '#3388ff';
  form.querySelector('#deviceActive').checked = device ? device.is_active : true;
  form.querySelector('#deviceNotifEnabled').checked = device ? device.notifications_enabled : false;
  form.querySelector('#deviceNotifEmail').value = device ? (device.notification_email || '') : '';

  form.classList.remove('hidden');
}

function closeDeviceForm() {
  const form = $('#deviceForm');
  if (form) form.classList.add('hidden');
}

async function saveDevice() {
  const form = $('#deviceForm');
  if (!form) return;

  const id = form.querySelector('#deviceId').value;
  const payload = {
    name: form.querySelector('#deviceName').value.trim(),
    device_eui: form.querySelector('#deviceEui').value.trim(),
    color: form.querySelector('#deviceColor').value,
    is_active: form.querySelector('#deviceActive').checked,
    notifications_enabled: form.querySelector('#deviceNotifEnabled').checked,
    notification_email: form.querySelector('#deviceNotifEmail').value.trim()
  };

  if (id) payload.id = parseInt(id);

  if (!payload.name || !payload.device_eui) {
    alert('Názov a Device EUI sú povinné');
    return;
  }

  try {
    const res = await apiPost('save_device', payload);
    if (res.ok) {
      closeDeviceForm();
      await loadDeviceManagementList();
      await loadDevices();
      initDeviceUI();
    } else {
      alert(res.error || 'Chyba pri ukladaní');
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function editDevice(id) {
  const dev = getDeviceById(id);
  if (dev) openDeviceForm(dev);
}

async function toggleDevice(id, active) {
  try {
    await apiPost('toggle_device', { id, is_active: active });
    await loadDeviceManagementList();
    await loadDevices();
    initDeviceUI();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function deleteDevice(id, name) {
  if (!confirm(`Naozaj chcete zmazať zariadenie "${name}" a všetky jeho dáta?`)) return;
  try {
    const res = await apiGet(`${API}?action=delete_device&id=${id}`);
    if (res.ok) {
      await loadDeviceManagementList();
      await loadDevices();
      if (activeDeviceId === id) {
        activeDeviceId = devices.length > 0 ? devices[0].id : null;
      }
      initDeviceUI();
      refresh();
    } else {
      alert(res.error || 'Chyba pri mazaní');
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

// ========== BUZZER COMMAND ==========

async function sendBuzzerCommand(deviceId, deviceName, evt) {
  const intervalNote = 'Zariadenie začne pípať po najbližšom spojení so sieťou Helium (zvyčajne do 5 minút).';
  const disclaimer = 'Pípanie funguje len ak má zariadenie pokrytie konektivitou Helium.';

  if (!confirm(`Zapnúť pípanie na zariadení "${deviceName}"?\n\n${intervalNote}\n\n${disclaimer}`)) return;

  const btn = evt ? evt.target : null;
  try {
    if (btn) { btn.disabled = true; btn.textContent = 'Odosielam...'; }

    const res = await apiPost('send_device_command', { device_id: deviceId, command: 'buzzer_on' });

    if (res.ok) {
      if (btn) {
        btn.textContent = 'Odoslané';
        btn.classList.add('btn-success');
        setTimeout(() => {
          btn.disabled = false;
          btn.textContent = 'Pípanie';
          btn.classList.remove('btn-success');
        }, 5000);
      }
      alert(`Príkaz na pípanie odoslaný zariadeniu "${deviceName}".\n\n${intervalNote}\n\nPre vypnutie pípania použite tlačidlo znova.`);
    } else {
      if (btn) { btn.disabled = false; btn.textContent = 'Pípanie'; }
      alert(res.error || 'Chyba pri odosielaní príkazu');
    }
  } catch (e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Pípanie'; }
    alert('Chyba: ' + e.message);
  }
}

// ========== DATA BROWSER ==========

let dataBrowserDeviceId = null;
let dataBrowserDeviceName = '';
let dataBrowserPage = 1;
const dataBrowserPerPage = 50;

function openDataBrowser(deviceId, deviceName) {
  dataBrowserDeviceId = deviceId;
  dataBrowserDeviceName = deviceName;
  dataBrowserPage = 1;

  const overlay = $('#dataBrowserOverlay');
  if (!overlay) return;

  overlay.querySelector('#dataBrowserTitle').textContent = `Dáta: ${deviceName}`;
  overlay.querySelector('#dataBrowserDate').value = fmt(new Date());
  overlay.querySelector('#dataBrowserSourceFilter').value = 'all';
  overlay.classList.remove('hidden');

  overlay.onclick = (e) => {
    if (e.target === overlay) closeDataBrowser();
  };

  loadDataBrowserRecords();
}

function closeDataBrowser() {
  const overlay = $('#dataBrowserOverlay');
  if (overlay) overlay.classList.add('hidden');
}

async function triggerRefetchFromDataBrowser() {
  const date = $('#dataBrowserDate')?.value;
  if (!date) return;
  if (!confirm(`Spustiť refetch dát zo SenseCraft pre ${date}?`)) return;

  try {
    const res = await apiPost('refetch_day', { date });
    if (res.ok) {
      alert(`Refetch spustený pre ${date}. Počkajte 1-2 minúty a potom obnovte.`);
      // Auto-reload after 30 seconds
      setTimeout(() => loadDataBrowserRecords(), 30000);
    } else {
      alert('Chyba: ' + (res.error || 'Nepodarilo sa spustiť refetch'));
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function loadDataBrowserRecords() {
  const tbody = $('#dataBrowserBody');
  if (!tbody) return;

  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;">Načítavam...</td></tr>';

  const date = $('#dataBrowserDate')?.value || '';
  const sourceFilter = $('#dataBrowserSourceFilter')?.value || 'all';

  try {
    const params = new URLSearchParams({
      action: 'get_raw_records',
      device_id: dataBrowserDeviceId,
      page: dataBrowserPage,
      per_page: dataBrowserPerPage
    });
    if (date) params.append('date', date);
    if (sourceFilter && sourceFilter !== 'all') params.append('source_filter', sourceFilter);

    const result = await apiGet(`${API}?${params}`);

    if (!result.ok || (!result.data && !result.records)) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#ef4444;">Chyba načítania</td></tr>';
      return;
    }

    const records = result.records || result.data || [];
    const pagination = result.pagination || {};

    if (records.length === 0) {
      // Show diagnostic info when no records found
      let diagHtml = '<tr><td colspan="7" style="text-align:center;color:var(--muted-text);padding:20px;">';
      diagHtml += '<div style="font-size:1.1em;">Žiadne záznamy</div>';
      try {
        const status = await apiGet(`${API}?action=fetch_status&device_id=${dataBrowserDeviceId}`);
        if (status && status.ok) {
          diagHtml += '<div style="margin-top:12px;font-size:0.85em;opacity:0.7;line-height:1.6;">';
          diagHtml += `Posledný záznam: ${status.last_record || 'žiadny'}<br>`;
          diagHtml += `Záznamy za 24h: ${status.records_last_24h} (+ ${status.wifi_scans_last_24h} WiFi skenov)<br>`;
          diagHtml += `Posledný fetch: ${status.last_fetch_run || 'neznámy'}<br>`;
          diagHtml += `Čas servera: ${status.server_time}`;
          diagHtml += '</div>';
        }
      } catch (e) { /* ignore diagnostic errors */ }
      diagHtml += `<div style="margin-top:14px;"><button class="btn-sm btn-edit" onclick="triggerRefetchFromDataBrowser()">Znovu načítať dáta zo SenseCraft</button></div>`;
      diagHtml += '</td></tr>';
      tbody.innerHTML = diagHtml;
      updateDataBrowserPagination(pagination);
      return;
    }

    tbody.innerHTML = records.map(r => {
      const time = r.timestamp ? new Date(r.timestamp).toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—';
      const lat = r.latitude ? r.latitude.toFixed(5) : '—';
      const lng = r.longitude ? r.longitude.toFixed(5) : '—';
      const sourceLabel = formatSource(r.source, r.resolved);
      const isWifiScan = r.record_type === 'wifi_scan';
      const isUnresolved = isWifiScan && !r.resolved;
      const rowClass = isUnresolved ? 'unresolved-row' : '';

      // MAC addresses info
      let macInfo = '';
      const macCount = r.mac_count || (r.mac_details ? r.mac_details.length : 0);
      if (r.mac_details && r.mac_details.length > 0) {
        const withLocation = r.mac_details.filter(m => m.has_location).length;
        const recordRef = isWifiScan ? `'ws_${r.id}'` : `${r.id}`;
        macInfo = `<span class="mac-badge" onclick="showRecordMacs(${recordRef})" title="Kliknutím zobrazíte detaily">${macCount} MAC${macCount > 1 ? 's' : ''} (${withLocation} s polohou)</span>`;
      } else if (r.source === 'gnss') {
        macInfo = '<span class="source-badge gnss">GNSS</span>';
      } else if (r.source === 'ibeacon') {
        macInfo = '<span class="source-badge ibeacon">iBeacon</span>';
      }

      // Actions - only show Google buttons if API key is configured
      let actions = '';
      if (hasGoogleApi) {
        if (isWifiScan && !r.resolved) {
          actions = `<button class="btn-sm btn-edit" onclick="retryGoogleForWifiScan(${r.id})" title="Skúsiť Google API pre tento sken">Google</button>`;
        } else if (r.raw_wifi_macs || isWifiScan) {
          const retryId = isWifiScan ? r.id : r.id;
          const retryFn = isWifiScan ? 'retryGoogleForWifiScan' : 'retryGoogleForRecord';
          actions = `<button class="btn-sm btn-edit" onclick="${retryFn}(${retryId})" title="Zavolať Google API">Google</button>`;
        }
      }

      return `<tr data-record-id="${r.id}" data-record-type="${r.record_type || 'tracker'}" class="${rowClass}">
        <td>${time}</td>
        <td>${lat}</td>
        <td>${lng}</td>
        <td>${sourceLabel}</td>
        <td>${macInfo}</td>
        <td>${r.primary_mac || '—'}</td>
        <td>${actions}</td>
      </tr>`;
    }).join('');

    updateDataBrowserPagination(pagination);
  } catch (err) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#ef4444;">Chyba: ' + err.message + '</td></tr>';
  }
}

function formatSource(source, resolved) {
  if (!source && resolved === false) {
    return '<span class="source-badge unresolved">Nerozpoznané</span>';
  }
  const map = {
    'gnss': '<span class="source-badge gnss">GNSS</span>',
    'wifi-google': '<span class="source-badge wifi">WiFi Google</span>',
    'wifi-cache': '<span class="source-badge wifi-cache">WiFi Cache</span>',
    'ibeacon': '<span class="source-badge ibeacon">iBeacon</span>'
  };
  return map[source] || `<span class="source-badge">${source || '—'}</span>`;
}

function updateDataBrowserPagination(pagination) {
  const info = $('#dataBrowserPaginationInfo');
  const prevBtn = $('#dataBrowserPrev');
  const nextBtn = $('#dataBrowserNext');

  if (info) {
    const totalCount = pagination.total_count || 0;
    const totalPages = pagination.total_pages || 1;
    info.textContent = `Strana ${dataBrowserPage} z ${totalPages} (${totalCount} záznamov)`;
  }

  if (prevBtn) prevBtn.disabled = dataBrowserPage <= 1;
  if (nextBtn) nextBtn.disabled = dataBrowserPage >= (pagination.total_pages || 1);
}

function dataBrowserPrevPage() {
  if (dataBrowserPage > 1) {
    dataBrowserPage--;
    loadDataBrowserRecords();
  }
}

function dataBrowserNextPage() {
  dataBrowserPage++;
  loadDataBrowserRecords();
}

function dataBrowserDateChanged() {
  dataBrowserPage = 1;
  loadDataBrowserRecords();
}

function dataBrowserFilterChanged() {
  dataBrowserPage = 1;
  loadDataBrowserRecords();
}

async function showRecordMacs(recordId) {
  // Handle wifi_scan records (prefixed with 'ws_')
  if (typeof recordId === 'string' && recordId.startsWith('ws_')) {
    const wsId = parseInt(recordId.substring(3));
    try {
      const params = new URLSearchParams({ action: 'get_wifi_scan_macs', id: wsId });
      const result = await apiGet(`${API}?${params}`);
      if (!result.ok) {
        alert('Chyba: ' + (result.error || 'Nepodarilo sa načítať MAC adresy'));
        return;
      }
      const macs = result.macs || [];
      let msg = `WiFi sken #${wsId} - ${macs.length} MAC adries:\n\n`;
      macs.forEach(m => {
        const rssiStr = m.rssi ? ` (${m.rssi} dBm)` : '';
        const status = m.has_location ? `[${m.lat.toFixed(4)}, ${m.lng.toFixed(4)}]` : (m.queried ? '[negatívny]' : '[neotestovaný]');
        msg += `${m.mac}${rssiStr} ${status}\n`;
      });
      alert(msg);
    } catch (e) {
      alert('Chyba: ' + e.message);
    }
    return;
  }

  try {
    const params = new URLSearchParams({ action: 'get_record_macs', id: recordId });
    const result = await apiGet(`${API}?${params}`);

    if (!result.ok) {
      alert('Chyba: ' + (result.error || 'Nepodarilo sa načítať MAC adresy'));
      return;
    }

    const data = result.data;
    let msg = `MAC adresy pre záznam #${recordId}:\n\n`;
    if (data.macs && data.macs.length > 0) {
      data.macs.forEach(m => {
        const status = m.has_coords ? `[${m.lat.toFixed(4)}, ${m.lng.toFixed(4)}]` : (m.negative ? '[negatívny]' : '[neotestovaný]');
        const queried = m.last_queried ? ` (${new Date(m.last_queried).toLocaleDateString('sk-SK')})` : '';
        msg += `${m.mac} ${status}${queried}\n`;
      });
    } else {
      msg += 'Žiadne MAC adresy';
    }
    alert(msg);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function retryGoogleForRecord(recordId) {
  if (!confirm('Zavolať Google Geolocation API pre tento záznam? Toto môže aktualizovať polohu.')) return;

  try {
    const res = await apiPost('test_google_api', { position_id: recordId });
    if (res.ok) {
      const d = res.data || res;
      let msg = 'Google API odpoveď:\n';
      if (d.google_lat !== undefined) {
        msg += `Poloha: ${d.google_lat.toFixed(5)}, ${d.google_lng.toFixed(5)}\n`;
        msg += `Presnosť: ${d.google_accuracy || '—'} m\n`;
        if (d.cache_lat !== undefined) {
          msg += `\nPôvodná poloha (cache): ${d.cache_lat.toFixed(5)}, ${d.cache_lng.toFixed(5)}\n`;
          msg += `Rozdiel: ${d.distance_meters?.toFixed(0) || '—'} m`;
        }
      } else {
        msg += 'Žiadna poloha vrátená';
      }
      alert(msg);
      loadDataBrowserRecords();
    } else {
      alert(res.error || 'Chyba pri volaní Google API');
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function retryGoogleForWifiScan(scanId) {
  if (!confirm('Zavolať Google Geolocation API pre tento WiFi sken?')) return;

  try {
    const res = await apiPost('retry_google_wifi_scan', { scan_id: scanId });
    if (res.ok) {
      let msg = 'Google API odpoveď:\n';
      if (res.latitude !== undefined && res.latitude !== null) {
        msg += `Poloha: ${res.latitude.toFixed(5)}, ${res.longitude.toFixed(5)}\n`;
        msg += `Presnosť: ${res.accuracy || '—'} m\n`;
        msg += `Aktualizovaných MAC: ${res.macs_updated || 0}`;
      } else {
        msg += 'Žiadna poloha vrátená - Google nerozpoznal tieto WiFi siete';
      }
      alert(msg);
      loadDataBrowserRecords();
    } else {
      alert(res.error || 'Chyba pri volaní Google API');
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

// ========== COMPARISON MODE ==========

function openComparisonPanel() {
  let panel = $('#comparisonPanel');
  if (!panel) {
    panel = document.createElement('div');
    panel.id = 'comparisonPanel';
    panel.className = 'comparison-panel';
    document.querySelector('.container').appendChild(panel);
  }

  const today = fmt(new Date());
  panel.innerHTML = `
    <div class="comparison-header">
      <h3>Porovnanie zariadení</h3>
      <button class="btn-close" onclick="closeComparisonPanel()">&times;</button>
    </div>
    <div class="comparison-controls">
      <div class="comparison-dates">
        <label>Od: <input type="date" id="compDateFrom" value="${comparisonDateFrom || today}"></label>
        <label>Do: <input type="date" id="compDateTo" value="${comparisonDateTo || today}"></label>
      </div>
      <div class="comparison-devices">
        ${devices.map(d => `
          <label class="comparison-device-toggle">
            <input type="checkbox" value="${d.id}" ${comparisonDeviceIds.includes(d.id) ? 'checked' : ''}>
            <span class="leg-dot" style="background:${d.color}"></span>
            ${d.name}
          </label>
        `).join('')}
      </div>
      <button class="btn-primary" onclick="runComparison()">Porovnať</button>
    </div>
  `;
  panel.classList.remove('hidden');
}

function closeComparisonPanel() {
  comparisonMode = false;
  deviceViewMode = 'all';
  const panel = $('#comparisonPanel');
  if (panel) panel.classList.add('hidden');
  refresh();
}

async function runComparison() {
  const fromInput = $('#compDateFrom');
  const toInput = $('#compDateTo');
  if (!fromInput || !toInput) return;

  comparisonDateFrom = fromInput.value;
  comparisonDateTo = toInput.value;
  comparisonDeviceIds = [];

  document.querySelectorAll('.comparison-device-toggle input:checked').forEach(cb => {
    comparisonDeviceIds.push(parseInt(cb.value));
  });

  if (comparisonDeviceIds.length < 1) {
    alert('Vyberte aspoň jedno zariadenie');
    return;
  }

  // Clear existing layers
  trackLayer.clearLayers();
  Object.values(deviceLayers).forEach(l => l.clearLayers());

  const allBounds = [];

  for (const did of comparisonDeviceIds) {
    const dev = getDeviceById(did);
    const color = dev ? dev.color : '#3388ff';
    const name = dev ? dev.name : 'Zariadenie ' + did;

    try {
      const data = await apiGet(`${API}?action=get_history_range&since=${comparisonDateFrom}T00:00:00&until=${comparisonDateTo}T23:59:59&device_id=${did}`);
      if (!Array.isArray(data) || data.length === 0) continue;

      const points = data.map(p => [p.latitude, p.longitude]);
      allBounds.push(...points);

      const polyline = L.polyline(points, { color, weight: 3, opacity: 0.8 });
      polyline.bindTooltip(name, { sticky: true });
      polyline.addTo(trackLayer);

      // Last point marker
      const lastPt = data[data.length - 1];
      L.circleMarker([lastPt.latitude, lastPt.longitude], {
        radius: 8, color: color, fillColor: color, fillOpacity: 0.9, weight: 2
      }).bindTooltip(`${name}: ${toTime(lastPt.timestamp)}`).addTo(trackLayer);
    } catch (e) {
      console.error(`Comparison fetch failed for device ${did}:`, e);
    }
  }

  if (allBounds.length > 0) {
    map.fitBounds(allBounds, { padding: [30, 30] });
  }
}

// ========== DEVICE PERIMETERS (Circular Geofences) ==========

async function loadDevicePerimeters(deviceId) {
  try {
    const url = deviceId ? `${API}?action=get_device_perimeters&device_id=${deviceId}` : `${API}?action=get_device_perimeters`;
    const res = await apiGet(url);
    return (res.ok && Array.isArray(res.data)) ? res.data : [];
  } catch (e) {
    console.error('Failed to load device perimeters:', e);
    return [];
  }
}

function renderDevicePerimetersOnMap(perimeters) {
  // Remove existing device perimeter circles
  if (window._devicePerimeterLayer) {
    map.removeLayer(window._devicePerimeterLayer);
  }
  window._devicePerimeterLayer = L.layerGroup().addTo(map);

  perimeters.forEach(p => {
    if (!p.is_active) return;
    const color = p.device_color || '#3388ff';
    const circle = L.circle([p.latitude, p.longitude], {
      radius: p.radius_meters,
      color: color,
      fillColor: color,
      fillOpacity: 0.1,
      weight: 2,
      dashArray: '5,5'
    });
    circle.bindTooltip(`${p.name} (${p.device_name})`);
    circle.addTo(window._devicePerimeterLayer);
  });
}

async function openDevicePerimeterManager(deviceId) {
  const dev = getDeviceById(deviceId);
  if (!dev) return;

  const perimeters = await loadDevicePerimeters(deviceId);
  const overlay = $('#devicePerimeterOverlay');
  if (!overlay) return;

  overlay.classList.remove('hidden');
  const body = overlay.querySelector('.overlay-body');
  body.innerHTML = `
    <h3>Perimeter zóny - ${dev.name}</h3>
    <div id="devicePerimeterList">
      ${perimeters.length === 0 ? '<p class="empty-state">Žiadne perimeter zóny</p>' : perimeters.map(p => `
        <div class="perimeter-card">
          <div class="perimeter-card-header">
            <span class="device-color-indicator" style="background:${dev.color}"></span>
            <strong>${p.name}</strong>
            <span class="perimeter-radius">${p.radius_meters}m</span>
            <span class="device-card-status ${p.is_active ? 'active' : 'inactive'}">${p.is_active ? 'Aktívne' : 'Neaktívne'}</span>
          </div>
          <div class="perimeter-card-info">
            <span>Vstup: ${p.alert_on_enter ? 'Áno' : 'Nie'}</span>
            <span>Výstup: ${p.alert_on_exit ? 'Áno' : 'Nie'}</span>
          </div>
          <div class="device-card-actions">
            <button class="btn-sm btn-edit" onclick="editDevicePerimeter(${p.id}, ${deviceId})">Upraviť</button>
            <button class="btn-sm btn-danger" onclick="deleteDevicePerimeter(${p.id}, ${deviceId})">Zmazať</button>
          </div>
        </div>
      `).join('')}
    </div>
    <button class="btn-primary" onclick="openDevicePerimeterForm(${deviceId})">Pridať perimeter</button>
  `;
}

function closeDevicePerimeterManager() {
  const overlay = $('#devicePerimeterOverlay');
  if (overlay) overlay.classList.add('hidden');
}

function openDevicePerimeterForm(deviceId, perimeter = null) {
  const form = $('#devicePerimeterForm');
  if (!form) return;

  form.querySelector('#dpFormTitle').textContent = perimeter ? 'Upraviť perimeter' : 'Nový perimeter';
  form.querySelector('#dpId').value = perimeter ? perimeter.id : '';
  form.querySelector('#dpDeviceId').value = deviceId;
  form.querySelector('#dpName').value = perimeter ? perimeter.name : '';
  form.querySelector('#dpLat').value = perimeter ? perimeter.latitude : '';
  form.querySelector('#dpLng').value = perimeter ? perimeter.longitude : '';
  form.querySelector('#dpRadius').value = perimeter ? perimeter.radius_meters : 500;
  form.querySelector('#dpAlertEnter').checked = perimeter ? perimeter.alert_on_enter : false;
  form.querySelector('#dpAlertExit').checked = perimeter ? perimeter.alert_on_exit : false;

  form.classList.remove('hidden');
}

function closeDevicePerimeterForm() {
  const form = $('#devicePerimeterForm');
  if (form) form.classList.add('hidden');
}

async function saveDevicePerimeter() {
  const form = $('#devicePerimeterForm');
  if (!form) return;

  const id = form.querySelector('#dpId').value;
  const payload = {
    device_id: parseInt(form.querySelector('#dpDeviceId').value),
    name: form.querySelector('#dpName').value.trim(),
    latitude: parseFloat(form.querySelector('#dpLat').value),
    longitude: parseFloat(form.querySelector('#dpLng').value),
    radius_meters: parseFloat(form.querySelector('#dpRadius').value),
    alert_on_enter: form.querySelector('#dpAlertEnter').checked,
    alert_on_exit: form.querySelector('#dpAlertExit').checked
  };
  if (id) payload.id = parseInt(id);

  if (!payload.name || isNaN(payload.latitude) || isNaN(payload.longitude)) {
    alert('Vyplňte všetky povinné polia');
    return;
  }

  try {
    const res = await apiPost('save_device_perimeter', payload);
    if (res.ok) {
      closeDevicePerimeterForm();
      openDevicePerimeterManager(payload.device_id);
      // Refresh perimeters on map
      const allPerimeters = await loadDevicePerimeters();
      renderDevicePerimetersOnMap(allPerimeters);
    } else {
      alert(res.error || 'Chyba pri ukladaní');
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function editDevicePerimeter(perimeterId, deviceId) {
  const perimeters = await loadDevicePerimeters(deviceId);
  const p = perimeters.find(x => x.id === perimeterId);
  if (p) openDevicePerimeterForm(deviceId, p);
}

async function deleteDevicePerimeter(perimeterId, deviceId) {
  if (!confirm('Naozaj chcete zmazať tento perimeter?')) return;
  try {
    await apiGet(`${API}?action=delete_device_perimeter&id=${perimeterId}`);
    openDevicePerimeterManager(deviceId);
    const allPerimeters = await loadDevicePerimeters();
    renderDevicePerimetersOnMap(allPerimeters);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

// ========== ADMIN: USER MANAGEMENT ==========

let usersCache = [];

async function loadUsers() {
  try {
    const res = await apiGet(`${API}?action=get_users`);
    if (res.ok && Array.isArray(res.data)) {
      usersCache = res.data;
      return res.data;
    }
  } catch (e) {
    console.error('Failed to load users:', e);
  }
  return [];
}

function createUserManagementOverlay() {
  let overlay = document.getElementById('userManagementOverlay');
  if (overlay) return overlay;

  overlay = document.createElement('div');
  overlay.id = 'userManagementOverlay';
  overlay.className = 'overlay hidden';
  overlay.innerHTML = `
    <div class="overlay-content settings-content">
      <div class="overlay-header">
        <h2>Správa používateľov</h2>
        <button class="overlay-close" onclick="document.getElementById('userManagementOverlay').classList.add('hidden')">&times;</button>
      </div>
      <div class="overlay-body">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <span id="userCount" style="color:var(--muted-text);font-size:0.9em;"></span>
          <button class="btn-primary btn-small" onclick="showUserForm()">+ Nový používateľ</button>
        </div>
        <div id="userList">Načítavam...</div>
        <div id="userFormContainer" class="hidden" style="margin-top:20px;padding:16px;background:var(--bg-secondary);border-radius:12px;">
          <h3 id="userFormTitle" style="margin:0 0 12px;">Nový používateľ</h3>
          <form id="userForm" autocomplete="off" style="display:flex;flex-direction:column;gap:12px;">
            <input type="hidden" id="userFormId">
            <label style="display:flex;flex-direction:column;gap:4px;">
              <span style="font-size:0.85em;color:var(--muted-text);">Používateľské meno</span>
              <input type="text" id="userFormUsername" required autocomplete="off"
                style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
              <span style="font-size:0.85em;color:var(--muted-text);">Zobrazované meno</span>
              <input type="text" id="userFormDisplayName" autocomplete="off"
                style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
              <span style="font-size:0.85em;color:var(--muted-text);">Email</span>
              <input type="email" id="userFormEmail" autocomplete="off"
                style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
              <span style="font-size:0.85em;color:var(--muted-text);">Heslo <span id="userFormPasswordHint" style="font-size:0.8em;">(povinné pre nového používateľa)</span></span>
              <input type="password" id="userFormPassword" autocomplete="new-password"
                style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
              <span style="font-size:0.85em;color:var(--muted-text);">Rola</span>
              <select id="userFormRole"
                style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);">
                <option value="user">Používateľ</option>
                <option value="admin">Administrátor</option>
              </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;">
              <span style="font-size:0.85em;color:var(--muted-text);">Priradené zariadenia</span>
              <div id="userFormDevices" style="display:flex;flex-wrap:wrap;gap:8px;padding:8px 0;"></div>
            </label>
            <div id="userFormError" class="hidden" style="color:#ef4444;font-size:0.85em;padding:8px;background:rgba(239,68,68,0.1);border-radius:8px;"></div>
            <div style="display:flex;gap:8px;">
              <button type="submit" class="btn-primary btn-small">Uložiť</button>
              <button type="button" class="btn-secondary btn-small" onclick="hideUserForm()">Zrušiť</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);

  document.getElementById('userForm').addEventListener('submit', handleSaveUser);
  return overlay;
}

async function openUserManagement() {
  const overlay = createUserManagementOverlay();
  overlay.classList.remove('hidden');
  await renderUserList();
}

async function renderUserList() {
  const users = await loadUsers();
  const container = document.getElementById('userList');
  const countEl = document.getElementById('userCount');
  if (countEl) countEl.textContent = `${users.length} používateľ(ov)`;

  if (users.length === 0) {
    container.innerHTML = '<p style="color:var(--muted-text);text-align:center;">Žiadni používatelia</p>';
    return;
  }

  container.innerHTML = users.map(u => `
    <div class="device-card" style="margin-bottom:8px;">
      <div class="device-card-header">
        <span class="device-color-indicator" style="background:${u.role === 'admin' ? '#f59e0b' : '#3b82f6'}"></span>
        <strong>${u.display_name || u.username}</strong>
        <span style="font-size:0.8em;color:var(--muted-text);">@${u.username}</span>
        <span class="device-card-status ${u.is_active ? 'active' : 'inactive'}">${u.role === 'admin' ? 'Admin' : 'User'}${u.is_active ? '' : ' (neaktívny)'}</span>
      </div>
      <div style="font-size:0.85em;color:var(--muted-text);padding:4px 0;">
        ${u.email ? u.email + ' | ' : ''}Zariadenia: ${u.assigned_devices || 'žiadne'}
        ${u.last_login ? ' | Posledné prihlásenie: ' + new Date(u.last_login).toLocaleString('sk-SK') : ''}
      </div>
      <div class="device-card-actions">
        <button class="btn-sm btn-edit" onclick="editUser(${u.id})">Upraviť</button>
        <button class="btn-sm ${u.is_active ? 'btn-warning' : 'btn-primary'}" onclick="toggleUser(${u.id}, ${u.is_active ? 'false' : 'true'})">${u.is_active ? 'Deaktivovať' : 'Aktivovať'}</button>
        <button class="btn-sm btn-danger" onclick="deleteUser(${u.id}, '${u.username}')">Zmazať</button>
      </div>
    </div>
  `).join('');
}

function showUserForm(user = null) {
  document.getElementById('userFormContainer').classList.remove('hidden');
  document.getElementById('userFormTitle').textContent = user ? 'Upraviť používateľa' : 'Nový používateľ';
  document.getElementById('userFormId').value = user ? user.id : '';
  document.getElementById('userFormUsername').value = user ? user.username : '';
  document.getElementById('userFormDisplayName').value = user ? (user.display_name || '') : '';
  document.getElementById('userFormEmail').value = user ? (user.email || '') : '';
  document.getElementById('userFormPassword').value = '';
  document.getElementById('userFormRole').value = user ? user.role : 'user';
  document.getElementById('userFormPasswordHint').textContent = user ? '(nechajte prázdne ak nechcete meniť)' : '(povinné pre nového používateľa)';
  document.getElementById('userFormError').classList.add('hidden');

  // Render device checkboxes
  const devContainer = document.getElementById('userFormDevices');
  const assignedIds = user ? (user.device_ids || []) : [];
  devContainer.innerHTML = devices.map(d => `
    <label style="display:flex;align-items:center;gap:6px;padding:4px 8px;background:var(--bg-primary);border-radius:6px;cursor:pointer;">
      <input type="checkbox" value="${d.id}" ${assignedIds.includes(d.id) ? 'checked' : ''}>
      <span class="device-color-indicator" style="background:${d.color};width:10px;height:10px;"></span>
      <span style="font-size:0.9em;">${d.name}</span>
    </label>
  `).join('');
}

function hideUserForm() {
  document.getElementById('userFormContainer').classList.add('hidden');
}

async function handleSaveUser(e) {
  e.preventDefault();
  const errorEl = document.getElementById('userFormError');
  errorEl.classList.add('hidden');

  const id = document.getElementById('userFormId').value;
  const deviceCheckboxes = document.querySelectorAll('#userFormDevices input[type="checkbox"]');
  const deviceIds = Array.from(deviceCheckboxes).filter(cb => cb.checked).map(cb => parseInt(cb.value));

  const payload = {
    username: document.getElementById('userFormUsername').value.trim(),
    display_name: document.getElementById('userFormDisplayName').value.trim(),
    email: document.getElementById('userFormEmail').value.trim(),
    role: document.getElementById('userFormRole').value,
    is_active: true,
    device_ids: deviceIds,
  };
  if (id) payload.id = parseInt(id);

  const password = document.getElementById('userFormPassword').value;
  if (password) payload.password = password;

  try {
    const res = await apiPost('save_user', payload);
    if (res.ok) {
      hideUserForm();
      await renderUserList();
    } else {
      errorEl.textContent = res.error || 'Chyba pri ukladaní';
      errorEl.classList.remove('hidden');
    }
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove('hidden');
  }
}

async function editUser(userId) {
  const user = usersCache.find(u => u.id === userId);
  if (user) showUserForm(user);
}

async function toggleUser(userId, activate) {
  try {
    await apiPost('toggle_user', { id: userId, is_active: activate });
    await renderUserList();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function deleteUser(userId, username) {
  if (!confirm(`Naozaj zmazať používateľa "${username}"?`)) return;
  try {
    const res = await apiGet(`${API}?action=delete_user&id=${userId}`);
    if (res.ok) {
      await renderUserList();
    } else {
      alert(res.error || 'Chyba pri mazaní');
    }
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

// --------- boot ---------
window.addEventListener('DOMContentLoaded', () => {
  // Inicializuj PIN security najprv
  initPinSecurity();
  // Initialize log viewer
  initLogViewer();
  // Initialize MAC management
  initMacManagement();
});
