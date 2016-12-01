<?php
/*
* @package    User Notify System Plugin
* @copyright  (C) 2016 RJCreations. All rights reserved.
* @license    GNU General Public License version 3 or later; see LICENSE.txt
*/

defined('_JEXEC') or die;

abstract class PlgUserNotifyHelper
{

	public static function getComposedEmail ($parms, $cfg, $article, $email=true, $updt=false)
	{
		$tnam = $email ? 'email' : 'sms';
		if ($updt) $tnam .= 'u';
		$tnam .= '_tmpl';
		$tmpl = $cfg[$tnam] ?: $parms->get($tnam, '');

		if ($uppo = strpos($tmpl, '[[update]]')) {
			if ($updt) {
				$tmpl = trim(substr($tmpl, $uppo+10));
			} else {
				$tmpl = trim(substr($tmpl, 0, $uppo));
			}
		}

		if (preg_match_all('|{{([A-Z_-]+)}}|', $tmpl, $mtchs)) {
			//var_dump($mtchs);
			foreach ($mtchs[1] as $mtch) {
				$rpl = '';
				switch ($mtch) {
					case 'TITLE':
						$rpl = $article->title;
						break;
					case 'CATEGORY':
						$rpl = self::getCatTitle($article->catid);
						break;
					case 'ITEM-LINK':
						$rpl = self::getArticleHref($article);
						$rpl = JHtml::link($rpl,$rpl);
						break;
				}
				if ($rpl) $tmpl = str_replace('{{'.$mtch.'}}', $rpl, $tmpl);
			}
		}

		return $tmpl;
	}


	public static function getRecipients ($ccfg, $email=true, $updt=false)
	{
		$db = JFactory::getDbo();
		$catid = $ccfg['cid'];
		$grps = $ccfg['grps'];

//		if ($updt) return array();

		// get all users of groups
	//	$query = $db->getQuery(true)
	//		->select('user_id')
	//		->from('#__user_usergroup_map')
	//		->where('group_id IN ('.$grps.')');
		$db->setQuery('SELECT DISTINCT user_id FROM #__user_usergroup_map WHERE group_id IN ('.$grps.')');
		$users = $db->loadColumn();		var_dump($catid,$users);
		if (!$users) return array();

		// get all user configuration settings
		$db->setQuery('SELECT * FROM #__usernotify_u');
		$ucfgs = $db->loadAssocList('uid');		var_dump($ucfgs);
		// and remove those opting out from user list
		foreach ($ucfgs as $k => $ucfg) {
			if (!$ucfg['oo_all']) {
				while (($key = array_search($k, $users)) !== false) {
					unset($users[$key]);
				}
			}
		//	$cdat = unserialize(base64_decode($ucfg['serdat']));
		//	var_dump($ucfg,$cdat);
		}

		// get the users who have subscription settings for the category
		$db->setQuery('SELECT * FROM #__usernotify_uc WHERE catid='.$catid);
		$usubs = $db->loadAssocList('uid');		var_dump($usubs);
		// and remove from the user list those whose settings for the category inhibit the notification
		foreach ($usubs as $k => $usub) {
			if (($email && !$usub['eml']) || (!$email && !$usub['sms']) || ($updt && !$usub['upd'])) {
				while (($key = array_search($k, $users)) !== false) {
					unset($users[$key]);
				}
			}
		}

		// combine everything into a recipient list
		$recips = array();
		foreach ($users as $user) {
			// get the user email
			$u = JFactory::getUser($user);
			$uem = $u->get('email');
			if (isset($ucfgs[$user])) {
				$recips[] = array_merge($ucfgs[$user], array('email'=>$uem));
			} else {
				$recips[] = array('email'=>$uem, 'alt_email'=>'', 'oo_email'=>'1', 'sms_ok'=>'0');
			}
		}

		var_dump($users,$recips);
		return $recips;
	}


	public static function getCatTitle ($id)
	{
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('title'))
			->from($db->quoteName('#__categories'))
			->where($db->quoteName('id') . ' = ' . $id);
		$db->setQuery($query);
		return $db->loadResult();
	}


	public static function getArticleHref ($article)
	{
/*
		require_once(JPATH_SITE.'/components/com_content/helpers/route.php');
		if (JPATH_BASE == JPATH_ADMINISTRATOR) {
			// In the back end we need to set the application to the site app instead
			JFactory::$application = JApplication::getInstance('site');
		}
		//$articleRoute = JRoute::_(ContentHelperRoute::getArticleRoute($article->id, $article->catid));
		$articleRoute = ContentHelperRoute::getArticleRoute($article->id, $article->catid);
		$articleRoute = substr($articleRoute, strpos($articleRoute, '/administrator/')+15);
		if (JPATH_BASE == JPATH_ADMINISTRATOR) {
			JFactory::$application = JApplication::getInstance('administrator');
		}
		return JUri::root().$articleRoute;
*/
		$live_site = substr(JURI::root(), 0, -1);
		$app = JApplication::getInstance('site');
		$router = $app->getRouter();
		$url = $router->build($live_site.'/index.php?option=com_content&view=article&id=' . $article->id );
		$url = $url->toString();
		$rpl = $live_site.JURI::root(true);
		$url = str_replace($rpl, $live_site, $url);
		if (JDEBUG) { JLog::add("live site: {$live_site}, rpl: {$rpl}, URL: {$url}", JLog::INFO, 'com_usernotify'); }
		$eventLink= str_replace($live_site .'/administrator'.JURI::root(true), $live_site, $url);
		return $eventLink;
	}

}