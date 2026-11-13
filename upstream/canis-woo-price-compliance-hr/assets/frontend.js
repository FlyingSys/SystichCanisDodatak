(function($){
  'use strict';
  $(function(){
    var $form = $('.variations_form');
    if (!$form.length) return;
    var $block = $('.cwpc-price-compliance').not('.cwpc-loop').first();
    if (!$block.length) return;
    var originalAnchor = $block.find('.cwpc-anchor-slot').html();
    var originalLowest = $block.find('.cwpc-lowest-slot').html();

    $form.on('found_variation', function(event, variation){
      if (typeof variation.cwpc_reference_html !== 'undefined') {
        $block.find('.cwpc-anchor-slot').html(variation.cwpc_reference_html || '');
      }
      if (typeof variation.cwpc_lowest_html !== 'undefined') {
        $block.find('.cwpc-lowest-slot').html(variation.cwpc_lowest_html || '');
      }
    });

    $form.on('reset_data hide_variation', function(){
      $block.find('.cwpc-anchor-slot').html(originalAnchor || '');
      $block.find('.cwpc-lowest-slot').html(originalLowest || '');
    });
  });
})(jQuery);
