<?php
/***************************************************************************
 *  You can find the license in the docs directory
 *
 *  Unicode Reminder メモ
 ***************************************************************************/

	require('./lib2/web.inc.php');
	require_once('./lib2/logic/cache.class.php');
	require_once('./lib2/logic/user.class.php');
	require_once('./lib2/logic/attribute.class.php');

	/* because the map does access some private info like
	 * ignored caches, we need to verify the login data
	 */
	$login->verify();

	// check for peculiar browsers
	$msie = preg_match('/MSIE [1-9]+.[0-9]+/',$_SERVER['HTTP_USER_AGENT']) &&
	                   !strpos($user_agent,"Opera");
	$old_msie = $msie && preg_match('/MSIE [1-6].[0-9]+/',$_SERVER['HTTP_USER_AGENT']);

	$sMode = isset($_REQUEST['mode']) ? $_REQUEST['mode'] : '';
	if ($sMode == 'locate')
	{
		$nLat = isset($_REQUEST['lat']) ? $_REQUEST['lat']+0 : 0;
		$nLon = isset($_REQUEST['lon']) ? $_REQUEST['lon']+0 : 0;
		$nDistance = isset($_REQUEST['distance']) ? $_REQUEST['distance']+0 : 5;
		if ($nDistance > 150) $nDistance = 150;

		cache_locate($nLat, $nLon, $nDistance);
	}
	else if ($sMode == 'wpsearch')
	{
		$sWaypoint = isset($_REQUEST['wp']) ? $_REQUEST['wp'] : '';
		output_cachexml($sWaypoint);
	}
	else if ($sMode == 'namesearch')
	{
	  $sName = isset($_REQUEST['name']) ? $_REQUEST['name'] : '';
		$nLat = isset($_REQUEST['lat']) ? $_REQUEST['lat']+0 : 0;
		$nLon = isset($_REQUEST['lon']) ? $_REQUEST['lon']+0 : 0;
		$nResultId = isset($_REQUEST['resultid']) ? $_REQUEST['resultid']+0 : 0;
    output_namesearch($sName, $nLat, $nLon, $nResultId);
	}
	else if ($sMode == 'searchresult')
	{
		$nResultId = isset($_REQUEST['resultid']) ? $_REQUEST['resultid']+0 : 0;
		$compact = isset($_REQUEST['compact']) && $_REQUEST['compact'];
		$nLon1 = isset($_REQUEST['lon1']) ? $_REQUEST['lon1']+0 : 0;
		$nLon2 = isset($_REQUEST['lon2']) ? $_REQUEST['lon2']+0 : 0;
		$nLat1 = isset($_REQUEST['lat1']) ? $_REQUEST['lat1']+0 : 0;
		$nLat2 = isset($_REQUEST['lat2']) ? $_REQUEST['lat2']+0 : 0;
		$cachenames = isset($_REQUEST['cachenames']) ? $_REQUEST['cachenames']+0 : 0;
		$smallmap = isset($_REQUEST['smallmap']) ? $_REQUEST['smallmap']+0 : 0;
		
		output_searchresult($nResultId, $compact, $nLon1, $nLon2, $nLat1, $nLat2,
		                    $cachenames, $smallmap);
	}
	else if ($sMode == 'fullscreen' ||
	         ($sMode == '' &&
            sql_value("SELECT option_value FROM user_options
                       INNER JOIN user ON user_options.user_id=user.user_id
                       WHERE option_id=6 AND user.user_id='&1'", true, $login->userid)))
	{
		$fullscreen = true;
		$tpl->popup = true;        // disables page header and -framefrapage
		$tpl->popupmargin = false;
	}
	else
	{
		$fullscreen = false;
	}

	// save options
	if (isset($_REQUEST['submit']) && $_REQUEST['submit'] && $login->userid > 0)
	{
		sql("INSERT INTO `user_options` (`user_id`, `option_id`, `option_visible`, `option_value`)
		          VALUES ('&1', '&2', '&3', '&4')
							ON DUPLICATE KEY UPDATE `option_value`='&4'",
							$login->userid, 6, 0, min(max($_REQUEST['opt_menumap']+0, 0), 1) );
		sql("INSERT INTO `user_options` (`user_id`, `option_id`, `option_visible`, `option_value`)
		          VALUES ('&1', '&2', '&3', '&4')
							ON DUPLICATE KEY UPDATE `option_value`='&4'",
							$login->userid, 7, 0, isset($_REQUEST['opt_overview']) ? min(max(round($_REQUEST['opt_overview']+0), 0), 1) : 0);
		sql("INSERT INTO `user_options` (`user_id`, `option_id`, `option_visible`, `option_value`)
		          VALUES ('&1', '&2', '&3', '&4')
							ON DUPLICATE KEY UPDATE `option_value`='&4'",
							$login->userid, 8, 0, $_REQUEST['opt_maxcaches'] == 0 ? 0 : min(max(round($_REQUEST['opt_maxcaches']+0), $opt['map']['min_maxrecords']), $opt['map']['max_maxrecords']) );
		sql("INSERT INTO `user_options` (`user_id`, `option_id`, `option_visible`, `option_value`)
		          VALUES ('&1', '&2', '&3', '&4')
							ON DUPLICATE KEY UPDATE `option_value`='&4'",
							$login->userid, 9, 0, min(max($_REQUEST['opt_cacheicons']+0, 1), 2) );
		sql("INSERT INTO `user_options` (`user_id`, `option_id`, `option_visible`, `option_value`)
		          VALUES ('&1', '&2', '&3', '&4')
							ON DUPLICATE KEY UPDATE `option_value`='&4'",
							$login->userid, 10, 0, min(max($_REQUEST['opt_pictures']+0, 0), 50) );
	}

	$tpl->name = 'map2';
	$tpl->menuitem = MNU_MAP;
	$tpl->nowpsearch = true;

	// get the correct mapkey
	$sHost = strtolower($_SERVER['HTTP_HOST']);
	if (isset($opt['lib']['google']['mapkey'][$sHost]))
		$sGMKey = $opt['lib']['google']['mapkey'][$sHost];
	else
		$tpl->error($translate->t('There is no google maps key registered for this domain.', '', '', 0));

  $tpl->add_header_javascript('http://maps.googleapis.com/maps/api/js?sensor=false&key=' . urlencode($sGMKey));
  	// https is supported by google, but may make problems in some environments,
  	// e.g. does not work with MSIE 7 on WinXP
  $tpl->add_body_load('mapLoad()');
  $tpl->add_body_unload('mapUnload()');

	// process start params
	$bGMInitCookiePos = true;
	if (isset($_REQUEST['lat']) &&
	    isset($_REQUEST['lon']) && 
	    isset($_REQUEST['zoom']))
	{
		$nGMInitLat = $_REQUEST['lat']+0;
		$nGMInitLon = $_REQUEST['lon']+0;
		$nGMInitZoom = $_REQUEST['zoom']+0;
		$bGMInitCookiePos = false;
	}
	else
	{
		// init GM from user country selection
		$rsCoords = sql("SELECT `gmLat`, `gmLon`, `gmZoom` FROM `countries_options` WHERE `country`='&1'", $login->getUserCountry());
		$rCoord = sql_fetch_assoc($rsCoords);
		sql_free_result($rsCoords);
		
		if ($rCoord !== false)
		{
			$nGMInitLat = $rCoord['gmLat'];
			$nGMInitLon = $rCoord['gmLon'];
			$nGMInitZoom = $rCoord['gmZoom'];
		}
		else
		{
			// europe is the last fallback
			$nGMInitLat = 52.74959372674114;
			$nGMInitLon = 10.01953125;
			$nGMInitZoom = 4;
		}
	}

	$sGMInitWaypoint = isset($_REQUEST['wp']) ? $_REQUEST['wp'] : '';
	if ($sGMInitWaypoint != '')
	{
		$cache = cache::fromWP($sGMInitWaypoint);
		if ($cache == null)
			$sGMInitWaypoint = '';
		else
		{
			if ($cache->allowView())
			{
				$nGMInitLon = $cache->getLongitude();
				$nGMInitLat = $cache->getLatitude();
				$nGMInitZoom = -1;
				$bGMInitCookiePos = false;
			}
			else
				$sGMInitWaypoint = '';
		}
	}
	
	$nUserLon = 0;
	$nUserLat = 0;
	if ($login->userid != 0 )
	{
		$user = new user($login->userid);
		$nUserLat = $user->getLatitude();
		$nUserLon = $user->getLongitude();
		$tpl->assign('username',$user->getUsername());
	}
	else
		$tpl->assign('username',"");
	$tpl->assign('nUserLon', $nUserLon);
	$tpl->assign('nUserLat', $nUserLat);
	
	$tpl->assign('nGMInitLon', $nGMInitLon);
	$tpl->assign('nGMInitLat', $nGMInitLat);
	$tpl->assign('nGMInitZoom', $nGMInitZoom);
	$tpl->assign('bGMInitCookiePos', ($bGMInitCookiePos ? 1 : 0));
	$tpl->assign('sGMInitWaypoint', $sGMInitWaypoint);
	$tpl->assign('bFullscreen', $fullscreen ? 1 : 0);

	$rsCacheType = sql("SELECT `cache_type`.`id`, IFNULL(`sys_trans_text`.`text`, `cache_type`.`short2`) AS `text` FROM `cache_type` LEFT JOIN `sys_trans_text` ON `cache_type`.`short2_trans_id`=`sys_trans_text`.`trans_id` AND `sys_trans_text`.`lang`='&1' ORDER BY `cache_type`.`ordinal` ASC", $opt['template']['locale']);
	$tpl->assign_rs('aCacheType', $rsCacheType);
	sql_free_result($rsCacheType);

	$rsCacheSize = sql("SELECT `cache_size`.`id`, IFNULL(`sys_trans_text`.`text`, `cache_size`.`name`) AS `text` FROM `cache_size` LEFT JOIN `sys_trans_text` ON `cache_size`.`trans_id`=`sys_trans_text`.`trans_id` AND `sys_trans_text`.`lang`='&1' ORDER BY `cache_size`.`ordinal` ASC", $opt['template']['locale']);
	$tpl->assign_rs('aCacheSize', $rsCacheSize);
	sql_free_result($rsCacheSize);

	/* assign attributes */
	$tpl->assign('aAttributes', attribute::getSelectableAttrbutesListArray());

	$aAttributesDisabled = array();
	$maxaid = 0;
	$rs = sql("SELECT `id` FROM `cache_attrib`");
	while ($r = sql_fetch_assoc($rs))
	{
		$aAttributesDisabled[] = $r['id'];
		if ($r['id'] > $maxaid) $maxaid = $r['id'];
	}
	sql_free_result($rs);
	$tpl->assign('aAttributesDisabled', $aAttributesDisabled);
	$tpl->assign('maxAttributeId', $maxaid);

	// options
	$rs = sql("SELECT `profile_options`.`id`,
                    IF(`option_value` IS NULL, `default_value`, `option_value`) AS `value`
	             FROM `profile_options`
          LEFT JOIN `user_options` ON `profile_options`.`id`=`user_options`.`option_id`  AND `user_id`='&1' 
              WHERE `optionset`=2",
						$login->userid);
	while ($r = sql_fetch_assoc($rs))
		switch ($r['id'])
		{
			case  6: $tpl->assign('opt_menumap',    $r['value']); break;
			case  7: $tpl->assign('opt_overview',   $r['value']); break;
			case  8: $tpl->assign('opt_maxcaches',  $r['value']); break;
			case  9: $tpl->assign('opt_cacheicons', $r['value']); break;
			case 10: $tpl->assign('opt_pictures',   $r['value']); break;
		}
	sql_free_result($rs);

	$tpl->assign('maxrecords',$opt['map']['maxrecords'] + 0);
	$tpl->assign('min_maxrecords', $opt['map']['min_maxrecords']);
	$tpl->assign('max_maxrecords', $opt['map']['max_maxrecords']);

	$tpl->assign('msie',$msie);
	$tpl->assign('old_msie',$old_msie);

	$tpl->assign('help_oconly', helppagelink("OConly"));
	$tpl->assign('help_map', helppagelink("*map2"));
	$tpl->assign('help_wps', helppagelink("additional_waypoints"));
	$tpl->assign('help_note', helppagelink("usernote"));
	$tpl->assign('help_previewpics', helppagelink("previewpics"));

	$tpl->display();

function cache_locate($nLat, $nLon, $nDistance)
{
	global $login;

	$rsCache = sql_slave("SELECT " . geomath::getSqlDistanceFormula($nLon, $nLat, $nDistance) . " AS `distance`, 
	                              `caches`.`wp_oc`
	                         FROM `caches` 
	                   INNER JOIN `cache_status` ON `caches`.`status`=`cache_status`.`id`
	                        WHERE `caches`.`latitude`>'&1' AND 
      										      `caches`.`latitude`<'&2' AND 
										            `caches`.`longitude`>'&3' AND 
										            `caches`.`longitude`<'&4' AND
										            (`cache_status`.`allow_user_view`=1 OR `caches`.`user_id`='&1')
					             ORDER BY `distance` ASC LIMIT 1",
										            geomath::getMinLat($nLon, $nLat, $nDistance),
										            geomath::getMaxLat($nLon, $nLat, $nDistance),
										            geomath::getMinLon($nLon, $nLat, $nDistance),
										            geomath::getMaxLon($nLon, $nLat, $nDistance),
										            $login->userid);
	$rCache = sql_fetch_assoc($rsCache);
	sql_free_result($rsCache);

	if ($rCache === false)
	{
		echo '<caches></caches>';
		exit;
	}

	output_cachexml($rCache['wp_oc']);
}

function output_cachexml($sWaypoint)
{
	global $opt, $login;

	$rsCache = sql_slave("SELECT `caches`.`cache_id`, `caches`.`name`, `caches`.`wp_oc`, `caches`.`cache_id`, `caches`.`type`,
	                             `caches`.`longitude`, `caches`.`latitude`, 
	                             IF(`caches`.`status` IN (2,3,6), 1, 0) AS `tna`, 
	                             IFNULL(`trans_status_text`.`text`, `cache_status`.`name`) AS `statustext`,
	                             IFNULL(`trans_type_text`.`text`, `cache_type`.`name`) AS `type_text`, `cache_type`.`id` AS `type_id`, 
	                             IFNULL(`trans_size_text`.`text`, `cache_size`.`name`) AS `size`, 
	                             `caches`.`difficulty`, `caches`.`terrain`, 
	                             `caches`.`date_created`, `caches`.`is_publishdate`,
	                             IFNULL(`stat_caches`.`toprating`, 0) AS `toprating`, 
	                             IF(`caches`.`user_id`='&1', 1, 0) AS `owner`, 
	                             `user`.`username`, `user`.`user_id`,
	                             IF(`caches_attributes`.`attrib_id` IS NULL, 0, 1) AS `oconly`,
	                             IFNULL(`pictures`.`url`,'') AS `picurl`,
	                             IFNULL(`pictures`.`title`,'') AS `pictitle`
	                        FROM `caches` 
	                  INNER JOIN `cache_type` ON `caches`.`type`=`cache_type`.`id` 
	                  INNER JOIN `cache_status` ON `caches`.`status`=`cache_status`.`id` 
	                  INNER JOIN `user` ON `caches`.`user_id`=`user`.`user_id` 
	                  INNER JOIN `cache_size` ON `caches`.`size`=`cache_size`.`id` 
	                   LEFT JOIN `stat_caches` ON `caches`.`cache_id`=`stat_caches`.`cache_id` 
	                   LEFT JOIN `sys_trans` AS `trans_status` ON `cache_status`.`trans_id`=`trans_status`.`id` AND `cache_status`.`name`=`trans_status`.`text`
	                   LEFT JOIN `sys_trans_text` AS `trans_status_text` ON `trans_status`.`id`=`trans_status_text`.`trans_id` AND `trans_status_text`.`lang`='&2'
	                   LEFT JOIN `sys_trans` AS `trans_type` ON `cache_type`.`trans_id`=`trans_type`.`id` AND `cache_type`.`name`=`trans_type`.`text`
	                   LEFT JOIN `sys_trans_text` AS `trans_type_text` ON `trans_type`.`id`=`trans_type_text`.`trans_id` AND `trans_type_text`.`lang`='&2'
	                   LEFT JOIN `sys_trans` AS `trans_size` ON `cache_size`.`trans_id`=`trans_size`.`id` AND `cache_size`.`name`=`trans_size`.`text`
	                   LEFT JOIN `sys_trans_text` AS `trans_size_text` ON `trans_size`.`id`=`trans_size_text`.`trans_id` AND `trans_size_text`.`lang`='&2'
	                   LEFT JOIN `caches_attributes` ON `caches_attributes`.`cache_id`=`caches`.`cache_id` AND `caches_attributes`.`attrib_id`=6
	                   LEFT JOIN `pictures` ON `pictures`.`object_id`=`caches`.`cache_id` AND `pictures`.`object_type`='&4' AND `pictures`.`mappreview`=1
	                       WHERE (`caches`.`wp_oc`='&3' OR (`caches`.`wp_oc`!='&3' AND `caches`.`wp_gc`='&3') OR (`caches`.`wp_oc`!='&3' AND `caches`.`wp_nc`='&3')) AND 
	                             (`cache_status`.`allow_user_view`=1 OR `caches`.`user_id`='&1')",
	                              $login->userid, $opt['template']['locale'], $sWaypoint, OBJECT_CACHE);

	$rCache = sql_fetch_assoc($rsCache);
	sql_free_result($rsCache);

	if ($rCache === false)
	{
		echo '<caches></caches>';
		exit;
	}

	$nGeokretyCount = sql_value_slave("SELECT COUNT(*) FROM `gk_item_waypoint` WHERE `wp`='&1'", 0, $sWaypoint);
	$nNotFoundCount = $nAttendedCount = 0;
	$nFoundCount = sql_value_slave("SELECT COUNT(*) FROM `cache_logs` WHERE `user_id`='&1' AND `cache_id`='&2' AND `type`=1", 0, $login->userid, $rCache['cache_id']);
	if ($nFoundCount == 0)
		$nNotFoundCount = sql_value_slave("SELECT COUNT(*) FROM `cache_logs` WHERE `user_id`='&1' AND `cache_id`='&2' AND `type`=2", 0, $login->userid, $rCache['cache_id']);
	if ($rCache['type'] == 6)
		$nAttendedCount = sql_value_slave("SELECT COUNT(*) FROM `cache_logs` WHERE `user_id`='&1' AND `cache_id`='&2' AND `type`=7", 0, $login->userid, $rCache['cache_id']);

  $wphandler = new ChildWp_Handler();
  $waypoints = $wphandler->getChildWps($rCache['cache_id'],true);

	echo '<caches>' . "\n";
	echo '  <cache ';
	echo 'name="' . xmlentities($rCache['name']) . '" ';
	echo 'wpoc="' . xmlentities($rCache['wp_oc']) . '" ';
	echo 'coords="' . $rCache['longitude'] . ',' . $rCache['latitude'] . '" ';
	echo 'status_tna="' . xmlentities($rCache['tna']) . '" ';
	echo 'status_text="' . xmlentities($rCache['statustext']) . '" ';
	echo 'type_id="' . xmlentities($rCache['type_id']) . '" ';
	echo 'type_text="' . xmlentities($rCache['type_text']) . '" ';
	echo 'size="' . xmlentities($rCache['size']) . '" ';
	echo 'difficulty="' . xmlentities($rCache['difficulty']/2) . '" ';
	echo 'terrain="' . xmlentities($rCache['terrain']/2) . '" ';
	echo 'listed_since="' . xmlentities(strftime($opt['locale'][$opt['template']['locale']]['format']['date'], strtotime($rCache['date_created']))) . '" ';
	echo 'is_publishdate="' . xmlentities($rCache['is_publishdate']) . '" ';
	echo 'toprating="' . xmlentities($rCache['toprating']) . '" ';
	echo 'geokreties="' . xmlentities($nGeokretyCount) . '" ';
	echo 'found="' . xmlentities(($nFoundCount>0) ? 1 : 0) . '" ';
	echo 'notfound="' . xmlentities(($nNotFoundCount>0) ? 1 : 0) . '" ';
	echo 'attended="' . xmlentities(($nAttendedCount>0) ? 1 : 0) . '" ';
	echo 'oconly="' . xmlentities($rCache['oconly']) . '" ';
	echo 'owner="' . xmlentities($rCache['owner']) . '" ';
	echo 'username="' . xmlentities($rCache['username']) . '" ';
	echo 'userid="' . xmlentities($rCache['user_id']) . '" ';
	echo 'picurl="' . xmlentities($rCache['picurl']) . '" ';
	echo 'pictitle="' . xmlentities(trim($rCache['pictitle'])) . '" >\n';

	foreach ($waypoints as $waypoint)
	{
		echo '    <wpt ';
		echo 'typeid="' . xmlentities($waypoint['type']) . '" ';
		echo 'typename="' . xmlentities($waypoint['name']) . '" ';
		echo 'image="' . xmlentities($waypoint['image']) . '" ';
		echo 'imagewidth="38" imageheight="38" ';
		echo 'latitude="' . xmlentities($waypoint['latitude']) . '" ';
		echo 'longitude="' . xmlentities($waypoint['longitude']) . '" ';
		echo 'description="' . xmlentities(mb_ereg_replace('\r\n','<br />',$waypoint['description'])) . '" />\n';
	}

	echo '  </cache>\n';

	echo '</caches>';

	exit;
}

function output_namesearch($sName, $nLat, $nLon, $nResultId)
{
	global $login;

  $sName = '%' . $sName . '%';

  echo '<caches>' . "\n";
  $rs = sql_slave("SELECT " . geomath::getSqlDistanceFormula($nLon, $nLat, 0) . " AS `distance`, 
                          `caches`.`name`, `caches`.`wp_oc` 
                     FROM `map2_data` 
               INNER JOIN `caches` ON `map2_data`.`cache_id`=`caches`.`cache_id`
               INNER JOIN `cache_status` ON `caches`.`status`=`cache_status`.`id`
                    WHERE `caches`.`name` LIKE '&1' 
                      AND (`cache_status`.`allow_user_view`=1 OR `caches`.`user_id`='&3')
                      AND `map2_data`.`result_id`='&2'
					       ORDER BY `distance` ASC LIMIT 30",
					                $sName,
					                $nResultId,
					                $login->userid);
  while ($r = sql_fetch_assoc($rs))
  {
    echo '<cache name="' . xmlentities($r['name']) . '" wpoc="' . xmlentities($r['wp_oc']) . '" />' . "\n";
  }
  sql_free_result($rs);
  echo '</caches>';
  exit;
}

function output_searchresult($nResultId, $compact, $nLon1, $nLon2, $nLat1, $nLat2,
                             $cachenames, $smallmap)
{
	global $login, $opt, $maxrecords, $msie;

	// check if data is available and connect the right slave server
	$nSlaveId = sql_value("SELECT `slave_id` FROM `map2_result` WHERE `result_id`='&1' AND DATE_ADD(`date_created`, INTERVAL '&2' SECOND)>NOW()", -2, $nResultId, $opt['map']['maxcacheage']);
	if ($nSlaveId == -2)
	{
		echo '<searchresult count="0" available="0">';
		echo '</searchresult>';
		exit;
	}
	sql_connect_slave($nSlaveId);

	sql("UPDATE `map2_result` SET `request_counter`=`request_counter`+1, `date_lastqueried`=NOW() WHERE `result_id`='&1'", $nResultId);

	// execute query and return search result
	$nRecordCount = sql_value_slave("SELECT COUNT(*) FROM `map2_data` INNER JOIN `caches` ON `map2_data`.`cache_id`=`caches`.`cache_id` WHERE `map2_data`.`result_id`='&1' AND `caches`.`longitude`>'&2' AND `caches`.`longitude`<'&3' AND `caches`.`latitude`>'&4' AND `caches`.`latitude`<'&5'", 0, $nResultId, $nLon1, $nLon2, $nLat1, $nLat2);
	// TODO: SQL_CALC_FOUND_ROWS + $nRecordCount = sql_value_slave("SELECT FOUND_ROWS()", 0);

	// determine max. number of records to send
	$maxrecords = $opt['map']['maxrecords'] + 0;
	if ($login->userid > 0)
	{
		$user_maxrecords =
			sql_value("SELECT option_value FROM user_options WHERE user_id='&1' AND option_id=8",
	               $opt['map']['maxrecords'] + 0, $login->userid);
		if ($user_maxrecords > 0 && (!$msie || $user_maxrecords < $maxrecords))
			$maxrecords = min(max($user_maxrecords, $opt['map']['min_maxrecords']), 
			                  $opt['map']['max_maxrecords']);
	}
	else
		$user_maxrecords = 0;

	if ($smallmap && $user_maxrecords == 0 && $maxrecords > 1000)
		$maxrecords = floor($maxrecords*0.65);

	$bMaxRecordReached = ($nRecordCount > $maxrecords);

	// output data
	echo '<searchresult count="' . xmlentities($nRecordCount) . '" available="1"' .
	     ' maxrecordreached="' .	($bMaxRecordReached ? '1' : '0') . '">' . "\n";

	if (!$bMaxRecordReached)
	{
		$namequery = ($cachenames ? ", `caches`.`name` AS `cachename`" : "");
		$rs = sql_slave("SELECT SQL_BUFFER_RESULT 
                            `caches`.`wp_oc`, `caches`.`longitude`, `caches`.`latitude`,
                            `caches`.`type`, 
                            `caches`.`status`>1 AS `inactive`,
                            `user`.`user_id`='&6' AS `owned`,
                            IF(`found_logs`.`id` IS NULL, 0, 1) AS `found`,
                            IF(`found_logs`.`id` IS NULL AND `notfound_logs`.`id` IS NOT NULL, 1, 0) AS `notfound`,
                            IF(`caches_attributes`.`attrib_id` IS NULL, 0, 1) AS `oconly`" .
                            $namequery . "
                       FROM `map2_data`
                 INNER JOIN `caches` ON `map2_data`.`cache_id`=`caches`.`cache_id`
                  LEFT JOIN `user` ON `user`.`user_id`=`caches`.`user_id`
                  LEFT JOIN `cache_logs` `found_logs` ON `found_logs`.`cache_id`=`caches`.`cache_id` AND `found_logs`.`user_id`='&6' AND `found_logs`.`type` IN (1,7)
                  LEFT JOIN `cache_logs` `notfound_logs` ON `notfound_logs`.`cache_id`=`caches`.`cache_id` AND `notfound_logs`.`user_id`='&6' AND `notfound_logs`.`type`=2
                  LEFT JOIN `caches_attributes` ON `caches_attributes`.`cache_id`=`caches`.`cache_id` AND `caches_attributes`.`attrib_id`=6
                      WHERE `map2_data`.`result_id`='&1' AND `caches`.`longitude`>'&2' AND `caches`.`longitude`<'&3' AND `caches`.`latitude`>'&4' AND `caches`.`latitude`<'&5'
									 ORDER BY `caches`.`status` DESC, `oconly` AND NOT (`found` OR `notfound`), NOT (`found` OR `notfound`), `caches`.`type`<>4, MD5(`caches`.`name`)
									 LIMIT &7",
									    // sort in reverse order, because last are on top of map;
									    // fixed order avoids oscillations when panning;
									    // MD5 pseudo-randomness gives equal changes for all kinds of caches to be on top
									    $nResultId, $nLon1, $nLon2, $nLat1, $nLat2, $login->userid, $maxrecords);

		while ($r = sql_fetch_assoc($rs))
		{
			$flags = 0; 
			if ($r['owned']) $flags |= 1;
			if ($r['found']) $flags |= 2;
			if ($r['notfound']) $flags |= 4;
			if ($r['inactive']) $flags |= 8;
			if ($r['oconly']) $flags |= 16;

			if ($compact)
				echo '<c d="' . xmlentities($r['wp_oc']) . '/' .
							xmlentities(round($r['longitude'],5)) . '/' . 
							xmlentities(round($r['latitude'],5)) . '/' .
							xmlentities($r['type']) . '/' . xmlentities($flags) . '"' .
							(isset($r['cachename']) ? ' n="' . xmlentities($r['cachename']) . '"' : '') . 
							' />';
			else
				echo '<cache wp="' . xmlentities($r['wp_oc']) . '"' .
							' lon="' . xmlentities(round($r['longitude'],5)) . '"' . 
							' lat="' . xmlentities(round($r['latitude'],5)) . '"' . 
							' type="' . xmlentities($r['type']) . '"' .
							(isset($r['cachename']) ? ' n="' . xmlentities($r['cachename']) . '"' : '') .  
							' f="' . xmlentities($flags) . '" />' . "\n";
		}
		sql_free_result($rs);
	}
	echo '</searchresult>';

	exit;
}
?>
