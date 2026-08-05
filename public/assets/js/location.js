const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
let pingTimer = null;

async function postForm(url, fields) {
  const fd = new FormData();
  fd.set('csrf_token', csrfToken);
  Object.entries(fields).forEach(([k, v]) => fd.set(k, v));
  const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
  return res.json();
}

document.getElementById('start-share-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const viewerId = document.getElementById('viewer_id').value;
  const duration = document.getElementById('duration').value;

  const data = await postForm('/actions/location_share_start.php', { viewer_id: viewerId, duration });
  if (data.success) {
    startPinging();
    location.reload();
  } else {
    alert(data.error || 'Could not start sharing.');
  }
});

document.querySelectorAll('.stop-share-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const data = await postForm('/actions/location_share_stop.php', { viewer_id: btn.dataset.viewer });
    if (data.success) location.reload();
  });
});

function startPinging() {
  if (pingTimer || !navigator.geolocation) return;
  const sendPing = () => {
    navigator.geolocation.getCurrentPosition((pos) => {
      postForm('/actions/location_ping.php', {
        lat: pos.coords.latitude,
        lng: pos.coords.longitude,
      });
    });
  };
  sendPing();
  pingTimer = setInterval(sendPing, 30000); // every 30s while an active share exists
}

// If this user already has active outgoing shares (rendered server-side),
// start pinging right away rather than waiting for a new share to be created.
if (document.querySelectorAll('.stop-share-btn').length > 0) {
  startPinging();
}

// --- Live updates for locations shared WITH this user ---
function connectSocket() {
  if (!window.WS_TOKEN) return;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const socket = new WebSocket(`${proto}://${location.host}/ws?token=${encodeURIComponent(window.WS_TOKEN)}`);

  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.type !== 'location') return;
    const row = document.querySelector(`[data-sharer="${data.sharer_public_id}"]`);
    if (row) {
      row.querySelector('.coords-cell').textContent = `${data.lat.toFixed(5)}, ${data.lng.toFixed(5)}`;
    }
  };
  socket.onclose = () => setTimeout(connectSocket, 3000);
}
connectSocket();
