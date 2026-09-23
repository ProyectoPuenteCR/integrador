/* CLEAR — Gestión de Telemetría */
(function(){
  'use strict';
  function cssVar(name,fallback){var v=getComputedStyle(document.documentElement).getPropertyValue(name);return(v&&v.trim())||fallback;}

  var cfg=window.CLEAR_GT||{};
  var canvas=document.getElementById('gtEvolutionChart');
  if(canvas&&window.Chart){
    var red=cssVar('--red','#e63329'),green=cssVar('--green','#0f8a5f'),amber=cssVar('--amber','#d98a1a');
    var mut=cssVar('--text-mut','#8a99a8'),line=cssVar('--line-mid','#e2e9ee');
    new Chart(canvas,{
      type:'line',
      data:{labels:cfg.labels||[],datasets:[
        {label:'Pozos sin telemetría',data:cfg.counts||[],borderColor:red,backgroundColor:red,pointRadius:4,tension:.25,spanGaps:true},
        {label:'Normalizados',data:cfg.normalized||[],borderColor:green,backgroundColor:green,pointRadius:4,tension:.25,spanGaps:true},
        {label:'Nuevos sin telemetría',data:cfg.newWells||[],borderColor:amber,backgroundColor:amber,borderDash:[5,4],pointRadius:3,tension:.25,spanGaps:true}
      ]},
      options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{
        legend:{position:'bottom',labels:{color:mut,usePointStyle:true,boxWidth:10}},
        tooltip:{callbacks:{label:function(item){return' '+item.dataset.label+': '+(item.raw===null||item.raw===undefined?'sin captura':item.raw);}}}
      },scales:{x:{grid:{display:false},ticks:{color:mut}},y:{beginAtZero:true,grid:{color:line},ticks:{color:mut,precision:0},title:{display:true,text:'Cantidad de pozos',color:mut}}}}
    });
  }

  function cellValue(row,index){
    var cell=row.cells[index];
    return cell?(cell.getAttribute('data-v')||cell.textContent||'').trim():'';
  }

  document.querySelectorAll('.gtTable').forEach(function(table){
    var tbody=table.tBodies[0]; if(!tbody)return;
    var rows=Array.prototype.slice.call(tbody.rows).filter(function(r){return r.cells.length>1;});
    table.querySelectorAll('select[data-gt-filter]').forEach(function(select){
      var index=parseInt(select.getAttribute('data-gt-filter'),10),values={};
      rows.forEach(function(row){var v=cellValue(row,index);if(v!=='')values[v]=true;});
      Object.keys(values).sort(function(a,b){return a.localeCompare(b,'es',{numeric:true});}).forEach(function(v){
        var o=document.createElement('option');o.value=v;o.textContent=v;select.appendChild(o);
      });
    });
    function applyFilters(){
      var active=Array.prototype.slice.call(table.querySelectorAll('[data-gt-filter]')).map(function(el){
        return{index:parseInt(el.getAttribute('data-gt-filter'),10),value:el.value.trim().toLowerCase(),exact:el.tagName==='SELECT'};
      }).filter(function(f){return f.value!=='';});
      rows.forEach(function(row){
        row.hidden=!active.every(function(f){var v=cellValue(row,f.index).toLowerCase();return f.exact?v===f.value:v.indexOf(f.value)>=0;});
      });
    }
    table.querySelectorAll('[data-gt-filter]').forEach(function(el){el.addEventListener(el.tagName==='SELECT'?'change':'input',applyFilters);});
    var sort={index:-1,dir:1};
    table.querySelectorAll('[data-gt-sort]').forEach(function(button){
      button.addEventListener('click',function(){
        var index=parseInt(button.getAttribute('data-gt-sort'),10),numeric=button.getAttribute('data-type')==='number';
        sort.dir=sort.index===index?-sort.dir:1;sort.index=index;
        rows.sort(function(a,b){
          var x=cellValue(a,index),y=cellValue(b,index),r;
          if(numeric){r=(parseFloat(x)||0)-(parseFloat(y)||0);}else{r=x.localeCompare(y,'es',{numeric:true,sensitivity:'base'});}
          return r*sort.dir;
        });
        rows.forEach(function(row){tbody.appendChild(row);});
        table.querySelectorAll('[data-gt-sort]').forEach(function(b){var s=b.querySelector('span');if(s)s.textContent='↕';});
        var s=button.querySelector('span');if(s)s.textContent=sort.dir===1?'↑':'↓';
      });
    });
  });

  document.querySelectorAll('[data-gt-export]').forEach(function(button){
    button.addEventListener('click',function(){
      var selector=button.getAttribute('data-gt-export'),table=document.querySelector(selector);if(!table)return;
      var lines=[];
      var header=Array.prototype.slice.call(table.tHead.rows[0].cells).map(function(c){return'"'+String(c.innerText||'').replace(/↕|↑|↓/g,'').trim().replace(/"/g,'""')+'"';});
      lines.push(header.join(';'));
      Array.prototype.slice.call(table.tBodies[0].rows).forEach(function(row){
        if(row.hidden||row.cells.length<2)return;
        lines.push(Array.prototype.slice.call(row.cells).map(function(c){return'"'+String(c.innerText||'').trim().replace(/"/g,'""')+'"';}).join(';'));
      });
      var blob=new Blob(['\uFEFF'+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'}),url=URL.createObjectURL(blob),a=document.createElement('a');
      a.href=url;a.download='gestion_telemetria_'+new Date().toISOString().slice(0,10)+'.csv';document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url);
    });
  });

  document.querySelectorAll('[data-gt-dyna-template]').forEach(function(button){
    button.addEventListener('click',function(){
      var blob=new Blob(['POZO;LINEA_ENERGIA;ALIMENTADOR;SUBESTACION\r\n'],{type:'text/csv;charset=utf-8;'}),url=URL.createObjectURL(blob),a=document.createElement('a');
      a.href=url;a.download='plantilla_dyna_pozos_lineas.csv';document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url);
    });
  });
})();