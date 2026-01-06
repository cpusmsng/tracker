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
    // Check auth config to determine SSO or PIN mode
    const configRes = await fetch(`${API}?action=auth_config`);
    const config = await configRes.json();

    if (config.ok && config.ssoEnabled) {
      ssoEnabled = true;
      await handleSSOAuth(config.loginUrl);
      return;
    }
  } catch (e) {
    console.warn('Auth config check failed, falling back to PIN mode', e);
  }

  // PIN mode fallback
  handlePINAuth();
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
    console.log('[SSO] v2 - Checking authentication...');

    const authRes = await fetch(`${API}?action=auth_me`, {
      credentials: 'include'
    });

    const authText = await authRes.text();
    console.log('[SSO] Raw response:', authText.substring(0, 300));

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

    console.log('[SSO] Parsed:', auth.ok, auth.authenticated, auth.user?.name);

    if (auth.ok && auth.authenticated && auth.user) {
      // User is authenticated via SSO - NOW show content
      console.log('[SSO] SUCCESS - Authenticated as:', auth.user.name);
      currentUser = auth.user;
      showAuthenticatedContent(); // Show content only after confirmed auth
      hidePinOverlay();
      showUserInfo(auth.user);
      initAppAfterAuth();

      // Set up periodic session check (but don't redirect on failure)
      setInterval(checkSSOSession, 5 * 60 * 1000);
      window.addEventListener('focus', checkSSOSession);
    } else {
      // Not authenticated via SSO - DO NOT show content
      console.log('[SSO] Not authenticated, debug:', auth.debug);

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
  if (userNameDisplay && user.name) {
    userNameDisplay.textContent = user.name;
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
  logoutBtn.addEventListener('click', handleLogout);
  hamburgerMenu.appendChild(logoutBtn);
}

async function handleLogout() {
  try {
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
    window.location.href = 'https://bagron.eu/login';
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
  // This will be called after successful auth (SSO or PIN)
  // initializeApp() already calls initMap, initCalendar, initHamburgerMenu, addUIHandlers
  if (ssoEnabled) {
    initTheme();
    initializeApp();
    initPerimeterManagement();
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

  // Update battery a last online každých 30 sekúnd pre real-time info
  setInterval(() => {
    updateBatteryStatus();
  }, 30 * 1000);
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
async function apiGet(url) {
  const r = await fetch(url, { method:'GET' });
  if (!r.ok) throw new Error(`GET ${url} -> ${r.status}`);
  return r.json();
}
async function apiPost(action, payload) {
  const r = await fetch(`${API}?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type':'application/json' },
    body: JSON.stringify(payload)
  });
  if (!r.ok) throw new Error(`POST ${action} -> ${r.status}`);
  return r.json();
}
async function apiDelete(action, payload) {
  const r = await fetch(`${API}?action=${action}`, {
    method: 'DELETE',
    headers: { 'Content-Type':'application/json' },
    body: JSON.stringify(payload)
  });
  if (!r.ok) throw new Error(`DELETE ${action} -> ${r.status}`);
  return r.json();
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
async function updateBatteryStatus() {
  try {
    const data = await apiGet(`${API}?action=get_device_status`);
    
    console.log('Device status response:', data);
    
    // Update battery status
    if (data.ok && data.battery_state !== null && data.battery_state !== undefined) {
      const state = data.battery_state; // 0 = low, 1 = good
      const textSpan = $('#batteryText');
      
      if (textSpan) {
        textSpan.textContent = state === 1 ? 'Dobrá' : 'Slabá';
        console.log('Battery updated to:', state === 1 ? 'Dobrá' : 'Slabá');
      }
      
      // Nastav farbu podľa stavu
      const indicator = $('#batteryIndicator');
      if (indicator) {
        if (state === 1) {
          indicator.setAttribute('data-level', 'good');
        } else {
          indicator.setAttribute('data-level', 'low');
        }
      }
    } else {
      console.log('No battery data available:', data);
      const textSpan = $('#batteryText');
      if (textSpan) {
        textSpan.textContent = '—';
      }
    }
    
    // Update last online time (from real-time API data)
    if (data.ok && data.latest_message_time) {
      const lastOnlineText = $('#lastOnlineText');
      const lastOnlineInfo = $('#lastOnlineInfo');
      
      if (lastOnlineText) {
        try {
          const uploadTime = new Date(data.latest_message_time);
          const now = new Date();
          const diffMs = now - uploadTime;
          const diffSeconds = Math.floor(diffMs / 1000);
          const diffMinutes = Math.floor(diffMs / 60000);
          
          // Presnejšie časové zobrazenie
          if (diffSeconds < 60) {
            lastOnlineText.textContent = 'teraz';
          } else if (diffMinutes < 60) {
            lastOnlineText.textContent = `pred ${diffMinutes}m`;
          } else {
            const diffHours = Math.floor(diffMinutes / 60);
            if (diffHours < 24) {
              const remainingMinutes = diffMinutes % 60;
              if (remainingMinutes > 0 && diffHours < 6) {
                lastOnlineText.textContent = `pred ${diffHours}h ${remainingMinutes}m`;
              } else {
                lastOnlineText.textContent = `pred ${diffHours}h`;
              }
            } else {
              const diffDays = Math.floor(diffHours / 24);
              const remainingHours = diffHours % 24;
              if (remainingHours > 0 && diffDays < 7) {
                lastOnlineText.textContent = `pred ${diffDays}d ${remainingHours}h`;
              } else {
                lastOnlineText.textContent = `pred ${diffDays}d`;
              }
            }
          }
          
          // Set online/offline status color
          if (lastOnlineInfo && data.online_status !== undefined) {
            if (data.online_status === 1) {
              lastOnlineInfo.setAttribute('data-status', 'online');
              lastOnlineInfo.title = 'Online - Posledný upload dát';
            } else {
              lastOnlineInfo.setAttribute('data-status', 'offline');
              lastOnlineInfo.title = 'Offline - Posledný upload dát';
            }
          }
          
          // Ak je z cache, pridaj indikátor
          if (data.from_cache) {
            if (lastOnlineInfo) {
              lastOnlineInfo.setAttribute('data-source', 'cache');
              lastOnlineInfo.title += ' (z cache)';
            }
          }
        } catch (e) {
          console.error('Error parsing upload time:', e);
          lastOnlineText.textContent = '—';
        }
      }
    } else {
      const lastOnlineText = $('#lastOnlineText');
      const lastOnlineInfo = $('#lastOnlineInfo');
      if (lastOnlineText) {
        lastOnlineText.textContent = '—';
      }
      if (lastOnlineInfo) {
        lastOnlineInfo.removeAttribute('data-status');
      }
    }
  } catch (err) {
    console.error('Device status error:', err);
    const textSpan = $('#batteryText');
    const lastOnlineText = $('#lastOnlineText');
    if (textSpan) {
      textSpan.textContent = '—';
    }
    if (lastOnlineText) {
      lastOnlineText.textContent = '—';
    }
  }
}

// --------- Hamburger Menu ---------
function initHamburgerMenu() {
  const btn = $('#hamburgerBtn');
  const menu = $('#hamburgerMenu');

  console.log('Initializing hamburger menu...');
  console.log('Menu button:', btn);
  console.log('Menu:', menu);

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

  // Menu items
  const menuRefetch = $('#menuRefetch');
  const menuManageIBeacons = $('#menuManageIBeacons');
  const menuViewIBeacons = $('#menuViewIBeacons');
  const menuSettings = $('#menuSettings');

  console.log('Menu items found:');
  console.log('- Refetch:', menuRefetch);
  console.log('- Manage iBeacons:', menuManageIBeacons);
  console.log('- View iBeacons:', menuViewIBeacons);
  console.log('- Settings:', menuSettings);

  if (menuRefetch) {
    menuRefetch.addEventListener('click', () => {
      console.log('Refetch clicked');
      menu.classList.add('hidden');
      btn.classList.remove('active');
      refetchDay();
    });
  }

  // iBeacon submenu toggle
  const menuIBeacons = $('#menuIBeacons');
  if (menuIBeacons) {
    menuIBeacons.addEventListener('click', (e) => {
      e.stopPropagation();
      const submenuItems = menuIBeacons.parentElement.querySelector('.submenu-items');
      if (submenuItems) {
        submenuItems.classList.toggle('hidden');
        menuIBeacons.classList.toggle('expanded');
      }
    });
  }

  if (menuManageIBeacons) {
    menuManageIBeacons.addEventListener('click', () => {
      console.log('Manage iBeacons clicked');
      menu.classList.add('hidden');
      btn.classList.remove('active');
      openIBeaconOverlay();
    });
  }

  if (menuViewIBeacons) {
    menuViewIBeacons.addEventListener('click', () => {
      console.log('View iBeacons clicked');
      menu.classList.add('hidden');
      btn.classList.remove('active');
      openIBeaconListOverlay();
    });
  }

  if (menuSettings) {
    console.log('Attaching click handler to Settings menu item');
    menuSettings.addEventListener('click', () => {
      console.log('Settings menu clicked');
      menu.classList.add('hidden');
      btn.classList.remove('active');
      openSettingsOverlay().catch(err => {
        console.error('Error opening settings:', err);
      });
    });
  } else {
    console.error('Settings menu item not found! Check if index.html has been updated.');
  }

  // Perimeter zones menu item
  const menuPerimeters = $('#menuPerimeters');
  if (menuPerimeters) {
    menuPerimeters.addEventListener('click', () => {
      console.log('Perimeters menu clicked');
      menu.classList.add('hidden');
      btn.classList.remove('active');
      openPerimeterOverlay();
    });
  }
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
  console.log('openSettingsOverlay called');
  const overlay = $('#settingsOverlay');
  console.log('Settings overlay element:', overlay);

  if (!overlay) {
    console.error('Settings overlay not found in DOM!');
    return;
  }

  overlay.classList.remove('hidden');
  console.log('Overlay should now be visible');

  // Inicializuj accordion sekcie
  initSettingsTabs();

  // Načítaj aktuálne nastavenia
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

      // Deaktivuj všetky taby
      tabs.forEach(t => t.classList.remove('active'));
      // Aktivuj kliknutý tab
      tab.classList.add('active');

      // Skry všetky sekcie
      sections.forEach(s => s.classList.remove('open'));
      // Zobraz cieľovú sekciu
      const target = document.querySelector(`.settings-section[data-section="${targetSection}"]`);
      if (target) {
        target.classList.add('open');
      }
    });
  });

  // Otvor prvú sekciu ako default
  if (sections.length > 0 && !document.querySelector('.settings-section.open')) {
    sections[0].classList.add('open');
  }
  // Aktivuj prvý tab ako default
  if (tabs.length > 0 && !document.querySelector('.settings-tab.active')) {
    tabs[0].classList.add('active');
  }
}

function closeSettingsOverlay() {
  $('#settingsOverlay').classList.add('hidden');
}

async function loadCurrentSettings() {
  console.log('loadCurrentSettings called');
  try {
    console.log('Fetching settings from API...');
    const settings = await apiGet(`${API}?action=get_settings`);
    console.log('Settings response:', settings);

    if (settings && settings.ok) {
      const data = settings.data;
      console.log('Settings data:', data);

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

      // Initialize theme toggle in settings
      initTheme();

      console.log('Settings loaded successfully');
    } else {
      console.warn('Settings response not OK:', settings);
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
      smart_refetch_days: parseInt($('#smartRefetchDays').value) || 7
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
    'smart_refetch_days': 'smartRefetchDays'
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

  const data = await apiGet(`${API}?action=get_history&date=${encodeURIComponent(dateStr)}`);
  
  // Načítaj aj posledný bod z predošlého dňa
  const prevDate = new Date(dateStr);
  prevDate.setDate(prevDate.getDate() - 1);
  const prevDateStr = fmt(prevDate);
  const prevData = await apiGet(`${API}?action=get_history&date=${encodeURIComponent(prevDateStr)}`);
  const lastPrevPoint = (prevData && prevData.length > 0) ? prevData[prevData.length - 1] : null;
  
  // OPRAVA: Rozlišuj medzi dneškom a minulým dňom
  if (!Array.isArray(data) || data.length === 0) {
    const today = fmt(new Date());
    const isToday = (dateStr === today);
    
    if (isToday) {
      // Pre dnešok zobraz poslednú známu polohu
      const lastPosition = await apiGet(`${API}?action=get_last_position`);
      
      if (lastPosition && lastPosition.latitude && lastPosition.longitude) {
        // Zobraz poslednú známu polohu na mape
        const markerHtml = `
          <style>
            @keyframes blink-marker {
              0%, 100% { opacity: 1; transform: scale(1); }
              50% { opacity: 0.3; transform: scale(1.3); }
            }
          </style>
          <div style="
            width: 16px;
            height: 16px;
            background: #ff9800;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 4px rgba(0,0,0,0.3);
            animation: blink-marker 1.5s ease-in-out infinite;
          "></div>
        `;
        
        const icon = L.divIcon({
          className: '',
          html: markerHtml,
          iconSize: [22, 22],
          iconAnchor: [11, 11]
        });
        
        lastPositionMarker = L.marker([lastPosition.latitude, lastPosition.longitude], { icon })
          .bindTooltip(`Posledná známa poloha<br>${new Date(lastPosition.timestamp).toLocaleString('sk-SK')}`, 
            { permanent:false, opacity:1 })
          .addTo(map);
        
        map.setView([lastPosition.latitude, lastPosition.longitude], 15);
        
        // Zobraz info v tabuľke
        const rows = [{
          i: 1,
          id: lastPosition.id, // Add position ID for editing
          from: lastPosition.timestamp,
          to: null,
          durMin: 0,
          source: lastPosition.source,
          lat: lastPosition.latitude,
          lng: lastPosition.longitude,
          isPrevDay: false,
          isLastKnown: true,  // <-- toto označuje že je to "last known"
          sortValues: [
            1,
            new Date(lastPosition.timestamp).getTime(),
            0,
            0,
            lastPosition.source,
            lastPosition.latitude,
            lastPosition.longitude
          ]
        }];
        renderTable(rows);
        
        // Pridaj info text
        const info = document.createElement('div');
        info.style.cssText = 'padding: 12px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin-bottom: 12px; color: #92400e;';
        info.innerHTML = '<strong>Žiadny pohyb v tento deň.</strong> Zobrazená je posledná známa poloha.';
        $('#positionsTable').prepend(info);
      } else {
        $('#positionsTable').innerHTML = 'Žiadne dáta pre dnes a žiadna posledná známa poloha.';
      }
    } else {
      // OPRAVA: Pre minulý deň bez pohybu nezobrazuj tabuľku ani posledný bod
      $('#positionsTable').innerHTML = '<div style="padding: 12px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin: 12px; color: #92400e;"><strong>Žiadny pohyb v tento deň.</strong></div>';
    }
    return;
  }

  // polyline
  const latlngs = data.map(p => [p.latitude, p.longitude]);
  const line = L.polyline(latlngs, { color:'#3388ff', weight:3 }).addTo(trackLayer);
  map.fitBounds(line.getBounds(), { padding:[20,20] });

  // body s tooltipom
  data.forEach((p, idx) => {
    // Posledný bod bude blikajúci
    if (idx === data.length - 1) {
      // Vytvor HTML element pre blikajúci marker s inline keyframes
      const markerHtml = `
        <style>
          @keyframes blink-marker {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(1.3); }
          }
        </style>
        <div style="
          width: 16px;
          height: 16px;
          background: #3388ff;
          border-radius: 50%;
          border: 3px solid white;
          box-shadow: 0 0 4px rgba(0,0,0,0.3);
          animation: blink-marker 1.5s ease-in-out infinite;
        "></div>
      `;
      
      const icon = L.divIcon({
        className: '',
        html: markerHtml,
        iconSize: [22, 22],
        iconAnchor: [11, 11]
      });
      
      lastPositionMarker = L.marker([p.latitude, p.longitude], { icon })
        .bindTooltip(new Date(p.timestamp).toLocaleString('sk-SK'), { permanent:false, opacity:1 })
        .addTo(map);

      // Add click handler to highlight corresponding table row
      lastPositionMarker.on('click', () => {
        highlightTableRowByCoords(p.latitude, p.longitude);
      });
    } else {
      const marker = L.circleMarker([p.latitude, p.longitude], {
        radius:4,
        fillColor: '#3388ff',
        color: '#fff',
        weight: 1,
        fillOpacity: 0.8
      })
       .bindTooltip(new Date(p.timestamp).toLocaleString('sk-SK'), { permanent:false, opacity:1 })
       .addTo(trackLayer);

      // Add click handler to highlight corresponding table row
      marker.on('click', () => {
        highlightTableRowByCoords(p.latitude, p.longitude);
        highlightMarker(marker);
      });

      circleMarkers.push({ marker, lat: p.latitude, lng: p.longitude });
    }
  });

  // tabuľka "od–do" s prvým riadkom z predošlého dňa
  const rows = [];
  
  // Ak existuje posledný bod z predošlého dňa, pridaj ho ako prvý riadok
  if (lastPrevPoint && data.length > 0) {
    const firstCur = data[0];
    const durMin = Math.max(0, Math.round((new Date(firstCur.timestamp) - new Date(lastPrevPoint.timestamp)) / 60000));
    rows.push({
      i: 0,
      id: lastPrevPoint.id, // Add position ID for editing
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
  for (let i=0; i<data.length; i++) {
    const cur = data[i];
    const nxt = data[i+1];
    const durMin = nxt ? Math.max(0, Math.round((new Date(nxt.timestamp) - new Date(cur.timestamp)) / 60000)) : 0;
    rows.push({
      i: i+1,
      id: cur.id, // Add position ID for editing
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

    // Debug: log row data to verify id exists
    console.log('Row data:', { i: r.i, id: r.id, source: r.source });

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
          // Reset all markers to default style
          circleMarkers.forEach(m => {
            m.marker.setStyle({
              radius: 4,
              fillColor: '#3388ff',
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
  // Reset all markers to default style
  circleMarkers.forEach(cm => {
    cm.marker.setStyle({
      radius: 4,
      fillColor: '#3388ff',
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
  await loadHistory(fmt(currentDate));
  await loadIBeacons();
  await loadPerimetersOnMainMap();
  await updateBatteryStatus();
  // updateLastOnline() removed - updateBatteryStatus() now handles this from device status API
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

  // Show edit section
  $('#macSelectedInfo').style.display = 'block';
  $('#macSelectedAddress').textContent = mac;
  $('#macEditLat').value = macData.lat !== null ? macData.lat : '';
  $('#macEditLng').value = macData.lng !== null ? macData.lng : '';

  // Update map
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

  if (perimeter) {
    editingPerimeterId = perimeter.id;
    $('#perimeterOverlayTitle').textContent = 'Upraviť perimeter';
    $('#perimeterId').value = perimeter.id;
    $('#perimeterName').value = perimeter.name;
    $('#perimeterColor').value = perimeter.color || '#ff6b6b';
    perimeterPolygonPoints = perimeter.polygon || [];

    // Populate emails list
    renderEmailsList(perimeter.emails || []);
  } else {
    editingPerimeterId = null;
    $('#perimeterOverlayTitle').textContent = 'Nový perimeter';
    $('#perimeterId').value = '';
    $('#perimeterName').value = '';
    $('#perimeterColor').value = '#ff6b6b';
    perimeterPolygonPoints = [];

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

      return `
        <div class="perimeter-item ${p.is_active ? '' : 'inactive'}" data-id="${p.id}">
          <div class="perimeter-color" style="background-color:${p.color || '#ff6b6b'}"></div>
          <div class="perimeter-info">
            <h4>${escapeHtml(p.name)}</h4>
            <small>${emailText} | ${p.polygon?.length || 0} bodov</small>
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
    const payload = {
      name: name,
      polygon: perimeterPolygonPoints,
      emails: emails,
      color: color,
      is_active: true
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

    console.log('Test email response:', response);

    if (response.ok) {
      resultEl.textContent = response.message || 'Testovací e-mail bol odoslaný!';
      resultEl.className = 'test-email-result success';

      if (response.debug) {
        console.log('Test email debug:', response.debug);
      }
    } else {
      let errorMsg = response.error || `Chyba servera (HTTP ${r.status})`;

      if (response.debug) {
        console.error('Test email error debug:', response.debug);
      }

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
  debounceTimer: null
};

function openLogViewer() {
  const overlay = $('#logViewerOverlay');
  if (overlay) {
    overlay.classList.remove('hidden');
    loadLogStats();
    loadLogs();
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
          total_lines_read: d.debug.total_lines_read,
          lines_matched: d.debug.lines_matched,
          lines_after_filter: d.debug.lines_after_filter,
          newest_timestamp: d.debug.newest_timestamp,
          oldest_timestamp: d.debug.oldest_timestamp,
          unmatched_samples: d.debug.unmatched_samples
        });
      }

      // Update file info
      const fileInfo = $('#logFileInfo');
      if (fileInfo) {
        const sizeKB = d.file_size ? (d.file_size / 1024).toFixed(1) : 0;
        const debugInfo = d.debug ? ` | Riadkov: ${d.debug.total_lines_read} | Najnovší: ${d.debug.newest_timestamp || 'N/A'}` : '';
        fileInfo.textContent = `Súbor: ${d.file || 'N/A'} (${sizeKB} KB)${debugInfo}`;
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
  if (!confirm('Naozaj chcete vymazať všetky logy? Záloha bude vytvorená.')) {
    return;
  }

  try {
    const response = await apiPost('clear_logs', {});

    if (response && response.ok) {
      alert(`Logy boli vymazané. Záloha: ${response.backup}`);
      loadLogs();
      loadLogStats();
    } else {
      alert(`Chyba: ${response.error || 'Neznáma chyba'}`);
    }
  } catch (err) {
    alert(`Chyba: ${err.message}`);
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
