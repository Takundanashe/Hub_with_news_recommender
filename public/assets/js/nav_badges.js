/**
 * Keeps the Messages/Groups nav badges live across the whole app, not just
 * while sitting inside Messages/Groups themselves. Included globally from
 * includes/layout_bottom.php.
 *
 * Deliberately a SEPARATE socket connection from chat.js/group.js/news.js's
 * own sockets on pages that already have one (chat.php, group.php, the news
 * feed) - a small inefficiency (up to 2 sockets open on those specific
 * pages) traded for not having to thread a shared connection object through
 * every page-specific script. Worth consolidating later if connection count
 * ever becomes a real concern; harmless for now.
 */
function bumpNavBadge(key) {
  document.querySelectorAll(`[data-badge="${key}"]`).forEach((el) => {
    const current = parseInt(el.textContent, 10) || 0;
    const next = current + 1;
    el.textContent = next > 99 ? '99+' : String(next);
    el.classList.add('is-visible');
  });
}

function connectNavBadgeSocket() {
  if (!window.WS_TOKEN) return;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const socket = new WebSocket(`${proto}://${location.host}/ws?token=${encodeURIComponent(window.WS_TOKEN)}`);

  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);

    // Don't bump the badge for a conversation the user is actively looking
    // at right now - they already see it, and dm_fetch/group view marks it
    // read almost immediately, so bumping then immediately un-bumping would
    // just flicker.
    if (data.type === 'dm' && data.from && data.from.public_id !== window.CHAT_WITH) {
      bumpNavBadge('messages');
    } else if (data.type === 'group' && data.group_id !== window.GROUP_ID) {
      bumpNavBadge('groups');
    }
  };

  socket.onerror = (err) => console.error('Nav badge socket error - will retry:', err);
  socket.onclose = () => setTimeout(connectNavBadgeSocket, 3000);
}
connectNavBadgeSocket();
