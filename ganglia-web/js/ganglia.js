$(function(){
  // Ensure that the window has a unique name
  if ((window.name == null) || window.name == "") {
    var d = new Date();
    window.name = d.getTime();
  }

  // Follow tab's URL instead of loading its content via ajax
  $("#tabs").tabs();
  // Restore previously selected tab
  var selected_tab = $.cookie("ganglia-selected-tab-" + window.name);
  if ((selected_tab != null) && (selected_tab.length > 0)) {
    try {
      var tab_index = parseInt(selected_tab, 10);
      if (!isNaN(tab_index) && (tab_index >= 0)) {
        //alert("ganglia-selected-tab: " + tab_index);
        $("#tabs").tabs("select", tab_index);
        switch (tab_index) {
          case 2:
            getViewsContent();
            break;
          case 4:
            autoRotationChooser();
            break;
        }
      }
    } catch (err) {
      try {
          alert("Error(ganglia.js): Unable to select tab: " + 
                tab_index + ". " + err.getDescription());
      } catch (err) {
          // If we can't even show the error, fail silently.
      }
    }
  }

  $("#tabs").bind("tabsselect", function(event, ui) {
    // Store selected tab in a session cookie
    $.cookie("ganglia-selected-tab-" + window.name, ui.index);
  });

  $( "#range_menu" ).buttonset();
  $( "#sort_menu" ).buttonset();

  jQuery('#metric-search input[name="q"]').liveSearch({url: 'search.php?q=', typeDelay: 500});

  $( "#datepicker-cs" ).datepicker({
	  showOn: "button",
	  buttonImage: "img/calendar.gif",
	  buttonImageOnly: true
  });
  $( "#datepicker-ce" ).datepicker({
	  showOn: "button",
	  buttonImage: "img/calendar.gif",
	  buttonImageOnly: true
  });

  $( "#create-new-view-dialog" ).dialog({
    autoOpen: false,
    height: 200,
    width: 350,
    modal: true,
    close: function() {
      getViewsContent();
      $("#create-new-view-layer").toggle();
      $("#create-new-view-confirmation-layer").html("");
    }
  });

  $( "#metric-actions-dialog" ).dialog({
    autoOpen: false,
    height: 250,
    width: 450,
    modal: true
  });

});

function selectTab(tab_index) {
  $("#tabs").tabs("select", tab_index);
}

function viewId(view_name) {
  return "v_" + view_name.replace(/[^a-zA-Z0-9_]/g, "_");
}

function highlightSelectedView(view_name) {
  $("#navlist a").css('background-color', '#FFFFFF');	
  $("#" + viewId(view_name)).css('background-color', 'rgb(238,238,238)');
}

function selectView(view_name) {
  highlightSelectedView(view_name);
  $.cookie('ganglia-selected-view-' + window.name, view_name); 
  var range = $.cookie('ganglia-view-range-' + window.name);
  if (range == null)
    range = '1hour';
  getViewsContentJustGraphs(view_name, range, '', '');
}

function getViewsContent() {
  $.get('views.php', "" , function(data) {
    $("#tabs-views-content").html('<img src="img/spinner.gif">');
    $("#tabs-views-content").html(data);
    $("#create_view_button")
      .button()
      .click(function() {
	$( "#create-new-view-dialog" ).dialog( "open" );
      });;
    $( "#view_range_chooser" ).buttonset();

    // Restore previously selected view
    var view_name = document.getElementById('view_name');
    var selected_view = $.cookie("ganglia-selected-view-" + window.name);
    if (selected_view != null) {
        view_name.value = selected_view;
	var range = $.cookie("ganglia-view-range-" + window.name);
	if (range == null)
          range = "hour";
	$("#view-range-"+range).click();
    } else
      view_name.value = "default";
    highlightSelectedView(view_name.value);
  });
  return false;
}

// This one avoids 
function getViewsContentJustGraphs(viewName,range, cs, ce) {
    $("#view_graphs").html('<img src="img/spinner.gif">');
    $.get('views.php', "view_name=" + viewName + "&just_graphs=1&r=" + range + "&cs=" + cs + "&ce=" + ce, function(data) {
	$("#view_graphs").html(data);
	document.getElementById('view_name').value = viewName;
     });
    return false;
}

function createView() {
  $("#create-new-view-confirmation-layer").html('<img src="img/spinner.gif">');
  $.get('views.php', $("#create_view_form").serialize() , function(data) {
    $("#create-new-view-layer").toggle();
    $("#create-new-view-confirmation-layer").html(data);
  });
  return false;
}

function addItemToView() {
  $.get('views.php', $("#add_metric_to_view_form").serialize() + "&add_to_view=1" , function(data) {
      $("#metric-actions-dialog-content").html('<img src="img/spinner.gif">');
      $("#metric-actions-dialog-content").html(data);
  });
  return false;  
}
function metricActions(host_name,metric_name,type,graphargs) {
    $( "#metric-actions-dialog" ).dialog( "open" );
    $("#metric-actions-dialog-content").html('<img src="img/spinner.gif">');
    $.get('actions.php', "action=show_views&host_name=" + host_name + "&metric_name=" + metric_name + "&type=" + type + graphargs, function(data) {
      $("#metric-actions-dialog-content").html(data);
     });
    return false;
}

function createAggregateGraph() {
  if ( $('#hreg').val() == "" ||  $('#metric_chooser').val() == "" ) {
      alert("Host regular expression and metric name can't be blank");
      return false;
  }
  $("#aggregate_graph_display").html('<img src="img/spinner.gif">');
  $.get('graph_all_periods.php', $("#aggregate_graph_form").serialize() + "&aggregate=1&embed=1" , function(data) {
    $("#aggregate_graph_display").html(data);
  });
  return false;
}

function metricActionsAggregateGraph() {
    $( "#metric-actions-dialog" ).dialog( "open" );
    $("#metric-actions-dialog-content").html('<img src="img/spinner.gif">');
    $.get('actions.php', "action=show_views&aggregate=1&" + $("#aggregate_graph_form").serialize(), function(data) {
      $("#metric-actions-dialog-content").html(data);
     });
    return false;
}


function autoRotationChooser() {
  $("#tabs-autorotation-chooser").html('<img src="img/spinner.gif">');
  $.get('autorotation.php', "" , function(data) {
      $("#tabs-autorotation-chooser").html(data);
  });
}
function updateViewTimeRange() {
  alert("Not implemented yet");
}

function ganglia_submit(clearonly) {
  document.getElementById("datepicker-cs").value = "";
  document.getElementById("datepicker-ce").value = "";
  if (! clearonly)
    document.ganglia_form.submit();
}
