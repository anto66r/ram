document.addEventListener('DOMContentLoaded', () => {
  chrome.storage.sync.get(['apiUrl'], ({ apiUrl }) => {
    if (apiUrl) document.getElementById('api-url').value = apiUrl;
  });

  document.getElementById('save').addEventListener('click', () => {
    const url = document.getElementById('api-url').value.trim();
    chrome.storage.sync.set({ apiUrl: url }, () => {
      const status = document.getElementById('status');
      status.textContent = 'Saved!';
      setTimeout(() => { status.textContent = ''; }, 2000);
    });
  });
});
