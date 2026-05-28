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

        $('.egentify-disconnect').on('click', function (e) {
            var message = $(this).data('confirm');
            if (message && !window.confirm(message)) {
                e.preventDefault();
            }
        });
    });
}(jQuery));
