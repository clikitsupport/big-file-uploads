jQuery(document).ready(function ($) {
  $('[data-toggle="tooltip"]').tooltip();

  //open modals from link
  $(window.location.hash).modal('show');
  $(".modal").on("hidden.bs.modal", function () { // any time a modal is hidden
    var urlReplace = window.location.toString().split('#', 1)[0];
    history.pushState(null, null, urlReplace); // push url without the hash as new history item
  });

  var bfuStopLoop = false;
  var bfuProcessingLoop = false;
  var bfuLoopErrors = 0;
  var bfuAjaxCall = false;

  //show a confirmation warning if leaving page during a bulk action
  $(window).bind('beforeunload', function () {
    if (bfuProcessingLoop) {
      return bfu_data.strings.leave_confirmation;
    }
  });

  //show an error at top of main settings page
  var showError = function (error_message) {
    $('#bfu-error').text(error_message.substr(0, 200)).show();
    $("html, body").animate({scrollTop: 0}, 1000);
  }

  //process the filescan ajax loop
  var fileScan = function (remaining_dirs) {
    if (bfuStopLoop) {
      bfuStopLoop = false;
      bfuProcessingLoop = false;
      return false;
    }
    bfuProcessingLoop = true;

    var data = {"remaining_dirs": remaining_dirs};
    $.post(ajaxurl + '?action=bfu_file_scan', data, function (json) {
      if (json.success) {
        $('#bfu-scan-storage').text(json.data.file_size);
        $('#bfu-scan-files').text(json.data.file_count);
        $('#bfu-scan-progress').show();
        if (!json.data.is_done) {
          fileScan(json.data.remaining_dirs);
        } else {
          bfuProcessingLoop = false;
          //if they have not dismissed subscribe
          if ( $('#subscribe-modal').length ) {
            $('.modal').modal('hide');
            $('#subscribe-modal').modal({
              backdrop: 'static',
              keyboard: false
            });
          } else {
            location.reload();
          }
          return true;
        }

      } else {
        showError(json.data);
        $('.modal').modal('hide');
      }
    }, 'json').fail(function () {
      showError(bfu_data.strings.ajax_error);
      $('.modal').modal('hide');
    });
  };

  //Scan local files
  $('#scan-modal').on('show.bs.modal', function () {
    $('#bfu-error').hide();
    bfuStopLoop = false;
    fileScan([]);
  }).on('hide.bs.modal', function () {
    bfuStopLoop = true;
    bfuProcessingLoop = false;
  });

  //Make sure scan modal closes
  $('#subscribe-modal').on('shown.bs.modal', function () {
    $('#scan-modal').modal('hide');
  })

  //handle upload limit field MB/GB changes
  $('.bfu-input-limit select').on('change', function () {
    var field = $(this).parents('.bfu-input-limit').children('input');
    if ($(this).val() === 'MB') {
      field.val(Math.round(field.val() * 1024));
    } else {
      field.val((field.val() / 1024).toFixed(1));
    }
  });

  //handle toggle of settings
  function bfu_is_roles($checkbox) {
    if ($checkbox.checked) {
      $('#bfu-settings').addClass('bfu-disabled');
      $('#bfu-settings-roles').removeClass('bfu-disabled');
      $('#bfu-settings input, #bfu-settings select').prop('disabled', true);
      $('#bfu-settings-roles input, #bfu-settings-roles select').prop('disabled', false);
    } else {
      $('#bfu-settings-roles').addClass('bfu-disabled');
      $('#bfu-settings').removeClass('bfu-disabled');
      $('#bfu-settings input, #bfu-settings select').prop('disabled', false);
      $('#bfu-settings-roles input, #bfu-settings-roles select').prop('disabled', true);
    }
  }

  bfu_is_roles($('#customSwitch_role')[0]); //init
  //oon toggle change
  $('#customSwitch_role').on('change', function () {
    bfu_is_roles(this);
  });

  $('#bfu-view-results, #subscribe-modal .bfu-subscribe__close').on('click', function () {
    $.get(ajaxurl + '?action=bfu_subscribe_dismiss', function( data ) {
      location.reload();
    });
  });

  var mc1Submitted = false;
  $('#mc-embedded-subscribe-form').on('submit reset', function (event) {
    console.log(event);
    if ("submit" === event.type) {
      mc1Submitted = true;
    } else if ( "reset" === event.type && mc1Submitted ) {
      console.log('success');
      $('#bfu-subscribe-button').prop('disabled', true);
      $.get(ajaxurl + '?action=bfu_subscribe_dismiss', function( data ) {
        console.log(data);
        location.reload();
      });
    }
  });

  //Charts
  var sizelabel = function (tooltipItem, data) {
    var label = ' ' + data.labels[tooltipItem.index] || '';
    return label;
  };

  window.onload = function () {
    var pie1 = document.getElementById('bfu-local-pie');
    if (pie1) {

      var config_local = {
        type: 'pie',
        data: bfu_data.local_types,
        options: {
          responsive: true,
          legend: false,
          tooltips: {
            callbacks: {
              label: sizelabel
            },
            backgroundColor: '#F1F1F1',
            bodyFontColor: '#2A2A2A',
          },
          title: {
            display: true,
            position: 'bottom',
            fontSize: 18,
            fontStyle: 'normal',
            text: bfu_data.local_types.total
          }
        }
      };

      var ctx = pie1.getContext('2d');
      window.myPieLocal = new Chart(ctx, config_local);
    }
  }

});
