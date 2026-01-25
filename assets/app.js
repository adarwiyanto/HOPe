(function(){
  const btn = document.querySelector('[data-toggle-sidebar]');
  const sidebar = document.querySelector('.sidebar');
  if (btn && sidebar){
    btn.addEventListener('click', () => {
      sidebar.classList.toggle('collapsed');
      localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1':'0');
    });
    if (localStorage.getItem('sidebar_collapsed') === '1') sidebar.classList.add('collapsed');
  }

  document.querySelectorAll('[data-toggle-submenu]').forEach(b=>{
    b.addEventListener('click', ()=>{
      const sel = b.getAttribute('data-toggle-submenu');
      const target = sel ? document.querySelector(sel) : null;
      if (!target) return;
      target.classList.toggle('open');
    });
  });
})();
