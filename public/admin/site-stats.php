<?php

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

use App\Models\User;
use nntmux\Releases;
use App\Models\UserRole;

$page = new AdminPage();
$releases = new Releases();

$page->title = 'Site Stats';

$topgrabs = User::getTopGrabbers();
$page->smarty->assign('topgrabs', $topgrabs);

$topdownloads = $releases->getTopDownloads();
$page->smarty->assign('topdownloads', $topdownloads);

$topcomments = $releases->getTopComments();
$page->smarty->assign('topcomments', $topcomments);

$recent = $releases->getRecentlyAdded();
$page->smarty->assign('recent', $recent);

$usersbymonth = User::getUsersByMonth();
$page->smarty->assign('usersbymonth', $usersbymonth);

$usersbyrole = UserRole::getUsersByRole();
$page->smarty->assign('usersbyrole', $usersbyrole);
$page->smarty->assign('totusers', 0);
$page->smarty->assign('totrusers', 0);

$page->content = $page->smarty->fetch('site-stats.tpl');
$page->render();
