(function(){
  'use strict';
  var table=document.getElementById('pozosFallaGrid');
  if(!table||!table.tHead||!table.tHead.rows.length)return;
  var header=table.tHead.rows[0];
  function alignFilters(){
    table.style.setProperty('--pf-header-height',Math.ceil(header.getBoundingClientRect().height)+'px');
  }
  if(typeof ResizeObserver!=='undefined')new ResizeObserver(alignFilters).observe(header);
  window.addEventListener('resize',alignFilters);
  window.addEventListener('clear-grid-visibility-changed',alignFilters);
  alignFilters();
})();
