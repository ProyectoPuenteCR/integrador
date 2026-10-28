(function(){
  'use strict';
  var panel=document.getElementById('sigmaAnalysisAdmin');if(!panel)return;
  var state=document.getElementById('sigmaAnalysisState'),result=document.getElementById('sigmaAnalysisResult'),enable=document.getElementById('sigmaAnalysisEnable'),refresh=document.getElementById('sigmaAnalysisRefresh'),busy=false,current=null;
  function showState(status){current=status;state.textContent=status==='Enabled'?'Habilitado en AF':status==='Disabled'?'Deshabilitado en AF':'Estado no confirmado';enable.hidden=status!=='Disabled';enable.disabled=busy||status!=='Disabled';}
  function loading(active){busy=active;refresh.disabled=active;enable.disabled=active||current!=='Disabled';}
  function request(options){return fetch('tecss_3sigma_analysis_api.php'+(options?'':'?'+new URLSearchParams({pozo:panel.dataset.well}).toString()),options||{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(response){return response.json().catch(function(){throw new Error('Respuesta inválida. Verificá tu sesión y volvé a consultar el estado.');}).then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'AF no confirmó la operación.');return data;});});}
  function check(){if(busy)return;loading(true);result.textContent='Consultando el estado actual de 3Sigma…';request().then(function(data){showState(data.status);result.textContent=data.status==='Enabled'?'OK · El análisis 3Sigma está habilitado en AF.':data.status==='Disabled'?'El análisis 3Sigma está deshabilitado. Podés habilitarlo con el botón.':'AF no devolvió un estado que permita habilitar el análisis.';}).catch(function(error){showState(null);result.textContent=error.message;}).finally(function(){loading(false);});}
  enable.addEventListener('click',function(){
    if(busy||current!=='Disabled')return;
    if(!window.confirm('¿Habilitar el análisis AF «3Sigma» del pozo '+panel.dataset.well+'? El cálculo volverá a ejecutarse. Confirmá para continuar.'))return;
    loading(true);result.textContent='Solicitando habilitación y verificando el resultado en AF…';
    request({method:'POST',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({pozo:panel.dataset.well,csrf:panel.dataset.csrf,confirmed:true})}).then(function(data){if(data.status!=='Enabled')throw new Error('AF no confirmó la habilitación. Usá Verificar estado antes de reintentar.');showState(data.status);result.textContent=data.message||'OK · Análisis habilitado y verificado en AF.';}).catch(function(error){showState(null);result.textContent=error.message+' Usá Verificar estado antes de reintentar.';}).finally(function(){loading(false);});
  });
  refresh.addEventListener('click',check);check();
})();
