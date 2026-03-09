function resizeImage(dataUrl, maxW = 640) {
  return new Promise(resolve => {
    const img = new Image();
    img.onload = () => {
      const scale = Math.min(1, maxW / img.width);
      const w = Math.round(img.width * scale);
      const h = Math.round(img.height * scale);
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      canvas.getContext('2d').drawImage(img, 0, 0, w, h);
      resolve(canvas.toDataURL('image/jpeg', 0.82));
    };
    img.src = dataUrl;
  });
}

async function handlePasteEvent(e) {
  const items = e.clipboardData?.items;
  if (!items) return;
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      const file = item.getAsFile();
      const reader = new FileReader();
      reader.onload = async ev => {
        const resized = await resizeImage(ev.target.result);
        setCover(resized);
      };
      reader.readAsDataURL(file);
      return;
    }
  }
}

let apiUrl = '';
let apiUser = '';
let apiPass = '';
let coverData = null;
let selectedTags = new Set();
let labels = [];

const $ = id => document.getElementById(id);

async function loadSettings() {
  return new Promise(resolve =>
    chrome.storage.sync.get(['apiUrl', 'apiUser', 'apiPass'], r => {
      apiUrl  = r.apiUrl  || '';
      apiUser = r.apiUser || '';
      apiPass = r.apiPass || '';
      resolve();
    })
  );
}

function authHeaders() {
  const headers = { 'Content-Type': 'application/json' };
  if (apiUser || apiPass) {
    headers['Authorization'] = 'Basic ' + btoa(`${apiUser}:${apiPass}`);
  }
  return headers;
}

async function apiFetch(params) {
  const res = await fetch(apiUrl, {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(params),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

async function apiGet(query) {
  const res = await fetch(`${apiUrl}?${query}`, { headers: authHeaders() });
  return res.json();
}

function showMsg(text, type) {
  const el = $('msg');
  el.className = `msg ${type}`;
  el.textContent = text;
  el.style.display = 'block';
}

function hideMsg() { $('msg').style.display = 'none'; }

function setCover(dataUrl) {
  coverData = dataUrl;
  if (dataUrl) {
    $('cover-img').src = dataUrl;
    $('cover-wrap').style.display = 'block';
    $('cover-placeholder').style.display = 'none';
  } else {
    $('cover-wrap').style.display = 'none';
    $('cover-placeholder').style.display = 'flex';
  }
}

function setUrl(url) {
  $('url').value = url || '';
  setCover(null);
  hideMsg();
}

function renderTags() {
  const list = $('tags-list');
  list.innerHTML = '';
  if (labels.length === 0) {
    list.innerHTML = '<span style="color:#555;font-size:0.78rem">No labels defined</span>';
    return;
  }
  labels.forEach(label => {
    const el = document.createElement('label');
    el.className = `tag-check${selectedTags.has(label) ? ' checked' : ''}`;
    el.innerHTML = `<input type="checkbox" />${label}`;
    el.addEventListener('click', (e) => {
      e.preventDefault();
      selectedTags.has(label) ? selectedTags.delete(label) : selectedTags.add(label);
      el.classList.toggle('checked', selectedTags.has(label));
    });
    list.appendChild(el);
  });
}

function setFetching(on) {
  const btn = $('fetch-btn');
  btn.disabled = on;
  btn.innerHTML = on ? '<span class="spinner"></span>' : 'Fetch';
}

function setAdding(on) {
  const btn = $('add-btn');
  btn.disabled = on;
  btn.innerHTML = on ? '<span class="spinner"></span>Adding…' : 'Add to Library';
}

async function fetchMeta() {
  const url = $('url').value.trim();
  if (!url) return;
  setFetching(true);
  hideMsg();
  try {
    const res = await apiFetch({ action: 'fetch_meta', url });
    if (res.cover) setCover(res.cover);
    else showMsg('No cover image found', 'error');
  } catch(e) {
    showMsg('Error: ' + e.message, 'error');
  }
  setFetching(false);
}

async function addVideo() {
  const url = $('url').value.trim();
  if (!url) { showMsg('Please enter a URL.', 'error'); return; }
  setAdding(true);
  hideMsg();
  try {
    const res = await apiFetch({
      action: 'add',
      url,
      tags: [...selectedTags],
      cover_data: coverData || undefined,
    });
    if (res.success) {
      showMsg(`Added: ${res.video.title}`, 'success');
      setCover(null);
      selectedTags.clear();
      renderTags();
    } else {
      showMsg(res.error || 'Failed to add video.', 'error');
    }
  } catch {
    showMsg('Network error.', 'error');
  }
  setAdding(false);
}

async function init() {
  await loadSettings();

  $('settings-btn').addEventListener('click', () => chrome.runtime.openOptionsPage());

  if (!apiUrl) {
    $('no-config').style.display = 'block';
    $('go-settings').addEventListener('click', () => chrome.runtime.openOptionsPage());
    return;
  }

  $('main').style.display = 'block';

  // Pre-fill with current active tab URL
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (tab?.url) setUrl(tab.url);

  // Update URL when user switches tabs or navigates
  chrome.tabs.onActivated.addListener(async ({ tabId, windowId }) => {
    const win = await chrome.windows.getCurrent();
    if (windowId !== win.id) return;
    const t = await chrome.tabs.get(tabId);
    if (t.url) setUrl(t.url);
  });

  chrome.tabs.onUpdated.addListener(async (tabId, changeInfo, t) => {
    if (changeInfo.status !== 'complete' || !t.active || !t.url) return;
    const win = await chrome.windows.getCurrent();
    if (t.windowId !== win.id) return;
    setUrl(t.url);
  });

  // Load labels
  try {
    const data = await apiGet('action=list');
    labels = data.labels || [];
    renderTags();
  } catch { /* labels stay empty */ }

  $('fetch-btn').addEventListener('click', fetchMeta);
  $('add-btn').addEventListener('click', addVideo);
  $('clear-cover').addEventListener('click', () => setCover(null));
  $('url').addEventListener('keydown', e => { if (e.key === 'Enter') addVideo(); });

  // Paste cover image
  const placeholder = $('cover-placeholder');
  placeholder.addEventListener('click', () => placeholder.focus());
  placeholder.addEventListener('paste', handlePasteEvent);
  document.addEventListener('paste', handlePasteEvent);
}

document.addEventListener('DOMContentLoaded', init);
