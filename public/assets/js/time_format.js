/**
 * Formats a raw SQLite timestamp (UTC, no timezone marker) into a
 * WhatsApp/Telegram-style relative label:
 *   <1 min -> "Now", <60 min -> "Xm", today -> "9:41 AM",
 *   yesterday -> "Yesterday", <7 days -> "Mon", this year -> "Jun 12",
 *   older -> "Jun 12, 2025"
 */
function formatRelativeTime(raw) {
  if (!raw) return '';
  const iso = raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';

  const now = new Date();
  const diffMs = now - d;
  const diffMin = Math.floor(diffMs / 60000);

  if (diffMin < 1) return 'Now';
  if (diffMin < 60) return `${diffMin}m`;

  const isSameDay = d.toDateString() === now.toDateString();
  if (isSameDay) return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

  const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
  if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';

  const diffDays = Math.floor(diffMs / 86400000);
  if (diffDays < 7) return d.toLocaleDateString([], { weekday: 'short' });

  const sameYear = d.getFullYear() === now.getFullYear();
  return sameYear
    ? d.toLocaleDateString([], { month: 'short', day: 'numeric' })
    : d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

/** Truncates a message preview to a fixed length, WhatsApp-list style. */
function truncatePreview(text, max = 40) {
  if (!text) return '';
  return text.length > max ? text.slice(0, max).trimEnd() + '...' : text;
}

// Auto-format any timestamp placeholder present on the page - covers
// conversation-list rows and message bubbles alike, so pages that only
// need this (like groups.php) don't need their own duplicate loop.
document.querySelectorAll('.contact-time[data-created], .msg-time[data-created], .comment-card-time[data-created]').forEach((el) => {
  el.textContent = formatRelativeTime(el.dataset.created);
});
