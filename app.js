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

// --------- PIN Security ---------
let pinCode = '';
const PIN_LENGTH = 4;
const SESSION_DURATION = 24 * 60 * 60 * 1000; // 24 hodín

function initPinSecurity() {
  // Skontroluj či je užívateľ autentifikovaný
  const authToken = sessionStorage.getItem('tracker_auth');
  const authTime = sessionStorage.getItem('tracker_auth_time');

  if (authToken && authTime) {
    const elapsed = Date.now() - parseInt(authTime);
    if (elapsed < SESSION_DURATION) {
      // Autentifikácia je platná
      hidePinOverlay();
      return;
    }
  }

  // Zobraz PIN overlay
  showPinOverlay();
  setupPinHandlers();
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
  addUIHandlers();
  await loadAvailableDates();
  await refresh();

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

  // Načítaj aktuálne nastavenia
  try {
    await loadCurrentSettings();
  } catch (err) {
    console.error('Error loading settings:', err);
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

      // Aktualizuj "aktuálne hodnoty" labels
      $('#currentHysteresisMeters').textContent = `(${data.hysteresis_meters || 50} m)`;
      $('#currentHysteresisMinutes').textContent = `(${data.hysteresis_minutes || 30} min)`;
      $('#currentUniquePrecision').textContent = `(${data.unique_precision || 6})`;
      $('#currentUniqueBucketMinutes').textContent = `(${data.unique_bucket_minutes || 30} min)`;
      $('#currentMacCacheMaxAgeDays').textContent = `(${data.mac_cache_max_age_days || 3600} dní)`;
      $('#currentGoogleForce').textContent = (data.google_force === '1' || data.google_force === 1) ? '(Zapnuté)' : '(Vypnuté)';

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
      google_force: $('#googleForce').checked ? '1' : '0'
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
    'google_force': 'googleForce'
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
    { label: 'Lng', sortable: true }
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
    
    return `
    <tr class="${rowClass}" data-lat="${r.lat}" data-lng="${r.lng}" data-idx="${idx}">
      <td>${displayNum}</td>
      <td>${fromDisplay}</td>
      <td>${toDisplay}</td>
      <td>${dur(r.from, r.to)}</td>
      <td>${r.source}</td>
      <td>${r.lat.toFixed(6)}</td>
      <td>${r.lng.toFixed(6)}</td>
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
    cancelPerimeterEdit.addEventListener('click', showPerimeterListView);
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
}

// --------- Perimeter Management ---------
let perimeterDrawMap = null;
let perimeterPolygonLayer = null;
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
  editingPerimeterId = null;
}

function showPerimeterListView() {
  $('#perimeterListView').classList.remove('hidden');
  $('#perimeterEditView').classList.add('hidden');
  $('#perimeterOverlayTitle').textContent = 'Perimeter zóny';
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

  // Initialize map after view is shown
  setTimeout(() => {
    initPerimeterDrawMap();
    drawPolygonOnMap();
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

function initPerimeterDrawMap() {
  if (perimeterDrawMap) {
    perimeterDrawMap.remove();
  }

  perimeterDrawMap = L.map('perimeterDrawMap').setView([48.1486, 17.1077], 13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap'
  }).addTo(perimeterDrawMap);

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
      if (p.alert_on_enter) badges.push('<span class="perimeter-badge enter">Vstup</span>');
      if (p.alert_on_exit) badges.push('<span class="perimeter-badge exit">Opustenie</span>');
      if (!p.is_active) badges.push('<span class="perimeter-badge inactive">Neaktívny</span>');

      return `
        <div class="perimeter-item ${p.is_active ? '' : 'inactive'}" data-id="${p.id}">
          <div class="perimeter-color" style="background-color:${p.color || '#ff6b6b'}"></div>
          <div class="perimeter-info">
            <h4>${escapeHtml(p.name)}</h4>
            <small>${p.notification_email || 'Bez e-mailu'} | ${p.polygon?.length || 0} bodov</small>
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

// --------- boot ---------
window.addEventListener('DOMContentLoaded', () => {
  // Inicializuj PIN security najprv
  initPinSecurity();
});
