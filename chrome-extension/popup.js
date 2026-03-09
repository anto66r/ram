let apiUrl = '';
let coverData = null;
let selectedTags = new Set();
let labels = [];

const $ = id => document.getElementById(id);

async function getApiUrl() {
  return new Promise(resolve =>
    chrome.storage.sync.get(['apiUrl'], r => resolve(r.apiUrl || ''))
  );
}

async function apiFetch(params) {
  const res = await fetch(apiUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(params),
  });
  return res.json();
}

function showMsg(text, type) {
  const el = $('msg');
  el.className = `msg ${type}`;
  el.textContent = text;
  el.style.display = 'block';
}

function hideMsg() {
  $('msg').style.display = 'none';
}

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
    el.addEventListener('click', () => {
      if (selectedTags.has(label)) {
        selectedTags.delete(label);
        el.classList.remove('checked');
      } else {
        selectedTags.add(label);
        el.classList.add('checked');
      }
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
  } catch {
    showMsg('Could not fetch metadata', 'error');
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
      $('url').value = '';
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
  apiUrl = await getApiUrl();

  if (!apiUrl) {
    $('no-config').style.display = 'block';
    $('go-settings').addEventListener('click', () => chrome.runtime.openOptionsPage());
    $('settings-btn').addEventListener('click', () => chrome.runtime.openOptionsPage());
    return;
  }

  $('main').style.display = 'block';
  $('settings-btn').addEventListener('click', () => chrome.runtime.openOptionsPage());

  // Pre-fill current tab URL
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (tab?.url) $('url').value = tab.url;

  // Load labels
  try {
    const data = await fetch(`${apiUrl}?action=list`).then(r => r.json());
    labels = data.labels || [];
    renderTags();
  } catch { /* labels stay empty */ }

  $('fetch-btn').addEventListener('click', fetchMeta);
  $('add-btn').addEventListener('click', addVideo);
  $('clear-cover').addEventListener('click', () => setCover(null));

  // Allow Enter on URL field to trigger fetch then add
  $('url').addEventListener('keydown', e => {
    if (e.key === 'Enter') addVideo();
  });
}

document.addEventListener('DOMContentLoaded', init);
