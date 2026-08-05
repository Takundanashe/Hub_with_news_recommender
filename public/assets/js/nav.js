const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('sidebar-backdrop');

function openSidebar() {
  sidebar.classList.add('is-open');
  backdrop.classList.add('is-visible');
}
function closeSidebar() {
  sidebar.classList.remove('is-open');
  backdrop.classList.remove('is-visible');
}

document.getElementById('menu-toggle')?.addEventListener('click', openSidebar);
document.getElementById('sidebar-close')?.addEventListener('click', closeSidebar);
backdrop?.addEventListener('click', closeSidebar);
