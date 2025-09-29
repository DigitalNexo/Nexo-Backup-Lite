(function($){
  let jobId = null;
  let ticking = false;

  function ui(state){
    const $bar = $('#nexo-progress-bar');
    const $txt = $('#nexo-progress-text');
    const $det = $('#nexo-progress-detail');

    const pct = Math.min(100, Math.max(0, Math.round(state.progress || 0)));
    $bar.css('width', pct + '%').attr('aria-valuenow', pct);
    $txt.text(pct + '%');
    $det.text(state.message || '');

    if(state.status === 'done'){
      $('#nexo-start').prop('disabled', false);
      $('#nexo-cancel').prop('disabled', true);
      ticking = false;
    } else if(state.status === 'error' || state.status === 'canceled'){
      $('#nexo-start').prop('disabled', false);
      $('#nexo-cancel').prop('disabled', true);
      ticking = false;
    } else {
      $('#nexo-start').prop('disabled', true);
      $('#nexo-cancel').prop('disabled', false);
    }
  }

  function poll(){
    if(!jobId || !ticking) return;
    $.post(ajaxurl, {
      action: 'nexo_backup_status',
      _ajax_nonce: NexoBackupLite.nonce,
      job_id: jobId
    }).done(function(res){
      if(!res || !res.success) return;
      ui(res.data);
      if(res.data.status === 'running'){
        // pedir un tick de trabajo
        tick();
      }
    });
  }

  function tick(){
    if(!jobId) return;
    $.post(ajaxurl, {
      action: 'nexo_backup_tick',
      _ajax_nonce: NexoBackupLite.nonce,
      job_id: jobId
    }).done(function(res){
      if(!res || !res.success) return;
      ui(res.data);
      if(res.data.status === 'running'){
        setTimeout(poll, 400); // sigue
      }
    });
  }

  $('#nexo-start').on('click', function(e){
    e.preventDefault();
    if(ticking) return;
    $.post(ajaxurl, { action:'nexo_backup_start', _ajax_nonce: NexoBackupLite.nonce })
      .done(function(res){
        if(!res || !res.success){ alert('No se pudo iniciar'); return; }
        jobId = res.data.job_id;
        ticking = true;
        ui(res.data);
        poll();
      });
  });

  $('#nexo-cancel').on('click', function(e){
    e.preventDefault();
    if(!jobId) return;
    $.post(ajaxurl, { action:'nexo_backup_cancel', _ajax_nonce: NexoBackupLite.nonce, job_id: jobId })
      .done(function(res){
        if(res && res.success){
          ticking = false;
          ui(res.data);
        }
      });
  });

})(jQuery);