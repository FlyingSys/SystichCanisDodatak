(function($){
  'use strict';

  function runBatch(action, $button, $progress, $status) {
    var offset = 0;
    $button.prop('disabled', true);
    $status.text('Pokretanje…');
    function next() {
      $.post(CWPCAdmin.ajax_url, {
        action: action,
        nonce: CWPCAdmin.nonce,
        offset: offset
      }).done(function(resp){
        if (!resp || !resp.success) {
          $status.text((resp && resp.data && resp.data.message) ? resp.data.message : 'Greška.');
          $button.prop('disabled', false);
          return;
        }
        var d = resp.data;
        var pct = d.total ? Math.min(100, Math.round((d.processed_total / d.total) * 100)) : 100;
        $progress.find('span').css('width', pct + '%');
        $status.text(d.message || (pct + '%'));
        if (!d.done) {
          offset = d.next_offset;
          next();
        } else {
          $button.prop('disabled', false);
          if (d.reload) window.location.reload();
        }
      }).fail(function(){
        $status.text('AJAX greška.');
        $button.prop('disabled', false);
      });
    }
    next();
  }

  $(document).on('click', '#cwpc-scan-now', function(e){
    e.preventDefault();
    runBatch('cwpc_scan_batch', $(this), $('#cwpc-scan-progress'), $('#cwpc-scan-status'));
  });

  $(document).on('click', '#cwpc-build-snapshots', function(e){
    e.preventDefault();
    runBatch('cwpc_snapshot_batch', $(this), $('#cwpc-snapshot-progress'), $('#cwpc-snapshot-status'));
  });

  $(document).on('click', '#cwpc-add-rule', function(e){
    e.preventDefault();
    var html = $('#cwpc-rule-template').html();
    if (!html) return;
    html = html.replace(/__INDEX__/g, Date.now());
    $('#cwpc-rules-body').append(html);
  });

  $(document).on('click', '.cwpc-remove-rule', function(e){
    e.preventDefault();
    $(this).closest('tr').remove();
  });
})(jQuery);
