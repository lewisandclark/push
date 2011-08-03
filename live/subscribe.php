<?php

require_once($LIVE_URL['DIR'].'/livewhale.php');
include_once($_LW->INCLUDES_DIR_PATH . '/client/modules/push/includes/class.livewhalepush.php');
$push = new LiveWhalePush();
$args = array_merge($_LW->_POST, $_LW->_GET);
$push->subscribe($args);

?>