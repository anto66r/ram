document.addEventListener('DOMContentLoaded', () => {
  chrome.storage.sync.get(['apiUrl', 'apiUser', 'apiPass'], ({ apiUrl, apiUser, apiPass }) => {
    if (apiUrl)  document.getElementById('api-url').value  = apiUrl;
    if (apiUser) document.getElementById('api-user').value = apiUser;
    if (apiPass) document.getElementById('api-pass').value = apiPass;
  });

  document.getElementById('save').addEventListener('click', () => {
    const apiUrl  = document.getElementById('api-url').value.trim();
    const apiUser = document.getElementById('api-user').value;
    const apiPass = document.getElementById('api-pass').value;
    chrome.storage.sync.set({ apiUrl, apiUser, apiPass }, () => {
      const status = document.getElementById('status');
      status.textContent = 'Saved!';
      setTimeout(() => { status.textContent = ''; }, 2000);
    });
  });
});
