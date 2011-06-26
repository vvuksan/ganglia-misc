<?php
/* $Id: graph.php 2591 2011-05-10 13:42:54Z vvuksan $ */
include_once "./eval_conf.php";
include_once "./get_context.php";
include_once "./functions.php";

$ganglia_dir = dirname(__FILE__);

# RFM - Added all the isset() tests to eliminate "undefined index"
# messages in ssl_error_log.

# Graph specific variables
# ATD - No need for escapeshellcmd or rawurldecode on $size or $graph.  Not used directly in rrdtool calls.
$size = isset($_GET["z"]) && in_array( $_GET[ 'z' ], $conf['graph_sizes_keys'] )
             ? $_GET["z"]
             : NULL;

# If graph arg is not specified default to metric
$graph      = isset($_GET["g"])  ?  sanitize ( $_GET["g"] )   : "metric";
$grid       = isset($_GET["G"])  ?  sanitize ( $_GET["G"] )   : NULL;
$self       = isset($_GET["me"]) ?  sanitize ( $_GET["me"] )  : NULL;
$vlabel     = isset($_GET["vl"]) ?  sanitize ( $_GET["vl"] )  : NULL;

$value      = isset($_GET["v"])  ?  sanitize ( $_GET["v"] )   : NULL;

$metric_name = isset($_GET["m"])  ?  sanitize ( $_GET["m"] )   : NULL;

$max        = isset($_GET["x"])  ?  clean_number ( sanitize ($_GET["x"] ) ) : NULL;
$min        = isset($_GET["n"])  ?  clean_number ( sanitize ($_GET["n"] ) ) : NULL;
$sourcetime = isset($_GET["st"]) ?  clean_number ( sanitize( $_GET["st"] ) ) : NULL;

$load_color = isset($_GET["l"]) && is_valid_hex_color( rawurldecode( $_GET[ 'l' ] ) )
                                 ?  sanitize ( $_GET["l"] )   : NULL;

$summary    = isset( $_GET["su"] )    ? 1 : 0;
$debug      = isset( $_GET['debug'] ) ? clean_number ( sanitize( $_GET["debug"] ) ) : 0;
// 
$command    = '';
$graphite_url = '';

$user['json_output'] = isset($_GET["json"]) ? 1 : NULL; 
$user['csv_output'] = isset($_GET["csv"]) ? 1 : NULL; 
$user['flot_output'] = isset($_GET["flot"]) ? 1 : NULL; 


// Get hostname
$raw_host = isset($_GET["h"])  ?  sanitize ( $_GET["h"]  )   : "__SummaryInfo__";  

// For graphite purposes we need to replace all dots with underscore. dot  is
// separates subtrees in graphite
$host = str_replace(".","_", $raw_host);

# Assumes we have a $start variable (set in get_context.php).
# $conf['graph_sizes'] and $conf['graph_sizes_keys'] defined in conf.php.  Add custom sizes there.
$size = in_array( $size, $conf['graph_sizes_keys'] ) ? $size : 'default';

if ( isset($_GET['height'] ) ) 
  $height = $_GET['height'];
else 
  $height  = $conf['graph_sizes'][ $size ][ 'height' ];

if ( isset($_GET['width'] ) ) 
  $width =  $_GET['width'];
else
  $width = $conf['graph_sizes'][ $size ][ 'width' ];

#$height  = $conf['graph_sizes'][ $size ][ 'height' ];
#$width   = $conf['graph_sizes'][ $size ][ 'width' ];
$fudge_0 = $conf['graph_sizes'][ $size ][ 'fudge_0' ];
$fudge_1 = $conf['graph_sizes'][ $size ][ 'fudge_1' ];
$fudge_2 = $conf['graph_sizes'][ $size ][ 'fudge_2' ];

///////////////////////////////////////////////////////////////////////////
// Set some variables depending on the context. Context is set in
// get_context.php
///////////////////////////////////////////////////////////////////////////
switch ($context)
{
  case "meta":
    $rrd_dir = $conf['rrds'] . "/__SummaryInfo__";
    $rrd_graphite_link = $conf['graphite_rrd_dir'] . "/__SummaryInfo__";
    $title = "$self Grid";
    break;
  case "grid":
    $rrd_dir = $conf['rrds'] . "/$grid/__SummaryInfo__";
    $rrd_graphite_link = $conf['graphite_rrd_dir'] . "/$grid/__SummaryInfo__";
    if (preg_match('/grid/i', $gridname))
        $title  = $gridname;
    else
        $title  = "$gridname Grid";
    break;
  case "cluster":
    $rrd_dir = $conf['rrds'] . "/$clustername/__SummaryInfo__";
    $rrd_graphite_link = $conf['graphite_rrd_dir'] . "/$clustername/__SummaryInfo__";
    if (preg_match('/cluster/i', $clustername))
        $title  = $clustername;
    else
        $title  = "$clustername Cluster";
    break;
  case "host":
    $rrd_dir = $conf['rrds'] . "/$clustername/$raw_host";
    $rrd_graphite_link = $conf['graphite_rrd_dir'] . "/" . $clustername . "/" . $host;
    // Add hostname to report graphs' title in host view
    if ($graph != 'metric')
       if ($conf['strip_domainname'])
          $title = strip_domainname($raw_host);
       else
          $title = $raw_host;
    break;
  default:
    $title = $clustername;
    exit;
}


$resource = GangliaAcl::ALL_CLUSTERS;
if( $context == "grid" ) {
  $resource = $grid;
} else if ( $context == "cluster" || $context == "host" ) {
  $resource = $clustername; 
}
if( ! checkAccess( $resource, GangliaAcl::VIEW, $conf ) ) {
  header( "HTTP/1.1 403 Access Denied" );
  header ("Content-type: image/jpg");
  echo file_get_contents( $ganglia_dir.'/img/access-denied.jpg');
  die();
}

if ($cs)
    $start = $cs;
if ($ce)
    $end = $ce;

# Set some standard defaults that don't need to change much
$rrdtool_graph = array(
    'start'  => $start,
    'end'    => $end,
    'width'  => $width,
    'height' => $height,
);

# automatically strip domainname from small graphs where it won't fit
if ($size == "small") {
    $conf['strip_domainname'] = true;
    # Let load coloring work for little reports in the host list.
    if (! isset($subtitle) and $load_color)
        $rrdtool_graph['color'] = "BACK#'$load_color'";
}

if ($debug) {
    error_log("Graph [$graph] in context [$context]");
}

/* If we have $graph, then a specific report was requested, such as "network_report" or
 * "cpu_report.  These graphs usually have some special logic and custom handling required,
 * instead of simply plotting a single metric.  If $graph is not set, then we are (hopefully),
 * plotting a single metric, and will use the commands in the metric.php file.
 *
 * With modular graphs, we look for a "${graph}.php" file, and if it exists, we
 * source it, and call a pre-defined function name.  The current scheme for the function
 * names is:   'graph_' + <name_of_report>.  So a 'cpu_report' would call graph_cpu_report(),
 * which would be found in the cpu_report.php file.
 *
 * These functions take the $rrdtool_graph array as an argument.  This variable is
 * PASSED BY REFERENCE, and will be modified by the various functions.  Each key/value
 * pair represents an option/argument, as passed to the rrdtool program.  Thus,
 * $rrdtool_graph['title'] will refer to the --title option for rrdtool, and pass the array
 * value accordingly.
 *
 * There are two exceptions to:  the 'extras' and 'series' keys in $rrdtool_graph.  These are
 * assigned to $extras and $series respectively, and are treated specially.  $series will contain
 * the various DEF, CDEF, RULE, LINE, AREA, etc statements that actually plot the charts.  The
 * rrdtool program requires that this come *last* in the argument string; we make sure that it
 * is put in it's proper place.  The $extras variable is used for other arguemnts that may not
 * fit nicely for other reasons.  Complicated requests for --color, or adding --ridgid, for example.
 * It is simply a way for the graph writer to add an arbitrary options when calling rrdtool, and to
 * forcibly override other settings, since rrdtool will use the last version of an option passed.
 * (For example, if you call 'rrdtool' with two --title statements, the second one will be used.)
 *
 * See ${conf['graphdir']}/sample.php for more documentation, and details on the
 * common variables passed and used.
 */

// Calculate time range.
if ($sourcetime)
   {
      $end = $sourcetime;
      # Get_context makes start negative.
      $start = $sourcetime + $start;
   }
// Fix from Phil Radden, but step is not always 15 anymore.
if ($range == "month")
   $rrdtool_graph['end'] = floor($rrdtool_graph['end'] / 672) * 672;

// Are we generating aggregate graphs
if ( isset( $_GET["aggregate"] ) && $_GET['aggregate'] == 1 ) {
    
  // Set start time
  $start = time() + $start;

  // If graph type is not specified default to line graph
  if ( isset($_GET["gtype"]) && in_array($_GET["gtype"], array("stack","line") )  ) 
      $graph_type = $_GET["gtype"];
  else
      $graph_type = "line";

  // If line width not specified default to 2
  if ( isset($_GET["lw"]) && in_array($_GET["lw"], array("1","2", "3") )  ) 
      $line_width = $_GET["lw"];
  else
      $line_width = "2";

  // Set up 
  $graph_config["report_name"] = $metric_name;
  $graph_config["report_type"] = "standard";
  $graph_config["title"] = $metric_name;
  $graph_config["vertical_label"] = $vlabel;

  $color_count = sizeof($conf['graph_colors']);

  // Load the host cache
  require_once('./cache.php');

  $counter = 0;

  // Find matching hosts    
  foreach ( $_GET['hreg'] as $key => $query ) {
    foreach ( $index_array['hosts'] as $key => $host_name ) {
      if ( preg_match("/$query/i", $host_name ) ) {
        // We can have same hostname in multiple clusters
        $matches[] = $host_name . "|" . $index_array['cluster'][$host_name]; 
      }
    }
  } 

  if( isset($_GET['mreg'])){
    // Find matching metrics
    foreach ( $_GET['mreg'] as $key => $query ) {
      foreach ( $index_array['metrics'] as $key => $m_name ) {
        if ( preg_match("/$query/i", $key ) ) {
          $metric_matches[] = $key;
        }
      }
    }
  }
  
  if( isset($metric_matches)){
    $metric_matches_unique = array_unique($metric_matches);
  }
  else{
    $metric_matches_unique = array($metric_name);
  }
  if( !isset($metric_name)){
    if( sizeof($metric_matches_unique)==1){
      $graph_config["report_name"]=sanitize($metric_matches_unique[0]);
      $graph_config["title"]=sanitize($metric_matches_unique[0]);
    }
    else{
      $graph_config["report_name"]=isset($_GET["mreg"])  ?  sanitize(implode($_GET["mreg"]))   : NULL;
      $graph_config["title"]=isset($_GET["mreg"])  ?  sanitize(implode($_GET["mreg"]))   : NULL;
    }
  }

  // Reset graph title 
  if ( isset($_GET['title']) && $_GET['title'] != "") {
    unset($title);
    $graph_config["title"] = sanitize($_GET['title']);
  } else {
    $title = "Aggregate";
  }

  if ( isset($matches)) {

    $matches_unique = array_unique($matches);

    // Create graph_config series from matched hosts and metrics
    foreach ( $matches_unique as $key => $host_cluster ) {

      $out = explode("|", $host_cluster);

      $host_name = $out[0];
      $cluster_name = $out[1];

      foreach ( $metric_matches_unique as $key => $m_name ) {

        // We need to cycle the available colors
        $color_index = $counter % $color_count;

        // next loop if there is no metric for this hostname
        if( !in_array($host_name, $index_array['metrics'][$m_name]))
          continue;

        $label = '';
        if ($conf['strip_domainname'] == True )
          $label = strip_domainname($host_name);
        else
          $label = $host_name;
        if( isset($metric_matches) and sizeof($metric_matches_unique)>1)
          $label.=" $m_name";

        $graph_config['series'][] = array ( "hostname" => $host_name , "clustername" => $cluster_name,
          "metric" => $m_name,  "color" => $conf['graph_colors'][$color_index], "label" => $label, "line_width" => $line_width, "type" => $graph_type);

        $counter++;

      }
    }

  }
  #print "<PRE>"; print_r($graph_config); exit(1);

}

//////////////////////////////////////////////////////////////////////////////
// Check what graph engine we are using
//////////////////////////////////////////////////////////////////////////////
switch ( $conf['graph_engine'] ) {
  case "flot":
  case "rrdtool":
    
    if ( ! isset($graph_config) ) {
	if ( ($graph == "metric") &&
             isset($_GET['title']) && 
             $_GET['title'] !== '')
	  $metrictitle = sanitize($_GET['title']);
      $php_report_file = $conf['graphdir'] . "/" . $graph . ".php";
      $json_report_file = $conf['graphdir'] . "/" . $graph . ".json";
      if( is_file( $php_report_file ) ) {
        include_once $php_report_file;
        $graph_function = "graph_${graph}";
        $graph_function( $rrdtool_graph );  // Pass by reference call, $rrdtool_graph modified inplace
      } else if ( is_file( $json_report_file ) ) {
        $graph_config = json_decode( file_get_contents( $json_report_file ), TRUE );

        # We need to add hostname and clustername if it's not specified
        foreach ( $graph_config['series'] as $index => $item ) {
          if ( ! isset($graph_config['series'][$index]['hostname'])) {
            $graph_config['series'][$index]['hostname'] = $raw_host;
            if (isset($grid))
               $graph_config['series'][$index]['clustername'] = $grid;
            else
               $graph_config['series'][$index]['clustername'] = $clustername;
          }
        }

        build_rrdtool_args_from_json ( $rrdtool_graph, $graph_config );
      }

    } else {
        
        build_rrdtool_args_from_json ( $rrdtool_graph, $graph_config );

    }
  
    // We must have a 'series' value, or this is all for naught
    if (!array_key_exists('series', $rrdtool_graph) || !strlen($rrdtool_graph['series']) ) {
        error_log("\$series invalid for this graph request ".$_SERVER['PHP_SELF']);
        exit();
    }
  
    # Make small graphs (host list) cleaner by removing the too-big
    # legend: it is displayed above on larger cluster summary graphs.
    if ($size == "small" and ! isset($subtitle))
        $rrdtool_graph['extras'] = "-g";

    # add slope-mode if rrdtool_slope_mode is set
    if (isset($conf['rrdtool_slope_mode']) && $conf['rrdtool_slope_mode'] == True)
        $rrdtool_graph['slope-mode'] = '';
  
    if (isset($rrdtool_graph['title']))
       if (isset($title))
          $rrdtool_graph['title'] = $title . " " . $rrdtool_graph['title'] . " last $range";

    $command = $conf['rrdtool'] . " graph - $rrd_options ";
  
    // The order of the other arguments isn't important, except for the
    // 'extras' and 'series' values.  These two require special handling.
    // Otherwise, we just loop over them later, and tack $extras and
    // $series onto the end of the command.
    foreach (array_keys ($rrdtool_graph) as $key) {
      if (preg_match('/extras|series/', $key))
          continue;

      $value = $rrdtool_graph[$key];

      if (preg_match('/\W/', $value)) {
          //more than alphanumerics in value, so quote it
          $value = "'$value'";
      }
      $command .= " --$key $value";
    }
  
    // And finish up with the two variables that need special handling.
    // See above for how these are created
    $command .= array_key_exists('extras', $rrdtool_graph) ? ' '.$rrdtool_graph['extras'].' ' : '';
    $command .= " $rrdtool_graph[series]";
    break;

  //////////////////////////////////////////////////////////////////////////////////////////////////
  // USING Graphite
  //////////////////////////////////////////////////////////////////////////////////////////////////
  case "graphite":  
    // Check whether the link exists from Ganglia RRD tree to the graphite storage/rrd_dir
    // area
    if ( ! is_link($rrd_graphite_link) ) {
      // Does the directory exist for the cluster. If not create it
      if ( ! is_dir ($conf['graphite_rrd_dir'] . "/" . str_replace(" ", "_", $clustername)) )
        mkdir ( $conf['graphite_rrd_dir'] . "/" . str_replace(" ", "_", $clustername ));
      symlink($rrd_dir, str_replace(" ", "_", $rrd_graphite_link));
    }
  
    // Generate host cluster string
    if ( isset($clustername) ) {
      $host_cluster = str_replace(" ", "_", $clustername) . "." . $host;
    } else {
      $host_cluster = $host;
    }
  
    $height += 70;
  
    if ($size == "small") {
      $width += 20;
    }
  
  //  $title = urlencode($rrdtool_graph["title"]);
  
    // If graph_config is already set we can use it immediately
    if ( isset($graph_config) ) {

      $target = build_graphite_series( $graph_config, "" );

    } else {

      if ( isset($_GET['g'])) {
    // if it's a report increase the height for additional 30 pixels
    $height += 40;
    
    $report_name = sanitize($_GET['g']);
    
    $report_definition_file = $conf['ganglia_dir'] . "/graph.d/" . $report_name . ".json";
    // Check whether report is defined in graph.d directory
    if ( is_file($report_definition_file) ) {
      $graph_config = json_decode(file_get_contents($report_definition_file), TRUE);
    } else {
      error_log("There is JSON config file specifying $report_name.");
      exit(1);
    }
    
    if ( isset($graph_config) ) {
      switch ( $graph_config["report_type"] ) {
        case "template":
          $target = str_replace("HOST_CLUSTER", $host_cluster, $graph_config["graphite"]);
          break;
    
        case "standard":
          $target = build_graphite_series( $graph_config, $host_cluster );
          break;
    
        default:
          error_log("No valid report_type specified in the $report_name definition.");
          break;
      }
    
      $title = $graph_config['title'];
    } else {
      error_log("Configuration file to $report_name exists however it doesn't appear it's a valid JSON file");
      exit(1);
    }
      } else {
    // It's a simple metric graph
    $target = "target=$host_cluster.$metric_name.sum&hideLegend=true&vtitle=" . urlencode($vlabel) . "&areaMode=all";
    $title = " ";
      }

    } // end of if ( ! isset($graph_config) ) {
    
    $graphite_url = $conf['graphite_url_base'] . "?width=$width&height=$height&" . $target . "&from=" . $start . "&yMin=0&bgcolor=FFFFFF&fgcolor=000000&title=" . urlencode($title . " last " . $range);
    break;

} // end of switch ( $conf['graph_engine'])

// Output to JSON
if ( $user['json_output'] || $user['csv_output'] || $user['flot_output'] ) {

  $rrdtool_graph_args = "";

  // First find RRDtool DEFs by parsing $rrdtool_graph['series']
  preg_match_all("| DEF:(.*):AVERAGE|U", " " . $rrdtool_graph['series'], $matches);

  foreach ( $matches[0] as $key => $value ) {
    if ( preg_match("/(DEF:\')(.*)(\'=\')(.*)\/(.*)\/(.*)\/(.*)(\.rrd)/", $value, $out ) ) {
      $ds_name = $out[2];
      $cluster_name = $out[5];
      $host_name = $out[6];
      $metric_name = $out[7];
      $output_array[] = array( "ds_name"      => $ds_name, 
                               "cluster_name" => $out[5], 
                               "host_name"    => $out[6], 
                               "metric_name"  => $out[7] );
      $rrdtool_graph_args .= $value . " " . "XPORT:" . $ds_name . ":" . $metric_name . " ";
    }
  }

  // This command will export values for the specified format in XML
  $command = $conf['rrdtool'] . " xport --start " . $rrdtool_graph['start'] . " --end " .  $rrdtool_graph['end'] . " " . $rrdtool_graph_args;

  // Read in the XML
  $fp = popen($command,"r"); 
  $string = "";
  while (!feof($fp)) { 
    $buffer = fgets($fp, 4096);
    $string .= $buffer;
  }
  // Parse it
  $xml = simplexml_load_string($string);

  # If there are multiple metrics columns will be > 1
  $num_of_metrics = $xml->meta->columns;

  // 
  $metric_values = array();
  // Build the metric_values array

  foreach ( $xml->data->row as $key => $objects ) {
    $values = get_object_vars($objects);
    // If $values["v"] is an array we have multiple data sources/metrics and we 
    // need to iterate over those
    if ( is_array($values["v"]) ) {
      foreach ( $values["v"] as $key => $value ) {
        $output_array[$key]["metrics"][] = array( "timestamp" => intval($values['t']), "value" => floatval($value));
      }
    } else {
      $output_array[0]["metrics"][] = array( "timestamp" => intval($values['t']), "value" => floatval($values['v']));
    }

  }

  // If JSON output request simple encode the array as JSON
  if ( $user['json_output'] ) {

    header("Content-type: application/json");
    header("Content-Disposition: inline; filename=\"ganglia-metrics.json\"");
    print json_encode($output_array);

  }

  // If Flot output massage the data JSON
  if ( $user['flot_output'] ) {

    foreach ( $output_array as $key => $metric_array ) {
      foreach ( $metric_array['metrics'] as $key => $values ) {
    $data_array[] = array ( $values['timestamp'] * 1000,  $values['value']);  
      }

      $flot_array[] = array( 'label' =>  strip_domainname($metric_array['host_name']) . " " . $metric_array['metric_name'], 
      'data' => $data_array);

      unset($data_array);

    }

    header("Content-type: application/json");
    print json_encode($flot_array);

  }

  if ( $user['csv_output'] ) {

    header("Content-Type: application/csv");
    header("Content-Disposition: inline; filename=\"ganglia-metrics.csv\"");

    print "Timestamp";

    // Print out headers
    for ( $i = 0 ; $i < sizeof($output_array) ; $i++ ) {
      print "," . $output_array[$i]["metric_name"];
    }

    print "\n";

    foreach ( $output_array[0]["metrics"] as $key => $row ) {
      print date("c", $row["timestamp"]);
      for ( $j = 0 ; $j < $num_of_metrics ; $j++ ) {
        print "," .$output_array[$j]["metrics"][$key]["value"];
      }
      print "\n";
    }

  }

  exit(1);
}

//////////////////////////////////////////////////////////////////////////////
// Check whether user wants to overlay events on graphs
//////////////////////////////////////////////////////////////////////////////
if ( $conf['overlay_events'] && $conf['graph_engine'] == "rrdtool" ) {

  $events_json = file_get_contents($conf['overlay_events_file']);
  $events_array = json_decode($events_json, TRUE);

//  error_log('Events file='.$conf['overlay_events_file']);
//  error_log('Events='.$events_json);
//  error_log('Events='.print_r($events_array,TRUE));
//  error_log(print_r($rrdtool_graph,TRUE));

  if (!empty($events_array)) {
    
    $color_count = sizeof($conf['graph_colors']);
    $counter = 0;

    // In order not too pollute the command line with all the possible VRULEs
    // we need to find the time range for the graph
    if ( $rrdtool_graph['end'] == "-N" or $rrdtool_graph['end'] == "N")
      $end = time();
    else if ( is_numeric($rrdtool_graph['end']) )
      $end = $rrdtool_graph['end'];

    if ( preg_match("/\-([0-9]*)(s)/", $rrdtool_graph['start'] , $out ) ) {
      $start = time() - $out[1];
    } else if ( is_numeric($rrdtool_graph['start']) )
      $start = $rrdtool_graph['start'];
    else
      // If it's not 
      $start = time() - 157680000;

    // Preserve original rrdtool command. That's the one we'll run regex checks
    // against
    $original_command = $command;

    foreach ($events_array as $key => $row) {
      $timestamp[$key]  = $row['start_time'];
    }

    // Sort events in reverse chronological order
    array_multisort($timestamp, SORT_DESC, $events_array);

    // Default to dashed line unless events_line_type is set to solid
    if ( $conf['overlay_events_line_type'] == "solid" )
      $overlay_events_line_type = "";
    else
      $overlay_events_line_type = ":dashes";

    // Loop through all the events
    foreach ( $events_array as $id => $event) {

      $timestamp = $event['start_time'];
      // Make sure it's a number
      if ( ! is_numeric($timestamp) ) {
	continue;
      }
      unset($ts_end);
      if (array_key_exists('end_time', $event) && is_numeric($event['end_time']) ) {
        $ts_end = $event['end_time'];
      }

      // If timestamp is less than start bail out of the loop since there is nothing more to do since
      // events are sorted in reverse chronological order and these events are not gonna show up in the graph
      if ( $timestamp < $start ) {
        //error_log("Time $timestamp earlier than start [$start]");
        break;
      }

      if ( preg_match("/" . $event["host_regex"]  .  "/", $original_command)) {

        
        if ( $timestamp >= $start ) {
        
	  // Do we have the end timestamp. 
          if ( !isset($end) || ( $timestamp < $end ) || 'N' == $end ) {

	    // This is a potential vector since this gets added to the command line_width
	    // TODO: Look over sanitize
            $summary = isset($event['summary']) ? sanitize($event['summary']) : "";
            $color_index = $counter % $color_count;
  
            if (isset($ts_end)) {
              # Attempt to draw a shaded area between start and end points.
              $color = $conf['graph_colors'][$color_index] . $conf['overlay_events_tick_alpha'];

              # Force solid line for ranges
              $overlay_events_line_type = "";
             
              $start_vrule = " VRULE:" . $timestamp 
                        . "#$color"
                        . ":\"" . $summary . "\"" . $overlay_events_line_type;
                        
              $end_vrule = " VRULE:" . $ts_end
                        . "#$color"
                        . ':""' . $overlay_events_line_type;

              # We need a dummpy DEF statement, because RRDtool is too stupid
              # to plot graphs without a DEF statement.
              # We can't count on a static name, so we have to "find" one.
              if (preg_match("/DEF:['\"]?(\w+)['\"]?=/", $command, $matches)) {

                $area_cdef = " CDEF:area_$counter=$matches[1],POP,"   # stupid rrdtool limitation.
                           . "TIME,$timestamp,GT,1,UNKN,IF,TIME,$ts_end,LT,1,UNKN,IF,+";

                $area_shade = $conf['graph_colors'][$color_index] . $conf['overlay_events_shade_alpha'];
                $area = " TICK:area_$counter#$area_shade:1";

                $command .= "$area_cdef $area $start_vrule $end_vrule";

              } else {
                error_log("No DEF statements found in \$command?!");
                #error_log("No DEF statements found in: $command");
              }
              
            } else {
              $command .= " VRULE:" . $timestamp 
                        . "#" . $conf['graph_colors'][$color_index]
                        . ":\"" . $summary . "\"" . $overlay_events_line_type;
            }
            
            $counter++;
            
          } else {
            #error_log("Timestamp [$timestamp] >= [$end]");
          }
          
        } else {
          #error_log("Timestamp [$timestamp] < [$start]");
        }
        
      } // end of if ( preg_match ...
      else {
        //error_log("Doesn't match host_regex");
      }

    } // end of foreach ( $events_array ...

    unset($events_array);
  } //End check for array
}

if ($debug) {
  error_log("Final rrdtool command:  $command");
}

# Did we generate a command?   Run it.
if($command || $graphite_url) {
    /*Make sure the image is not cached*/
    header ("Expires: Mon, 26 Jul 1997 05:00:00 GMT");   // Date in the past
    header ("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT"); // always modified
    header ("Cache-Control: no-cache, must-revalidate");   // HTTP/1.1
    header ("Pragma: no-cache");                     // HTTP/1.0
    if ($debug>2) {
        header ("Content-type: text/html");
        print "<html><body>";
        
        switch ( $conf['graph_engine'] ) {
      case "flot":
          case "rrdtool":
            print htmlentities( $command );
            break;
          case "graphite":
            print $graphite_url;
            break;
        }        
        print "</body></html>";
    } else {
        header ("Content-type: image/png");
        switch ( $conf['graph_engine'] ) {  
      case "flot":
          case "rrdtool":
            passthru($command);
            break;
          case "graphite":
            echo file_get_contents($graphite_url);
            break;
        }        
    }
}

?>
