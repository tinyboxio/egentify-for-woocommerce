(function ($) {
    'use strict';

    $(function () {
        if ($.fn.wpColorPicker) {
            $('.egentify-color-picker').wpColorPicker();
        }

        var $toggle = $('#egentify-toggle-manual-config');
        var $panel = $('#egentify-manual-config');
        $toggle.on('click', function () {
            var isOpen = $panel.toggleClass('egentify-collapsible__panel--open')
                .hasClass('egentify-collapsible__panel--open');
            $toggle.toggleClass('egentify-collapsible__toggle--open', isOpen);
        });

        // Count Unicode code points, so the limit never splits an emoji.
        $('[data-tooltip-limit]').on('input compositionend', function (event) {
            if (event.originalEvent && event.originalEvent.isComposing) return;
            var limit = Number(this.getAttribute('data-tooltip-limit'));
            this.value = Array.from(this.value).slice(0, limit).join('');
        });

        $('.egentify-disconnect').on('click', function (e) {
            var message = $(this).data('confirm');
            if (message && !window.confirm(message)) {
                e.preventDefault();
            }
        });
    });
}(jQuery));
