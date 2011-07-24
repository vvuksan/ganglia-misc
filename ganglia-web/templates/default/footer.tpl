<!-- Begin footer.tpl -->
</form>
</div> <!-- tabs-main -->

<div id="tabs-search">
  Search term matches any number of metrics and hosts. For example type web or disk, wait a split seconds
  and drop down menu will show up with choices.
  <!-- Uses LiveSearch from http://andreaslagerkvist.com/jquery/live-search/ -->
  <div id="metric-search">
    <form method="post" action="/search/">
      <p>
	<label>
	    <small>Search as you type</small><br />
	    <input type="text" name="q" id="search-field-q" size="60" placeholder="Search as you type" on />
	</label>
      </p>
    </form>
  </div>
</div> 

<div id="create-new-view-dialog" title="Create new view">
  <div id="create-new-view-layer">
    <form id="create_view_form">
      <input type="hidden" name="create_view" value="1">
      <fieldset>
	 <label for="name">View Name</label>
	 <input type="text" name="view_name" id="view_name" class="text ui-widget-content ui-corner-all" />
         <center><button onclick="createView(); return false;">Create</button></center>
      </fieldset>
    </form>
  </div>
  <div id="create-new-view-confirmation-layer"></div>
</div>

<div id="tabs-views">
  <div id="tabs-views-content">
Loading View Please wait...<img src="img/spinner.gif" />
  </div>
</div>

<div id="tabs-autorotation">
Invoke automatic rotation system. Automatic rotation rotates all of the graphs/metrics specified in a view waiting 
30 seconds in between each. This will run as long as you have this page open.
<p>
Please select the view you want to rotate.</p>
  <div id="tabs-autorotation-chooser">
Loading view, please wait...<img src="img/spinner.gif" />
  </div>
</div>

<hr />
<div align="center">
<font size="-1" class="footer">
Ganglia Web Frontend version {$webfrontend_version}
<a href="http://ganglia.sourceforge.net/downloads.php?component=ganglia-webfrontend&amp;version={$webfrontend_version}">Check for Updates.</a><br />

Ganglia Web Backend <i>({$webbackend_component})</i> version {$webbackend_version}
<a href="http://ganglia.sourceforge.net/downloads.php?component={$webbackend_component}&amp;version={$webbackend_version}">Check for Updates.</a><br />

Downloading and parsing ganglia's XML tree took {$parsetime}.<br />
Images created with <a href="http://www.rrdtool.org/">RRDtool</a> version {$rrdtool_version}.<br />
{$dwoo.ad} {$dwoo.version}.<br />
</font>
</div>
</div> <!-- div-tabs -->
</BODY>
</HTML>
<!-- End footer.tpl -->
